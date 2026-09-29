<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-user
                            {--name= : Full name of the user}
                            {--email= : Unique email address of the user}
                            {--locale=ar : Interface locale (ar or en)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create an initial login user when public registration is disabled';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Full Name');
        $email = $this->option('email') ?: $this->ask('Email address');
        $password = $this->secret('Password (minimum 8 characters)');
        $locale = $this->option('locale') ?: 'ar';

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'locale' => $locale,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['required', 'string', 'min:8'],
            'locale' => ['required', 'string', Rule::in(['ar', 'en'])],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => (string) $name,
            'email' => (string) $email,
            'password' => Hash::make((string) $password),
            'locale' => (string) $locale,
        ]);

        $user->forceFill([
            'email_verified_at' => now(),
        ])->save();

        $this->info("User [{$user->email}] successfully created with Public ID [{$user->public_id}].");

        return self::SUCCESS;
    }
}
