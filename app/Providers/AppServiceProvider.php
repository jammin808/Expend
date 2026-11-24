<?php

namespace App\Providers;

use App\Domain\Ai\AiClient;
use App\Domain\Ai\AnthropicAiClient;
use App\Domain\Mail\OutgoingMail;
use App\Jobs\Middleware\AuditQueueContext;
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

        // Sending email is set in Settings › Integrations, so read it when the mailer is first needed.
        $this->app->afterResolving('mail.manager', fn () => OutgoingMail::apply());

        // What a queued job writes to the audit trail is from the queue, under the action that started it.
        AuditQueueContext::register();
    }
}
