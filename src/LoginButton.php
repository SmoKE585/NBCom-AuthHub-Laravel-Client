<?php

declare(strict_types=1);

namespace Nbcom\SsoAuth;

use Nbcom\SsoAuth\Support\UrlGuard;

/**
 * HTML-кнопка входа.
 *
 * Рендерит только контейнер .ssonbcom-auth-button[data-url] и подключает
 * боевой JS с хаба. Скрипт никогда не копируется в пакет: ?v=time()
 * обходит кеш браузера и nginx после каждой правки виджета.
 *
 * Использование в Blade:
 *   {!! \Nbcom\SsoAuth\LoginButton::render() !!}
 */
final class LoginButton
{
    private const SCRIPT_STATE_KEY = 'sso-auth.button.script-included';

    public function __toString(): string
    {
        return self::render();
    }

    /**
     * @param array<string, string|int|bool|null> $attributes
     */
    public static function render(
        ?string $targetUrl = null,
        array $attributes = [],
        ?string $class = null,
    ): string {
        $fallback = (string) config('sso-auth.routes.redirect_after_callback', '/');
        $current = app()->bound('request') ? request()->fullUrl() : null;
        $target = UrlGuard::sanitizeTarget($targetUrl ?? $current, $fallback);

        $url = app(SsoClient::class)->createLoginUrl($target);

        // Флаг живёт в контейнере, а не в static: Octane сбрасывает контейнер
        // между запросами, поэтому вторая страница тоже получит <script>.
        $includeScript = (bool) config('sso-auth.button.include_script', true)
            && ! app()->bound(self::SCRIPT_STATE_KEY);

        if ($includeScript) {
            app()->instance(self::SCRIPT_STATE_KEY, true);
        }

        return view('sso-auth::button', [
            'url' => $url,
            'jsUrl' => self::jsUrl(),
            'includeScript' => $includeScript,
            'buttonClass' => trim(($class ?? '') . ' ' . (string) config('sso-auth.button.class', '')),
            'attributes' => self::sanitizeAttributes($attributes),
        ])->render();
    }

    public static function jsUrl(): string
    {
        $url = (string) config('sso-auth.button.js_url', '');

        if ((bool) config('sso-auth.button.cache_bust', true)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . time();
        }

        return $url;
    }

    /**
     * @param array<string, string|int|bool|null> $attributes
     * @return array<string, string>
     */
    private static function sanitizeAttributes(array $attributes): array
    {
        $out = [];

        foreach ($attributes as $name => $value) {
            if (preg_match('/^[A-Za-z][A-Za-z0-9_:-]*$/', $name) !== 1) {
                continue;
            }

            if ($value === null || $value === false) {
                continue;
            }

            $out[$name] = $value === true ? '' : (string) $value;
        }

        return $out;
    }
}
