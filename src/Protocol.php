<?php

declare(strict_types=1);

namespace Nbcom\SsoAuth;

/**
 * Константы протокола ЕЦА NBCom.
 *
 * Имена параметров и порядок подписи — публичный контракт со всеми
 * клиентскими сайтами. Менять их нельзя.
 */
final class Protocol
{
    public const START_PATH = '/start';

    public const Q_CLIENT_ID = 'client_id';
    public const Q_TICKET = 'ticket';
    public const Q_STATE = 'state';
    public const Q_RETURN_URL = 'return_url';
    public const Q_TARGET_URL = 'target_url';

    public const R_STATE = 'state';
    public const R_BITRIX_USER_ID = 'bitrix_user_id';
    public const R_TS = 'ts';
    public const R_USER = 'user';
    public const R_SIG = 'sig';

    public const DEFAULT_TTL_SECONDS = 180;
    public const DEFAULT_CLOCK_SKEW_SECONDS = 30;
}
