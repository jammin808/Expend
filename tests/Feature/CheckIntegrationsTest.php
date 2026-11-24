<?php

namespace Tests\Feature;

use App\Domain\Ai\AiClient;
use App\Domain\Ai\AiResult;
use App\Models\CycleRun;
use App\Models\CycleRunStep;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

class CheckIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_working_ai_and_telegram_without_printing_secrets(): void
    {
        $this->app->instance(AiClient::class, new FakeAiClient([FakeAiClient::text('OK')]));
        Setting::put('anthropic_api_key', 'sk-ant-secret-key-value');
        Setting::put('telegram_bot_token', '123456:SECRET-token_value');
        Setting::put('telegram_chat_id', '987654321');
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'ledger_test_bot']]),
            'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => url('/hooks/telegram'), 'pending_update_count' => 0]]),
            'api.telegram.org/*/sendMessage' => Http::response(['ok' => true]),
        ]);

        $this->artisan('ledger:check', ['--telegram-message' => true])
            ->expectsOutputToContain('Test request: OK, answered "OK"')
            ->expectsOutputToContain('Bot: @ledger_test_bot (token accepted)')
            ->expectsOutputToContain('Webhook: points at this site')
            ->expectsOutputToContain('Paired with your chat: yes')
            ->expectsOutputToContain('Test message: sent')
            ->expectsOutputToContain('All checks passed.')
            ->doesntExpectOutputToContain('SECRET')
            ->doesntExpectOutputToContain('sk-ant-secret')
            ->doesntExpectOutputToContain('987654321')
            ->assertExitCode(0);
    }

    public function test_it_reports_problems_with_the_cause(): void
    {
        $failing = new class implements AiClient
        {
            public function send(array $params): AiResult
            {
                throw new \RuntimeException('401 invalid x-api-key sk-ant-leaked-value');
            }
        };
        $this->app->instance(AiClient::class, $failing);
        Setting::put('anthropic_api_key', 'sk-ant-wrong');
        Setting::put('telegram_bot_token', '123456:SECRET');
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'ledger_test_bot']]),
            'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => [
                'url' => url('/hooks/telegram'), 'pending_update_count' => 3,
                'last_error_message' => 'Wrong response from the webhook: 500 Internal Server Error', 'last_error_date' => 1795393000,
            ]]),
        ]);

        $this->artisan('ledger:check')
            ->expectsOutputToContain('Test request: FAILED, RuntimeException: 401 invalid x-api-key sk-ant-[key]')
            ->expectsOutputToContain('Last delivery error: Wrong response from the webhook: 500')
            ->expectsOutputToContain('Paired with your chat: NO')
            ->doesntExpectOutputToContain('leaked-value')
            ->assertExitCode(1);
    }

    public function test_it_shows_the_nightly_cycles_latest_run_and_its_failed_steps(): void
    {
        $this->artisan('ledger:check')->expectsOutputToContain('Nightly cycle: off, no runs yet');

        Setting::put('intel_cycle', 'on');
        $run = CycleRun::query()->create(['run_key' => 'nightly:2026-11-24', 'kind' => 'nightly', 'mode' => 'on', 'status' => 'partial',
            'started_at' => '2026-11-24 03:05:00', 'finished_at' => '2026-11-24 04:12:00']);
        foreach ([['verify.audit_chain', 'ok', null], ['relate.jobs', 'failed', "RuntimeException: 401 invalid x-api-key sk-ant-leaked-value\n#0 trace"]] as $i => [$step, $status, $error]) {
            CycleRunStep::query()->create(['cycle_run_id' => $run->id, 'step' => $step, 'stage' => 'relate', 'seq' => $i + 1, 'status' => $status, 'error' => $error]);
        }

        $this->artisan('ledger:check')
            ->expectsOutputToContain('Nightly cycle: on, latest run nightly:2026-11-24 (real): finished in part, ended 24 Nov 04:12')
            ->expectsOutputToContain('Step failed: relate.jobs (the job finder), RuntimeException: 401 invalid x-api-key sk-ant-[key]')
            ->doesntExpectOutputToContain('leaked-value')
            ->doesntExpectOutputToContain('verify.audit_chain')
            ->assertExitCode(1);
    }
}
