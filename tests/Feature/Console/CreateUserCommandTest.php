<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_user_via_command_with_hidden_password_prompt(): void
    {
        $this->artisan('app:create-user', [
            '--name' => 'Initial User',
            '--email' => 'initial@example.com',
            '--locale' => 'ar',
        ])
            ->expectsQuestion('Password (minimum 8 characters)', 'SecurePass123!')
            ->expectsOutputToContain('successfully created')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', [
            'email' => 'initial@example.com',
            'name' => 'Initial User',
            'locale' => 'ar',
        ]);

        $user = User::where('email', 'initial@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('SecurePass123!', $user->password));
        $this->assertNotNull($user->public_id);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_can_create_user_via_fully_interactive_prompts(): void
    {
        $this->artisan('app:create-user')
            ->expectsQuestion('Full Name', 'Interactive User')
            ->expectsQuestion('Email address', 'interactive@example.com')
            ->expectsQuestion('Password (minimum 8 characters)', 'SecurePass123!')
            ->expectsOutputToContain('successfully created')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', [
            'email' => 'interactive@example.com',
            'name' => 'Interactive User',
            'locale' => 'ar',
        ]);
    }

    public function test_password_option_is_not_accepted_as_a_command_option(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->artisan('app:create-user', [
            '--name' => 'Test User',
            '--email' => 'test@example.com',
            '--password' => 'SecurePass123!',
        ]);
    }

    public function test_fails_when_email_already_exists(): void
    {
        User::factory()->create([
            'email' => 'duplicate@example.com',
        ]);

        $this->artisan('app:create-user', [
            '--name' => 'Duplicate User',
            '--email' => 'duplicate@example.com',
        ])
            ->expectsQuestion('Password (minimum 8 characters)', 'SecurePass123!')
            ->assertExitCode(1);
    }

    public function test_fails_when_password_is_shorter_than_eight_characters(): void
    {
        $this->artisan('app:create-user', [
            '--name' => 'Short Pass',
            '--email' => 'short@example.com',
        ])
            ->expectsQuestion('Password (minimum 8 characters)', 'short')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', [
            'email' => 'short@example.com',
        ]);
    }
}
