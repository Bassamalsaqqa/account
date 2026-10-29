<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Models\Company;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use stdClass;

/** Platform-only commercial records. No tenant ledger, stock or payment writer is called. */
final class ManageSubscription
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(string $companyPublicId, string $operation, array $input): array
    {
        app(PlatformAccess::class)->authorize('subscription.manage');
        $this->requirePassword();
        $rules = [
            'request_key' => ['required', 'uuid'],
            'expected_revision' => ['required', 'integer', 'min:0'],
        ];
        $rules += match ($operation) {
            'configure' => [
                'plan' => ['required', 'string', 'max:100'],
                'fee' => ['required', 'string', 'regex:/^\d{1,14}(\.\d{1,6})?$/'],
                'currency' => ['required', 'in:ILS,USD,JOD'],
                'starts_on' => ['required', 'date_format:Y-m-d'],
                'renews_on' => ['required', 'date_format:Y-m-d', 'after:starts_on'],
                'grace_days' => ['required', 'integer', 'between:0,365'],
                'reminder_days' => ['required', 'array', 'min:1', 'max:20'],
                'reminder_days.*' => ['required', 'integer', 'between:1,365', 'distinct'],
                'enforcement_enabled' => ['required', 'boolean'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
            'renew' => [
                'renews_on' => ['required', 'date_format:Y-m-d'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
            'payment' => [
                'charge_id' => ['required', 'integer', 'min:1'],
                'amount' => ['required', 'string', 'regex:/^\d{1,14}(\.\d{1,6})?$/'],
                'currency' => ['required', 'in:ILS,USD,JOD'],
                'received_on' => ['required', 'date_format:Y-m-d'],
                'reference' => ['nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
            default => throw new AuthorizationException('Unknown commercial operation.'),
        };
        // Drop credentials, tenant amounts and all other unrelated submitted fields.
        $input = array_intersect_key($input, $rules);
        foreach (['plan', 'notes', 'reference'] as $key) {
            if (is_string($input[$key] ?? null)) {
                $input[$key] = trim($input[$key]);
            }
        }
        $attributes = [];
        foreach (['plan', 'fee', 'currency', 'starts_on', 'renews_on', 'grace_days', 'reminder_days', 'enforcement_enabled', 'notes', 'received_on', 'reference'] as $field) {
            $attributes[$field] = __('subscriptions.'.$field);
        }
        $attributes['reminder_days.*'] = __('subscriptions.reminder_days');
        $attributes['amount'] = __('subscriptions.payment_amount');
        $attributes['charge_id'] = __('subscriptions.select_charge');
        $attributes['expected_revision'] = __('subscriptions.revision');
        $data = Validator::make($input, $rules, [], $attributes)->validate();
        $data['expected_revision'] = (int) $data['expected_revision'];
        foreach (['amount', 'fee'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (string) MoneyAmount::from($data[$field]);
            }
        }
        if ($operation === 'configure') {
            $data['grace_days'] = (int) $data['grace_days'];
            $data['reminder_days'] = array_map(intval(...), $data['reminder_days']);
            sort($data['reminder_days'], SORT_NUMERIC);
            $data['enforcement_enabled'] = (bool) $data['enforcement_enabled'];
            // Validate exact local calendar configuration before starting writes.
            (new SubscriptionTimeline)->evaluate($data['renews_on'], 'UTC', $data['grace_days'], $data['reminder_days'], new DateTimeImmutable);
        }
        if ($operation === 'payment') {
            $data['charge_id'] = (int) $data['charge_id'];
            if (MoneyAmount::from($data['amount'])->getAmount()->isLessThanOrEqualTo('0')) {
                throw ValidationException::withMessages(['amount' => __('subscriptions.positive_amount')]);
            }
        }
        $requestKey = $data['request_key'];
        unset($data['request_key']);
        ksort($data);
        $fingerprint = hash_hmac('sha256', json_encode([$companyPublicId, $operation, $data], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return DB::transaction(function () use ($companyPublicId, $operation, $data, $requestKey, $fingerprint): array {
            // Same grant/identity lock order as provisioning; existing permissions are never expanded implicitly.
            $grant = app(PlatformAccess::class)->authorize('subscription.manage', lock: true);
            $this->requirePassword();
            $actorId = (int) $grant->user_id;
            $company = Company::query()->where('public_id', $companyPublicId)->lockForUpdate()->firstOrFail();
            if ($operation === 'payment' && $data['received_on'] > now()->setTimezone($company->timezone)->toDateString()) {
                throw ValidationException::withMessages(['received_on' => __('subscriptions.future_payment')]);
            }
            $request = DB::table('platform_billing_requests')->where('operator_user_id', $actorId)->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($request !== null) {
                if ((int) $request->company_id !== (int) $company->id || $request->operation !== $operation || ! hash_equals($request->payload_fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages(['request_key' => __('platform.retry_changed')]);
                }

                return json_decode($request->result, true, 512, JSON_THROW_ON_ERROR);
            }
            $subscription = DB::table('platform_subscriptions')->where('company_id', $company->id)->lockForUpdate()->first();
            if ((int) ($subscription->revision ?? 0) !== $data['expected_revision']) {
                throw ValidationException::withMessages(['expected_revision' => __('subscriptions.stale_revision')]);
            }
            $before = $subscription === null ? null : (array) $subscription;
            $result = match ($operation) {
                'configure' => $this->configure((int) $company->id, $subscription, $data),
                'renew' => $this->renew((int) $company->id, $subscription, $data),
                'payment' => $this->payment((int) $company->id, $actorId, $subscription, $data),
            };
            $after = DB::table('platform_subscriptions')->where('company_id', $company->id)->first();
            $requestId = DB::table('platform_billing_requests')->insertGetId([
                'company_id' => $company->id, 'operator_user_id' => $actorId,
                'request_key' => $requestKey, 'operation' => $operation,
                'payload_fingerprint' => $fingerprint,
                'payload' => json_encode(['input' => $data, 'before' => $before, 'after' => $after], JSON_THROW_ON_ERROR),
                'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            app(PlatformAudit::class)->record(match ($operation) {
                'configure' => 'subscription.configured',
                'renew' => 'subscription.renewed',
                'payment' => 'subscription.payment_recorded',
            }, $actorId, (int) $company->id, ['billing_request_id' => $requestId]);

            return $result;
        }, attempts: 3);
    }

    private function requirePassword(): void
    {
        $confirmed = request()->session()->get('auth.password_confirmed_at');
        if (! is_int($confirmed) || $confirmed > now()->timestamp || $confirmed < now()->subMinutes(15)->timestamp) {
            throw new AuthorizationException('Recent password confirmation required.');
        }
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function configure(int $companyId, ?stdClass $subscription, array $data): array
    {
        if ($subscription !== null && ($subscription->starts_on !== $data['starts_on'] || $subscription->renews_on !== $data['renews_on'])) {
            throw ValidationException::withMessages(['renews_on' => __('subscriptions.use_renewal')]);
        }
        $revision = (int) ($subscription->revision ?? 0) + 1;
        $terms = [
            'plan' => $data['plan'], 'fee' => $data['fee'], 'currency' => $data['currency'],
            'starts_on' => $data['starts_on'], 'renews_on' => $data['renews_on'],
            'grace_days' => $data['grace_days'], 'reminder_days' => json_encode($data['reminder_days'], JSON_THROW_ON_ERROR),
            'enforcement_enabled' => $data['enforcement_enabled'], 'notes' => $data['notes'] ?? null,
            'revision' => $revision, 'created_at' => $subscription->created_at ?? now(), 'updated_at' => now(),
        ];
        if ($subscription === null) {
            DB::table('platform_subscriptions')->insert(['company_id' => $companyId] + $terms);
        } else {
            DB::table('platform_subscriptions')->where('company_id', $companyId)->update($terms);
        }
        $chargeId = $subscription === null ? $this->charge($companyId, $revision, $data['plan'], $data['fee'], $data['currency'], $data['starts_on'], $data['renews_on']) : null;

        return ['company_id' => $companyId, 'revision' => $revision, 'charge_id' => $chargeId];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function renew(int $companyId, ?stdClass $subscription, array $data): array
    {
        if ($subscription === null || $data['renews_on'] <= $subscription->renews_on) {
            throw ValidationException::withMessages(['renews_on' => __('subscriptions.later_renewal')]);
        }
        $revision = (int) $subscription->revision + 1;
        $chargeId = $this->charge($companyId, $revision, $subscription->plan, $subscription->fee, $subscription->currency, $subscription->renews_on, $data['renews_on']);
        DB::table('platform_subscriptions')->where('company_id', $companyId)->update([
            'renews_on' => $data['renews_on'], 'revision' => $revision, 'updated_at' => now(),
        ]);

        return ['company_id' => $companyId, 'revision' => $revision, 'charge_id' => $chargeId];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function payment(int $companyId, int $actorId, ?stdClass $subscription, array $data): array
    {
        if ($subscription === null) {
            throw ValidationException::withMessages(['charge_id' => __('subscriptions.charge_unavailable')]);
        }
        $charge = DB::table('platform_subscription_charges')->where('company_id', $companyId)->where('id', $data['charge_id'])->lockForUpdate()->first();
        if ($charge === null || $charge->currency !== $data['currency']) {
            throw ValidationException::withMessages(['charge_id' => __('subscriptions.charge_unavailable')]);
        }
        // A caller may already own an older REPEATABLE READ snapshot. Locking reads,
        // unlike a plain SUM, see receipts committed before our company lock was acquired.
        $outstanding = MoneyAmount::from($charge->amount)->getAmount();
        foreach (DB::table('platform_subscription_payments')->where('company_id', $companyId)->where('charge_id', $charge->id)->lockForUpdate()->pluck('amount') as $received) {
            $outstanding = $outstanding->minus(MoneyAmount::from((string) $received)->getAmount());
        }
        if (MoneyAmount::from($data['amount'])->getAmount()->isGreaterThan($outstanding)) {
            throw ValidationException::withMessages(['amount' => __('subscriptions.overpayment')]);
        }
        $paymentId = DB::table('platform_subscription_payments')->insertGetId([
            'company_id' => $companyId, 'charge_id' => $charge->id, 'operator_user_id' => $actorId,
            'amount' => $data['amount'], 'currency' => $charge->currency, 'received_on' => $data['received_on'],
            'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null, 'created_at' => now(),
        ]);
        $revision = (int) $subscription->revision + 1;
        DB::table('platform_subscriptions')->where('company_id', $companyId)->update(['revision' => $revision, 'updated_at' => now()]);

        // Recording a receipt never silently renews access or marks a different charge settled.
        return ['company_id' => $companyId, 'revision' => $revision, 'payment_id' => $paymentId];
    }

    private function charge(int $companyId, int $revision, string $plan, string $amount, string $currency, string $from, string $until): int
    {
        return DB::table('platform_subscription_charges')->insertGetId([
            'company_id' => $companyId, 'subscription_revision' => $revision,
            'plan' => $plan, 'amount' => $amount, 'currency' => $currency,
            'starts_on' => $from, 'ends_before' => $until, 'created_at' => now(),
        ]);
    }
}
