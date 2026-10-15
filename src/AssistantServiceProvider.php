<?php

namespace Another\DatabaseAssistant;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;

class AssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/assistant.php', 'assistant');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'assistant');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->publishes([__DIR__.'/../config/assistant.php' => config_path('assistant.php')], 'assistant-config');
        RateLimiter::for('assistant', fn ($request) => Limit::perMinute(10)->by((string) $request->user()?->getAuthIdentifier()));
    }
}
