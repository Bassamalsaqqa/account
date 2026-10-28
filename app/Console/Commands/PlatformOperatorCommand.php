<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Platform\PlatformAccess;
use App\Application\Platform\PlatformAudit;
use App\Models\PlatformOperatorGrant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PlatformOperatorCommand extends Command
{
    protected $signature = 'platform:operator {email : Existing operator account} {--revoke : Revoke platform authority}';

    protected $description = 'Explicit host-operator grant/revocation; never creates or impersonates a customer account';

    public function handle(): int
    {
        $user = User::query()->where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();
        if ($user === null) {
            $this->error('An existing account is required for a grant.');

            return self::FAILURE;
        }
        if (! $this->input->isInteractive() || ! $this->confirm('Change platform authority for this exact existing account?', false)) {
            $this->error('Interactive operator confirmation is required.');

            return self::FAILURE;
        }
        $revoke = (bool) $this->option('revoke');
        DB::transaction(function () use ($user, $revoke): void {
            $grant = PlatformOperatorGrant::query()->where('user_id', $user->id)->lockForUpdate()->first() ?? new PlatformOperatorGrant;
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $grant->forceFill([
                'user_id' => $user->id, 'active' => ! $revoke,
                'permissions' => $revoke ? [] : PlatformAccess::PERMISSIONS,
                'revision' => $grant->exists ? ((int) $grant->revision + 1) : 1,
            ])->save();
            // Null actor means trusted host operator, not a fabricated web identity.
            app(PlatformAudit::class)->record($revoke ? 'operator.revoked' : 'operator.granted', null, metadata: [
                'operator_user_id' => (int) $user->id, 'grant_revision' => (int) $grant->revision,
            ]);
        });
        $this->info('Platform authority updated. Sign in again or confirm your account password for access.');

        return self::SUCCESS;
    }
}
