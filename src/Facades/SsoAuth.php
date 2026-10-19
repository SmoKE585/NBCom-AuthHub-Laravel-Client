<?php

declare(strict_types=1);

namespace Nbcom\SsoAuth\Facades;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Nbcom\SsoAuth\Dto\SsoCallbackResult;
use Nbcom\SsoAuth\Services\DelegateClient;

/**
 * @method static string loginUrl(?string $targetUrl = null, ?string $returnUrl = null)
 * @method static SsoCallbackResult validateCallback(Request $request)
 * @method static DelegateClient delegate()
 *
 * @see \Nbcom\SsoAuth\SsoAuth
 */
final class SsoAuth extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Nbcom\SsoAuth\SsoAuth::class;
    }
}
