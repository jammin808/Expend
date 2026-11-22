<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_a_new_owner_is_sent_to_setup_first()
    {
        $this->actingAs(User::factory()->create());
        $this->get('/dashboard')->assertRedirect('/setup');
    }

    public function test_the_dashboard_shows_once_set_up()
    {
        $this->actingAs(User::factory()->create());
        Account::create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $this->get('/dashboard')->assertOk();
    }
}
