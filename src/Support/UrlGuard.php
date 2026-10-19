<?php

declare(strict_types=1);

namespace Nbcom\SsoAuth\Support;

/**
 * Защита от open redirect на стороне клиентского сайта.
 *
 * Хаб проверяет target_url по своему allowlist, но после callback приложение
 * само делает redirect. Поэтому домен проверяется ещё раз здесь.
 */
final class UrlGuard
{
    public static function sanitizeTarget(?string $url, ?string $fallback = null): ?string
    {
        if ($url === null || trim($url) === '') {
            return $fallback;
        }

        return self::isAllowed(trim($url)) ? trim($url) : $fallback;
    }

    public static function isAllowed(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        foreach (self::allowedHosts() as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function allowedHosts(): array
    {
        $configured = (array) config('sso-auth.allowed_target_hosts', []);
        $hosts = [];

        foreach ($configured as $host) {
            if (is_string($host) && trim($host) !== '') {
                $hosts[] = strtolower(trim($host));
            }
        }

        if ($hosts !== []) {
            return $hosts;
        }

        // В первую очередь — фактический хост текущего запроса: APP_URL
        // может быть не задан или смотреть на localhost.
        $appHost = app()->bound('request') ? strtolower(request()->getHost()) : '';

        if ($appHost === '') {
            $appHost = strtolower((string) parse_url((string) config('app.url', ''), PHP_URL_HOST));
        }

        return $appHost !== '' ? [$appHost] : [];
    }
}
