<?php

namespace App\Providers;

use App\Domain\Ai\AiClient;
use App\Domain\Ai\AnthropicAiClient;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AiClient::class, AnthropicAiClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The live site sits behind Hostinger's CDN, which may reach the server over plain HTTP:
        // always build https:// links there so assets and redirects never downgrade.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
