<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nbcom\SsoAuth\Http\Controllers\CallbackController;
use Nbcom\SsoAuth\Http\Controllers\LoginController;

Route::middleware((array) config('sso-auth.routes.middleware', ['web']))
    ->prefix(trim((string) config('sso-auth.routes.prefix', 'sso'), '/'))
    ->name((string) config('sso-auth.routes.name_prefix', 'sso-auth.'))
    ->group(function (): void {
        Route::get('login', LoginController::class)->name('login');
        Route::get('callback', CallbackController::class)->name('callback');
    });
