<?php

declare(strict_types=1);

namespace Nbcom\SsoAuth\Exceptions;

/**
 * Подписанный ответ хаба не прошёл проверку: state, подпись или срок.
 */
final class InvalidCallbackException extends SsoAuthException
{
}
