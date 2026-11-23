<?php

namespace Tests\Feature;

use App\Domain\Mail\OutgoingMail;
use App\Mail\TestEmail;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OutgoingMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_live_site_sends_from_the_server_until_you_choose_otherwise(): void
    {
        $this->app['env'] = 'production';
        config(['app.url' => 'https://expenses.rohleder.tech']);
        OutgoingMail::apply();
        $this->assertSame('sendmail', config('mail.default'));
        $this->assertSame('noreply@rohleder.tech', config('mail.from.address'));

        Setting::put('mail_method', 'smtp');
        Setting::put('smtp_host', 'smtp.hostinger.com');
        Setting::put('smtp_username', 'jo@example.com');
        Setting::put('smtp_password', 'secret');
        OutgoingMail::apply();
        $this->assertSame(['smtp', 'smtps', 465, 'jo@example.com'], [config('mail.default'), config('mail.mailers.smtp.scheme'), config('mail.mailers.smtp.port'), config('mail.from.address')]);

        Setting::put('mail_method', 'off');
        OutgoingMail::apply();
        $this->assertSame('log', config('mail.default'));
    }

    public function test_settings_can_be_saved_and_a_test_email_sent(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $this->actingAs($user);

        $this->put('/settings/integrations/mail', ['method' => 'smtp', 'smtp_host' => 'smtp.hostinger.com', 'smtp_port' => 465, 'smtp_username' => 'jo@example.com'])
            ->assertSessionHas('error');
        $this->put('/settings/integrations/mail', ['method' => 'smtp', 'smtp_host' => 'smtp.hostinger.com', 'smtp_port' => 465, 'smtp_username' => 'jo@example.com', 'smtp_password' => 'pw'])
            ->assertSessionHas('success');
        $this->assertStringNotContainsString('pw', (string) Setting::query()->find('smtp_password')->value, 'Stored encrypted');

        $this->post('/settings/integrations/mail/test')->assertSessionHas('success');
        Mail::assertSent(TestEmail::class, fn ($m) => $m->hasTo('owner@example.com'));
    }

    public function test_forgot_password_sends_the_reset_link(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_a_reset_link_can_be_made_on_the_server_when_email_is_unavailable(): void
    {
        User::factory()->create(['email' => 'owner@example.com']);
        $this->artisan('ledger:reset-link')->expectsOutputToContain('/reset-password/')->assertExitCode(0);
    }
}
