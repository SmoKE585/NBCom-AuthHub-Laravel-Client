<?php

declare(strict_types=1);

namespace Nbcom\SsoAuth\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Nbcom\SsoAuth\SsoClient;
use Nbcom\SsoAuth\Support\UrlGuard;

/**
 * Fallback-вход без JS-кнопки: сразу уводит браузер на /start хаба.
 */
final class LoginController
{
    public function __invoke(Request $request, SsoClient $client): RedirectResponse
    {
        $fallback = (string) config('sso-auth.routes.redirect_after_callback', '/');
        $target = UrlGuard::sanitizeTarget($request->query('target_url'), $fallback);

        return redirect()->away($client->createLoginUrl($target));
    }
}
