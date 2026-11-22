<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function userWithTwoFactor(): array
    {
        $secret = TwoFactor::newSecret();
        $codes = TwoFactor::newRecoveryCodes();
        $user = User::factory()->create();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes['hashed']])->save();

        return [$user, $secret, $codes['plain']];
    }

    public function test_password_alone_does_not_sign_in_when_two_factor_is_on()
    {
        [$user] = $this->userWithTwoFactor();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
    }

    public function test_a_valid_code_completes_sign_in()
    {
        [$user, $secret] = $this->userWithTwoFactor();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->post('/two-factor-challenge', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_code_is_rejected_and_recovery_codes_work_once()
    {
        [$user, , $plain] = $this->userWithTwoFactor();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['recovery_code' => $plain[0]]);
        $this->assertAuthenticatedAs($user);
        $this->assertCount(7, $user->fresh()->two_factor_recovery_codes);
    }

    public function test_two_factor_can_be_set_up_from_settings()
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/settings/security/enable');
        $secret = $user->fresh()->two_factor_secret;
        $this->assertNotNull($secret);

        $this->actingAs($user)->post('/settings/security/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertSessionHas('recovery_codes');
        $this->assertTrue($user->fresh()->hasTwoFactor());
    }
}
