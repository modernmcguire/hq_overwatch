<?php

namespace ModernMcguire\Overwatch;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class OverwatchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mmp.php', 'mmp');
    }

    public function boot(): void
    {
        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/mmp.php' => $this->app->configPath('mmp.php'),
            ], 'mmp-config');
        }
    }

    protected function registerRoutes(): void
    {
        Route::middleware('web')
            ->prefix((string) config('mmp.overwatch.route_prefix', 'mmp/overwatch'))
            ->group(__DIR__.'/../routes/overwatch.php');
    }
}
