<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PrivacyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_anyone_can_read_the_privacy_page_without_signing_in(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('privacy', false)
                ->where('business', null)
                ->where('email', null)
                ->has('updated'));

        $this->assertFileExists(resource_path('js/pages/privacy.tsx'));
    }

    public function test_it_names_the_business_and_its_email_from_settings_and_nothing_else(): void
    {
        Setting::put('business_name', 'Acme Events');
        Setting::put('business_email', 'accounts@acme-events.test');
        Setting::put('google_client_secret', 'not-for-the-page');
        Setting::put('business_bank_details', 'Sort code 00-00-00');

        $response = $this->get('/privacy')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('privacy', false)
                ->where('business', 'Acme Events')
                ->where('email', 'accounts@acme-events.test'));

        $response->assertDontSee('not-for-the-page')->assertDontSee('00-00-00');
    }

    public function test_a_signed_in_owner_sees_it_too(): void
    {
        $this->actingAs(User::factory()->create())->get('/privacy')->assertOk();
    }

    public function test_the_sign_in_page_still_loads(): void
    {
        User::factory()->create();

        $this->get('/login')->assertOk();
    }
}
