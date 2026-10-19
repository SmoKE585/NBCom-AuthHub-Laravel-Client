<?php

declare(strict_types=1);

namespace Nbcom\SsoAuth\Dto;

/**
 * Результат проверки callback: профиль пользователя и сохранённый target URL.
 */
final readonly class SsoCallbackResult
{
    public function __construct(
        public SsoUser $user,
        public ?string $targetUrl,
        public string $state,
        public int $timestamp,
    ) {
    }
}
