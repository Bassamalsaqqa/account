<?php

declare(strict_types=1);

use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table): void {
            $table->dateTime('voided_at')->nullable()->after('posted_by');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 500)->nullable()->after('voided_by');
            $table->foreignId('void_posting_batch_id')->nullable()->after('void_reason')->constrained('posting_batches')->restrictOnDelete();
        });

        // Register new permissions globally
        $newPermissions = [
            'purchasing.purchase.void',
            'purchasing.return.view',
            'purchasing.return.create',
            'purchasing.return.post',
            'purchasing.return.void',
        ];
        foreach ($newPermissions as $perm) {
            Permission::firstOrCreate([
                'name' => $perm,
                'guard_name' => 'web',
            ]);
        }

        // Grant to Owner and Manager roles for all existing companies
        $previousTeam = getPermissionsTeamId();
        try {
            Company::query()->orderBy('id')->each(function (Company $company) use ($newPermissions): void {
                setPermissionsTeamId($company->id);
                app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

                $owner = Role::where('company_id', $company->id)->where('name', 'Owner')->where('guard_name', 'web')->first();
                if ($owner !== null) {
                    $owner->givePermissionTo($newPermissions);
                }

                $manager = Role::where('company_id', $company->id)->where('name', 'Manager')->where('guard_name', 'web')->first();
                if ($manager !== null) {
                    $manager->givePermissionTo($newPermissions);
                }
            });
        } finally {
            setPermissionsTeamId($previousTeam);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        if (DB::table('purchase_returns')->where(fn ($query) => $query->where('status', 'void')->orWhereNotNull('voided_at')->orWhereNotNull('void_posting_batch_id'))->exists()) {
            throw new RuntimeException('Cannot remove purchase return void history.');
        }
        Schema::table('purchase_returns', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('void_posting_batch_id');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason']);
        });

        // Inert permissions remain to preserve explicit Owner delegation history.
    }
};
