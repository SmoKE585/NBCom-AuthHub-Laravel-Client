<?php

declare(strict_types=1);

namespace Nbcom\SsoAuth\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Nbcom\SsoAuth\Exceptions\DelegateException;
use Nbcom\SsoAuth\Exceptions\SsoAuthException;

/**
 * Клиент делегированного входа.
 *
 * Сайт-источник уже знает bitrix_user_id и просит хаб выдать подписанный
 * redirect URL для сайта-назначения. Весь обмен — backend-to-backend,
 * client_secret никогда не попадает в браузер.
 */
final class DelegateClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $sourceClientId,
        private readonly string $sourceClientSecret,
        private readonly string $endpoint,
        private readonly int $timeoutSeconds,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            baseUrl: rtrim((string) config('sso-auth.base_url', ''), '/'),
            sourceClientId: (string) config('sso-auth.client_id', ''),
            sourceClientSecret: (string) config('sso-auth.client_secret', ''),
            endpoint: (string) config('sso-auth.delegate.endpoint', '/api/sso/delegate'),
            timeoutSeconds: max(1, (int) config('sso-auth.delegate.timeout', 10)),
        );
    }

    /**
     * Возвращает подписанный URL на сайте-назначении.
     */
    public function redirectUrl(
        string $targetClientId,
        int $bitrixUserId,
        string $redirectUrl,
        ?string $state = null,
        ?string $targetUrl = null,
    ): string {
        $this->assertConfigured();

        // Хаб вернёт браузер на redirect_url с подписанными параметрами.
        // target_url в query — подсказка сайту Б, куда идти после callback;
        // домен всё равно проверяется UrlGuard на стороне сайта Б.
        if ($targetUrl !== null && $targetUrl !== '') {
            $redirectUrl .= (str_contains($redirectUrl, '?') ? '&' : '?')
                . 'target_url=' . rawurlencode($targetUrl);
        }

        $payload = [
            'source_client_id' => $this->sourceClientId,
            'source_client_secret' => $this->sourceClientSecret,
            'target_client_id' => $targetClientId,
            'bitrix_user_id' => $bitrixUserId,
            'redirect_url' => $redirectUrl,
        ];

        if ($state !== null && $state !== '') {
            $payload['state'] = $state;
        }

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->asJson()
                ->post($this->endpoint, $payload);
        } catch (ConnectionException $e) {
            throw new DelegateException('Не удалось связаться с сервисом авторизации.', 0, $e);
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            $message = $response->json('message');
            $message = is_string($message) && $message !== ''
                ? $message
                : 'Не удалось выполнить делегированный вход.';

            throw new DelegateException($message, $response->status());
        }

        $url = $response->json('redirect_url');

        if (! is_string($url) || $url === '') {
            throw new DelegateException('Сервис авторизации вернул пустой redirect URL.');
        }

        return $url;
    }

    private function assertConfigured(): void
    {
        if ($this->baseUrl === '' || $this->sourceClientId === '' || $this->sourceClientSecret === '') {
            throw new SsoAuthException(
                'Делегированный вход не настроен: заполните SSO_AUTH_BASE_URL, SSO_AUTH_CLIENT_ID и SSO_AUTH_CLIENT_SECRET.',
            );
        }
    }
}
