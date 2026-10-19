<?php

declare(strict_types=1);

namespace Nbcom\SsoAuth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Nbcom\SsoAuth\Dto\SsoCallbackResult;
use Nbcom\SsoAuth\Dto\SsoUser;
use Nbcom\SsoAuth\Exceptions\InvalidCallbackException;
use Nbcom\SsoAuth\Exceptions\SsoAuthException;
use Nbcom\SsoAuth\Support\StateStore;

/**
 * Серверная часть протокола: сборка /start-ссылки и проверка ответа хаба.
 *
 * Сервис не трогает Auth и не пишет в БД — он только возвращает DTO.
 */
final class SsoClient
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $baseUrl,
        private readonly int $ttlSeconds,
        private readonly int $clockSkewSeconds,
        private readonly StateStore $states,
        private readonly bool $allowStatelessDelegate = false,
    ) {
    }

    public static function fromConfig(StateStore $states): self
    {
        return new self(
            clientId: (string) config('sso-auth.client_id', ''),
            clientSecret: (string) config('sso-auth.client_secret', ''),
            baseUrl: rtrim((string) config('sso-auth.base_url', ''), '/'),
            ttlSeconds: max(1, (int) config('sso-auth.ttl_seconds', Protocol::DEFAULT_TTL_SECONDS)),
            clockSkewSeconds: max(0, (int) config('sso-auth.clock_skew_seconds', Protocol::DEFAULT_CLOCK_SKEW_SECONDS)),
            states: $states,
            allowStatelessDelegate: (bool) config('sso-auth.delegate.allow_stateless_callback', true),
        );
    }

    /**
     * Собирает ссылку на /start и запоминает state в сессии.
     *
     * @param string|null $targetUrl куда вернуть пользователя после callback
     * @param string|null $returnUrl куда хаб пришлёт подписанный ответ
     */
    public function createLoginUrl(
        ?string $targetUrl = null,
        ?string $returnUrl = null,
        ?int $ttlSeconds = null,
    ): string {
        $this->assertConfigured();

        $returnUrl = $this->absoluteUrl($returnUrl ?: $this->resolveReturnUrl());
        $targetUrl = $this->absoluteUrl($targetUrl ?: $this->resolveDefaultTargetUrl());
        $ttl = max(1, $ttlSeconds ?? $this->ttlSeconds);

        $state = $this->randomUrlSafe(24);
        $nonce = $this->randomUrlSafe(24);
        $exp = time() + $ttl;

        $payload = [
            Protocol::Q_CLIENT_ID => $this->clientId,
            Protocol::Q_STATE => $state,
            'exp' => $exp,
            'nonce' => $nonce,
            Protocol::Q_RETURN_URL => $returnUrl,
            Protocol::Q_TARGET_URL => $targetUrl,
        ];

        $ticket = $this->makeTicket($payload);

        $this->states->put($state, [
            'target_url' => $targetUrl,
            'exp' => $exp,
        ]);

        $query = [
            Protocol::Q_CLIENT_ID => $this->clientId,
            Protocol::Q_TICKET => $ticket,
            Protocol::Q_STATE => $state,
            Protocol::Q_RETURN_URL => $returnUrl,
            Protocol::Q_TARGET_URL => $targetUrl,
        ];

        return $this->baseUrl . Protocol::START_PATH
            . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Проверяет подписанный ответ хаба.
     *
     * @param array<string, mixed> $query
     */
    public function validateCallback(array $query): SsoCallbackResult
    {
        $this->assertConfigured();

        $state = $this->requireString($query, Protocol::R_STATE);
        $bitrixUserId = $this->requireInt($query, Protocol::R_BITRIX_USER_ID);
        $ts = $this->requireInt($query, Protocol::R_TS);
        $sig = $this->requireString($query, Protocol::R_SIG);

        // Формат state не фиксируем: при делегировании его генерирует хаб
        // или сайт-источник. Хаб ограничивает значение 255 символами.
        if (strlen($state) > 255) {
            throw new InvalidCallbackException('Некорректный ответ авторизации.');
        }

        $record = $this->states->pull($state);

        if ($record === null && ! $this->allowStatelessDelegate) {
            throw new InvalidCallbackException('Сессия авторизации истекла. Попробуйте войти заново.');
        }

        $this->assertFresh($ts);

        // Порядок и разделители — контракт со всеми клиентами. Не менять.
        $stringToSign = sprintf(
            'client_id=%s&state=%s&bitrix_user_id=%d&ts=%d',
            $this->clientId,
            $state,
            $bitrixUserId,
            $ts,
        );

        $expectedSig = $this->b64uEncode(hash_hmac('sha256', $stringToSign, $this->clientSecret, true));

        if (! hash_equals($expectedSig, $sig)) {
            throw new InvalidCallbackException('Некорректный ответ авторизации.');
        }

        // Делегированный вход: state нет в сессии, поэтому подпись должна
        // использоваться ровно один раз. Cache::add атомарен.
        if ($record === null && ! Cache::add(
            'sso-auth:used-signature:' . hash('sha256', $sig),
            true,
            $this->ttlSeconds + $this->clockSkewSeconds,
        )) {
            throw new InvalidCallbackException('Ответ авторизации уже использован.');
        }

        $userPayload = $this->decodeUser($query[Protocol::R_USER] ?? null);
        $user = SsoUser::fromPayload($userPayload, $bitrixUserId);

        return new SsoCallbackResult(
            user: $user,
            targetUrl: is_string($record['target_url'] ?? null) ? $record['target_url'] : null,
            state: $state,
            timestamp: $ts,
        );
    }

    private function assertFresh(int $ts): void
    {
        $now = time();

        if ($ts > $now + $this->clockSkewSeconds) {
            throw new InvalidCallbackException('Некорректное время ответа авторизации.');
        }

        if ($ts < $now - $this->ttlSeconds) {
            throw new InvalidCallbackException('Ответ авторизации устарел.');
        }
    }

    private function assertConfigured(): void
    {
        if ($this->baseUrl === '' || $this->clientId === '' || $this->clientSecret === '') {
            throw new SsoAuthException(
                'SSO не настроен: заполните SSO_AUTH_BASE_URL, SSO_AUTH_CLIENT_ID и SSO_AUTH_CLIENT_SECRET.',
            );
        }
    }

    private function resolveReturnUrl(): string
    {
        $configured = trim((string) config('sso-auth.callback_url', ''));

        if ($configured !== '') {
            return $configured;
        }

        $routeName = (string) config('sso-auth.routes.name_prefix', 'sso-auth.') . 'callback';

        if (Route::has($routeName)) {
            return route($routeName);
        }

        throw new SsoAuthException(
            'Не настроен callback URL: включите маршруты пакета или задайте SSO_AUTH_CALLBACK_URL.',
        );
    }

    private function resolveDefaultTargetUrl(): string
    {
        return (string) config('sso-auth.routes.redirect_after_callback', '/');
    }

    private function absoluteUrl(string $url): string
    {
        $url = trim($url);

        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        return url($url);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function makeTicket(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new SsoAuthException('Не удалось подготовить вход.');
        }

        $payloadB64u = $this->b64uEncode($json);
        $sig = $this->b64uEncode(hash_hmac('sha256', $payloadB64u, $this->clientSecret, true));

        return $payloadB64u . '.' . $sig;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeUser(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        $json = $this->b64uDecode($value);

        if ($json === '') {
            return [];
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $source
     */
    private function requireString(array $source, string $key): string
    {
        if (! isset($source[$key]) || ! is_string($source[$key]) || $source[$key] === '') {
            throw new InvalidCallbackException('Некорректный ответ авторизации.');
        }

        return $source[$key];
    }

    /**
     * @param array<string, mixed> $source
     */
    private function requireInt(array $source, string $key): int
    {
        if (! isset($source[$key]) || ! ctype_digit((string) $source[$key])) {
            throw new InvalidCallbackException('Некорректный ответ авторизации.');
        }

        return (int) $source[$key];
    }

    private function randomUrlSafe(int $bytes): string
    {
        return $this->b64uEncode(random_bytes($bytes));
    }

    private function b64uEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function b64uDecode(string $b64u): string
    {
        $b64 = strtr($b64u, '-_', '+/');
        $padding = strlen($b64) % 4;

        if ($padding !== 0) {
            $b64 .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($b64, true);

        return $decoded === false ? '' : $decoded;
    }
}
