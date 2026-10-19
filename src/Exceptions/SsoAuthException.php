<?php

declare(strict_types=1);

namespace Nbcom\SsoAuth\Exceptions;

use RuntimeException;

/**
 * Базовая ошибка пакета. Сообщение можно безопасно показывать пользователю.
 */
class SsoAuthException extends RuntimeException
{
}
