<?php

declare(strict_types=1);

namespace Nbcom\SsoAuth\Events;

use Nbcom\SsoAuth\Dto\SsoCallbackResult;

/**
 * Событие после успешной проверки подписанного ответа хаба.
 *
 * Пакет не логинит пользователя сам: слушатель события решает, найти
 * локального пользователя по bitrixId, создать его или отказать во входе.
 */
final class SsoCallbackReceived
{
    public function __construct(
        public readonly SsoCallbackResult $result,
    ) {
    }
}
