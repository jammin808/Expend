<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered_before_the_owner_exists()
    {
        $this->get('/register')->assertStatus(200);
    }

    public function test_the_owner_can_register_and_is_sent_to_setup()
    {
        $response = $this->post('/register', [
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'a-long-test-password',
            'password_confirmation' => 'a-long-test-password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('setup', absolute: false));
    }

    public function test_registration_closes_once_the_owner_exists()
    {
        User::factory()->create();

        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Intruder', 'email' => 'x@example.com', 'password' => 'password', 'password_confirmation' => 'password',
        ])->assertNotFound();
        $this->assertSame(1, User::count());
    }
}
