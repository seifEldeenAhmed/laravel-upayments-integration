<?php

namespace Osama\Upayments\Providers;

use Illuminate\Support\ServiceProvider;
use Osama\Upayments\Services\UpaymentsService;
use Psr\Log\LoggerInterface;

class UpaymentsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('Upayments', function ($app) {
            return new UpaymentsService();
        });

        $this->mergeConfigFrom(__DIR__ . '/../Config/upayments.php', 'upayments');
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../Config/upayments.php' => config_path('upayments.php'),
            ], 'config');
        }

    }
}