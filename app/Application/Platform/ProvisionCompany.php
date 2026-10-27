<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Actions\Company\CreateCompanyAction;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Throwable;

final class ProvisionCompany
{
    /** @param array<string, mixed> $input */
    public function execute(#[SensitiveParameter] array $input): Company
    {
        app(PlatformAccess::class)->authorize('company.provision');
        $confirmed = request()->session()->get('auth.password_confirmed_at');
        if (! is_int($confirmed) || $confirmed > now()->timestamp || $confirmed < now()->subMinutes(15)->timestamp) {
            throw new AuthorizationException('Recent password confirmation required.');
        }

        $allowed = ['request_key', 'name_ar', 'name_en', 'base_currency_code', 'default_locale', 'timezone', 'owner_name', 'owner_email', 'password', 'password_confirmation'];
        $values = array_intersect_key($input, array_flip($allowed));
        foreach (['name_ar', 'name_en', 'owner_name', 'owner_email'] as $field) {
            if (is_string($values[$field] ?? null)) {
                $values[$field] = trim($values[$field]);
            }
        }
        if (is_string($values['owner_email'] ?? null)) {
            $values['owner_email'] = mb_strtolower($values['owner_email']);
        }
        $data = Validator::make($values, [
            'request_key' => ['required', 'uuid'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'base_currency_code' => ['required', 'in:ILS,USD,JOD'],
            'default_locale' => ['required', 'in:ar,en'],
            'timezone' => ['required', 'timezone'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ])->validate();
        $canonical = [];
        foreach ($allowed as $field) {
            if (! in_array($field, ['request_key', 'password_confirmation'], true)) {
                $canonical[$field] = $data[$field] ?? null;
            }
        }
        // Keyed digest compares confidential payloads without storing password/plain hashes.
        $fingerprint = hash_hmac('sha256', json_encode($canonical, JSON_THROW_ON_ERROR), (string) config('app.key'));
        $context = app(CompanyContext::class);
        $priorCompany = $context->hasCompany() ? $context->company() : null;
        $priorUser = $context->user();
        $priorTeam = getPermissionsTeamId();
        $actorId = (int) auth()->id();
        $context->clear();

        try {
            return DB::transaction(function () use ($data, $fingerprint, $actorId): Company {
                // Serializes this operator's provisioning/revocation without external I/O.
                // Locking identity/grant reads avoid stale repeatable-read snapshots after credential revocation.
                app(PlatformAccess::class)->authorize('company.provision', lock: true);
                $existing = DB::table('platform_provisioning_requests')
                    ->where('operator_user_id', $actorId)->where('request_key', $data['request_key'])->lockForUpdate()->first();
                if ($existing !== null) {
                    if (! hash_equals($existing->payload_fingerprint, $fingerprint) || $existing->company_id === null) {
                        throw ValidationException::withMessages(['request_key' => __('platform.retry_changed')]);
                    }

                    return Company::query()->findOrFail($existing->company_id);
                }
                if (User::query()->where('email', $data['owner_email'])->exists()) {
                    throw ValidationException::withMessages(['owner_email' => __('platform.email_unavailable')]);
                }
                $requestId = DB::table('platform_provisioning_requests')->insertGetId([
                    'operator_user_id' => $actorId, 'request_key' => $data['request_key'],
                    'payload_fingerprint' => $fingerprint, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $owner = User::query()->create([
                    'name' => $data['owner_name'], 'email' => $data['owner_email'],
                    'password' => $data['password'], 'locale' => $data['default_locale'],
                ]);
                // Preserve current trusted administrator-created-account semantics; no email sent.
                $owner->forceFill(['email_verified_at' => now()])->save();
                $company = app(CreateCompanyAction::class)->execute($owner, $data);
                DB::table('platform_provisioning_requests')->where('id', $requestId)->update([
                    'company_id' => $company->id, 'owner_user_id' => $owner->id, 'updated_at' => now(),
                ]);
                app(PlatformAudit::class)->record('company.provisioned', $actorId, (int) $company->id, ['owner_user_id' => (int) $owner->id]);

                return $company;
            }, attempts: 3);
        } catch (Throwable $e) {
            $context->clear();
            app(PlatformAudit::class)->record('company.provision_failed', $actorId, metadata: ['correlation_id' => (string) Str::uuid()]);
            if ($e instanceof QueryException && (string) $e->getCode() === '23000') {
                throw ValidationException::withMessages(['owner_email' => __('platform.email_unavailable')]);
            }

            throw $e;
        } finally {
            $context->clear();
            if ($priorCompany !== null && $priorUser !== null) {
                try {
                    $context->setCompany($priorCompany->fresh() ?? $priorCompany, $priorUser);
                } catch (AuthorizationException) {
                    // Revoked membership cannot be revived as ambient tenant context.
                    $context->clear();
                }
            }
            if ($priorCompany === null || $context->hasCompany()) {
                setPermissionsTeamId($priorTeam);
            }
            $priorUser?->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
