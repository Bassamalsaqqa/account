<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_is_disabled_by_default(): void
    {
        $this->assertFalse(config('auth.registration_enabled'));

        $response = $this->get('/register');
        $response->assertNotFound();
    }

    public function test_registration_can_be_enabled_via_config(): void
    {
        config(['auth.registration_enabled' => true]);

        // Re-load auth routes with the config enabled
        Route::middleware('guest')->group(function () {
            Volt::route('register', 'pages.auth.register')->name('register');
        });

        $response = $this->get('/register');
        $response->assertOk()
            ->assertSeeVolt('pages.auth.register');

        $component = Volt::test('pages.auth.register')
            ->set('name', 'New Trader')
            ->set('email', 'trader@example.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123');

        $component->call('register');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'trader@example.com',
        ]);

        $user = User::where('email', 'trader@example.com')->first();
        $this->assertNotNull($user->public_id);
    }
}
