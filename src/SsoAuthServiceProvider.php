<?php

declare(strict_types=1);

namespace Nbcom\SsoAuth;

use Illuminate\Support\ServiceProvider;
use Nbcom\SsoAuth\Services\DelegateClient;
use Nbcom\SsoAuth\Support\StateStore;

final class SsoAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/sso-auth.php', 'sso-auth');

        $this->app->singleton(StateStore::class);

        $this->app->singleton(
            SsoClient::class,
            static fn ($app): SsoClient => SsoClient::fromConfig($app->make(StateStore::class)),
        );

        $this->app->singleton(
            DelegateClient::class,
            static fn (): DelegateClient => DelegateClient::fromConfig(),
        );

        $this->app->singleton(
            SsoAuth::class,
            static fn ($app): SsoAuth => new SsoAuth(
                $app->make(SsoClient::class),
                $app->make(DelegateClient::class),
            ),
        );

        $this->app->alias(SsoAuth::class, 'sso-auth');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'sso-auth');

        if ((bool) config('sso-auth.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/sso-auth.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes(
                [__DIR__ . '/../config/sso-auth.php' => config_path('sso-auth.php')],
                'sso-auth-config',
            );

            $this->publishes(
                [__DIR__ . '/../resources/views' => resource_path('views/vendor/sso-auth')],
                'sso-auth-views',
            );
        }
    }
}
