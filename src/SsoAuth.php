<?php

declare(strict_types=1);

namespace Nbcom\SsoAuth;

use Illuminate\Http\Request;
use Nbcom\SsoAuth\Dto\SsoCallbackResult;
use Nbcom\SsoAuth\Services\DelegateClient;

/**
 * Точка входа для приложения.
 *
 * SsoAuth::loginUrl()                 — ссылка на /start хаба
 * SsoAuth::validateCallback($request) — проверка ответа и DTO
 * SsoAuth::delegate()                 — делегированный вход
 */
final class SsoAuth
{
    public function __construct(
        private readonly SsoClient $client,
        private readonly DelegateClient $delegate,
    ) {
    }

    public function loginUrl(?string $targetUrl = null, ?string $returnUrl = null): string
    {
        return $this->client->createLoginUrl($targetUrl, $returnUrl);
    }

    public function validateCallback(Request $request): SsoCallbackResult
    {
        return $this->client->validateCallback($request->query());
    }

    public function delegate(): DelegateClient
    {
        return $this->delegate;
    }
}
