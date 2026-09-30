<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Accounting\Catalog\SystemAccountsCatalog;
use App\Domain\Accounting\Exceptions\SystemAccountConflictException;
use App\Exceptions\CompanyReassignmentException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnsureSystemLedgerAccountsAction
{
    /**
     * Provision and reconcile the default system chart of accounts for a company idempotently.
     */
    public function execute(Company $company, bool $isSystem = false): void
    {
        $context = app(CompanyContext::class);

        if (! $isSystem) {
            if (! $context->hasCompany()) {
                throw new NoActiveCompanyException('Cannot provision system accounts without an active company context.');
            }

            if ($context->companyId() !== $company->id) {
                throw new CompanyReassignmentException("Cannot provision system accounts for company [{$company->id}] when active company is [{$context->companyId()}].");
            }
        }

        $provision = function () use ($company): void {
            DB::transaction(function () use ($company): void {
                // Lock the company row first to serialize concurrent provisioning even when no accounts exist yet
                Company::where('id', $company->id)->lockForUpdate()->firstOrFail();

                // Lock existing accounts for this company to prevent race conditions
                /** @var Collection<int, LedgerAccount> $existingAccounts */
                $existingAccounts = LedgerAccount::where('company_id', $company->id)
                    ->lockForUpdate()
                    ->get();

                /** @var array<string, LedgerAccount> $byKey */
                $byKey = [];
                /** @var array<string, LedgerAccount> $byCode */
                $byCode = [];

                foreach ($existingAccounts as $account) {
                    if ($account->system_key !== null) {
                        $byKey[$account->system_key] = $account;
                    }
                    $byCode[$account->code] = $account;
                }

                $definitions = SystemAccountsCatalog::all();

                // Pass 1: Verify or create each system account
                foreach ($definitions as $def) {
                    if (isset($byKey[$def->systemKey])) {
                        $existing = $byKey[$def->systemKey];

                        // Verify that existing account code matches standard catalog code
                        if ($existing->code !== $def->code) {
                            throw SystemAccountConflictException::keyConflict(
                                $def->systemKey,
                                $def->code,
                                $existing->id,
                                $existing->code
                            );
                        }

                        // Verify semantic integrity of existing system account
                        if ($existing->account_type !== $def->accountType) {
                            throw SystemAccountConflictException::semanticConflict(
                                $def->systemKey,
                                'account_type',
                                $def->accountType,
                                $existing->account_type
                            );
                        }

                        if ($existing->normal_balance !== $def->normalBalance) {
                            throw SystemAccountConflictException::semanticConflict(
                                $def->systemKey,
                                'normal_balance',
                                $def->normalBalance,
                                $existing->normal_balance
                            );
                        }

                        if ((bool) $existing->is_control !== (bool) $def->isControl) {
                            throw SystemAccountConflictException::semanticConflict(
                                $def->systemKey,
                                'is_control',
                                $def->isControl ? 'true' : 'false',
                                $existing->is_control ? 'true' : 'false'
                            );
                        }

                        if (! $existing->is_system) {
                            throw SystemAccountConflictException::semanticConflict(
                                $def->systemKey,
                                'is_system',
                                'true',
                                'false'
                            );
                        }

                        if (! $existing->active) {
                            throw SystemAccountConflictException::semanticConflict(
                                $def->systemKey,
                                'active',
                                'true',
                                'false'
                            );
                        }

                        continue;
                    }

                    // System key does not exist yet. Check if preferred code is occupied
                    if (isset($byCode[$def->code])) {
                        $conflict = $byCode[$def->code];
                        throw SystemAccountConflictException::codeConflict(
                            $def->systemKey,
                            $def->code,
                            $conflict->id,
                            $conflict->system_key
                        );
                    }

                    // Create the system account
                    $created = LedgerAccount::create([
                        'public_id' => (string) Str::ulid(),
                        'company_id' => $company->id,
                        'code' => $def->code,
                        'system_key' => $def->systemKey,
                        'name_ar' => $def->nameAr,
                        'name_en' => $def->nameEn,
                        'account_type' => $def->accountType,
                        'normal_balance' => $def->normalBalance,
                        'is_control' => $def->isControl,
                        'is_system' => true,
                        'active' => true,
                    ]);

                    $byKey[$def->systemKey] = $created;
                    $byCode[$def->code] = $created;
                }

                // Pass 2: Reconcile parent account relationships
                foreach ($definitions as $def) {
                    if ($def->parentSystemKey !== null && isset($byKey[$def->parentSystemKey], $byKey[$def->systemKey])) {
                        $child = $byKey[$def->systemKey];
                        $parent = $byKey[$def->parentSystemKey];

                        if ($child->parent_id !== $parent->id) {
                            $child->parent_id = $parent->id;
                            $child->save();
                        }
                    }
                }
            });
        };

        if ($isSystem && ! $context->hasCompany()) {
            CompanyScope::executeWithoutScope($provision);
        } else {
            $provision();
        }
    }
}
