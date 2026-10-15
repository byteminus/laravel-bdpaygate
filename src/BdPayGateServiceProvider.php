<?php

namespace ByteMinus\BdPayGate;

use ByteMinus\BdPayGate\Console\SyncPaymentsCommand;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class BdPayGateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bdpaygate.php', 'bdpaygate');

        $this->app->singleton(BdPayGateManager::class, fn ($app) => new BdPayGateManager($app));
        $this->app->alias(BdPayGateManager::class, 'bdpaygate');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/bdpaygate.php' => config_path('bdpaygate.php'),
            ], 'bdpaygate-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'bdpaygate-migrations');

            $this->commands([SyncPaymentsCommand::class]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (config('bdpaygate.routes.enabled', true)) {
            $this->registerRoutes();
        }
    }

    protected function registerRoutes(): void
    {
        $prefix = trim((string) config('bdpaygate.routes.prefix', 'bdpaygate'), '/');

        Route::prefix($prefix)
            ->middleware(config('bdpaygate.routes.middleware', []))
            ->group(__DIR__.'/../routes/web.php');

        // Only matters if you add the "web" group to the routes: gateways cannot send a CSRF token.
        if (class_exists(ValidateCsrfToken::class)) {
            ValidateCsrfToken::except([$prefix.'/*']);
        }
    }
}
