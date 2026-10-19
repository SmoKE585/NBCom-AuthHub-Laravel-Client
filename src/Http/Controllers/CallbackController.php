<?php

declare(strict_types=1);

namespace Nbcom\SsoAuth\Http\Controllers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Nbcom\SsoAuth\Events\SsoCallbackReceived;
use Nbcom\SsoAuth\Exceptions\SsoAuthException;
use Nbcom\SsoAuth\SsoClient;
use Nbcom\SsoAuth\Support\UrlGuard;

/**
 * Принимает подписанный ответ хаба, проверяет его и отдаёт DTO приложению.
 *
 * Авторизация не выполняется: DTO публикуется событием и (опционально)
 * кладётся в сессию. Дальше решает само приложение.
 */
final class CallbackController
{
    public function __invoke(Request $request, SsoClient $client, Dispatcher $events): RedirectResponse
    {
        try {
            $result = $client->validateCallback($request->query());
        } catch (SsoAuthException $e) {
            report($e);

            return redirect()
                ->to($this->errorRedirect())
                ->withErrors(['sso' => $e->getMessage()]);
        }

        $events->dispatch(new SsoCallbackReceived($result));

        if ((bool) config('sso-auth.session.store_callback', true)) {
            $request->session()->put(
                (string) config('sso-auth.session.callback_key', 'sso-auth.callback'),
                $result->user->toArray(),
            );
        }

        $fallback = (string) config('sso-auth.routes.redirect_after_callback', '/');

        // При делегированном входе target_url приходит в query, а не из state.
        // UrlGuard всё равно проверяет домен — открытого редиректа не будет.
        $queryTarget = $request->query('target_url');
        $candidate = $result->targetUrl
            ?? (is_string($queryTarget) ? $queryTarget : null);

        $target = UrlGuard::sanitizeTarget($candidate, $fallback);

        return redirect()->to($target ?: '/');
    }

    private function errorRedirect(): string
    {
        $configured = (string) config('sso-auth.routes.error_redirect', '');

        return $configured !== ''
            ? $configured
            : (string) config('sso-auth.routes.redirect_after_callback', '/');
    }
}
