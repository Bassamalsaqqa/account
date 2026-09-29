<?php

namespace App\Console\Commands;

use App\Actions\Company\CreateCompanyAction;
use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BootstrapCompanyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:bootstrap-company
                            {--email= : Email of an existing user who will own the company}
                            {--name-ar= : Arabic name of the company}
                            {--name-en= : English name of the company (optional)}
                            {--base-currency=ILS : Base currency code (ILS, USD, JOD)}
                            {--timezone=Asia/Hebron : Timezone}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Bootstrap an initial company for an existing user';

    /**
     * Execute the console command.
     */
    public function handle(CreateCompanyAction $action): int
    {
        $email = $this->option('email') ?: $this->ask('Owner email address');
        $nameAr = $this->option('name-ar') ?: $this->ask('Company Arabic Name (الاسم العربي)');
        $nameEn = $this->option('name-en');
        $baseCurrency = strtoupper((string) ($this->option('base-currency') ?: 'ILS'));
        $timezone = $this->option('timezone') ?: 'Asia/Hebron';

        $validator = Validator::make([
            'email' => $email,
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'base_currency' => $baseCurrency,
            'timezone' => $timezone,
        ], [
            'email' => ['required', 'string', 'email', 'max:255'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'base_currency' => ['required', 'string', Rule::in(['ILS', 'USD', 'JOD'])],
            'timezone' => ['required', 'string', 'timezone'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        /** @var User|null $user */
        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->error("User with email [{$email}] does not exist. Run app:create-user first.");

            return self::FAILURE;
        }

        // Check if user already owns or belongs to a company to enforce safe-first-bootstrap requirement
        /** @var Company|null $existing */
        $existing = $user->companies()->first();

        if ($existing) {
            $this->info("User [{$email}] already belongs to company '{$existing->displayName()}' (ULID: {$existing->public_id}). Reusing existing bootstrap.");

            return self::SUCCESS;
        }

        $company = $action->execute($user, [
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'base_currency_code' => $baseCurrency,
            'timezone' => $timezone,
            'default_locale' => $user->locale ?: 'ar',
        ]);

        $this->info("Company successfully bootstrapped: '{$company->displayName()}'");
        $this->line("ULID: {$company->public_id}");
        $this->line("Owner: {$user->name} ({$user->email})");
        $this->line("Base Currency: {$company->base_currency_code}");
        $this->line("Timezone: {$company->timezone}");

        return self::SUCCESS;
    }
}
