<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Platform\ManageSubscription;
use App\Application\Platform\PlatformAccess;
use App\Application\Platform\SubscriptionTimeline;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Http\Middleware\RequirePlatformBillingPassword;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PlatformSubscriptionController extends Controller
{
    public function show(Request $request, string $publicId): View
    {
        app(PlatformAccess::class)->authorize('subscription.manage');
        $pending = $request->session()->get(RequirePlatformBillingPassword::PENDING);
        if (is_array($pending) && ($pending['actor'] ?? null) === (int) $request->user()->getAuthIdentifier()
            && ($pending['company'] ?? null) === $publicId) {
            $request->session()->forget(RequirePlatformBillingPassword::PENDING);
            if (is_int($pending['at'] ?? null) && $pending['at'] <= now()->timestamp && $pending['at'] >= now()->subMinutes(15)->timestamp && is_array($pending['input'] ?? null)) {
                $request->session()->flashInput($pending['input']);
            }
        }

        $company = DB::table('companies')
            ->select(['id', 'public_id', 'name_ar', 'name_en', 'status', 'base_currency_code', 'default_locale', 'timezone'])
            ->where('public_id', $publicId)
            ->first();
        abort_if($company === null, 404);

        $subscription = DB::table('platform_subscriptions')->where('company_id', $company->id)->first();

        $reminderDays = [];
        if ($subscription !== null && is_string($subscription->reminder_days)) {
            $decoded = json_decode($subscription->reminder_days, true);
            if (is_array($decoded)) {
                $reminderDays = array_values(array_map('intval', $decoded));
            }
        }
        if ($reminderDays === []) {
            $reminderDays = (array) config('subscriptions.reminder_days', [14, 7, 1]);
        }

        $timeline = null;
        if ($subscription !== null) {
            $timeline = (new SubscriptionTimeline)->evaluate(
                $subscription->renews_on,
                $company->timezone,
                (int) $subscription->grace_days,
                $reminderDays,
                now()->toDateTimeImmutable()
            );
        }

        $globalEnforcement = (bool) config('subscriptions.enforcement_enabled', false);
        $companyEnforcement = (bool) ($subscription->enforcement_enabled ?? false);
        $effectiveEnforcement = $globalEnforcement && $companyEnforcement;

        $charges = DB::table('platform_subscription_charges')
            ->where('company_id', $company->id)
            ->orderByDesc('id')
            ->paginate(15);

        $payments = DB::table('platform_subscription_payments')
            ->where('company_id', $company->id)
            ->whereIn('charge_id', $charges->pluck('id'))
            ->orderByDesc('id')
            ->paginate(30, ['*'], 'receipts_page')->withQueryString();
        $paymentsByCharge = $payments->getCollection()->groupBy('charge_id');
        $paidByCharge = DB::table('platform_subscription_payments')
            ->where('company_id', $company->id)->whereIn('charge_id', $charges->pluck('id'))
            ->selectRaw('charge_id, SUM(amount) AS paid')->groupBy('charge_id')->pluck('paid', 'charge_id');

        $outstandingCharges = [];
        foreach ($charges as $charge) {
            $paidDecimal = MoneyAmount::from((string) $paidByCharge->get($charge->id, '0'));
            $charge->paid = (string) $paidDecimal;
            $charge->outstanding = (string) MoneyAmount::from((string) $charge->amount)->getAmount()->minus($paidDecimal->getAmount());
            $charge->is_settled = MoneyAmount::from($charge->outstanding)->getAmount()->isZero();
            if (! $charge->is_settled) {
                $outstandingCharges[] = $charge;
            }
        }

        $requestKey = (string) Str::uuid();

        return view('platform.subscriptions.show', compact(
            'company',
            'subscription',
            'timeline',
            'globalEnforcement',
            'companyEnforcement',
            'effectiveEnforcement',
            'charges',
            'payments',
            'paymentsByCharge',
            'outstandingCharges',
            'requestKey',
            'reminderDays'
        ));
    }

    public function update(Request $request, string $publicId, string $operation, ManageSubscription $manageSubscription): RedirectResponse
    {
        app(PlatformAccess::class)->authorize('subscription.manage');
        abort_unless(in_array($operation, ['configure', 'renew', 'payment'], true), 404);
        $request->merge(['form_operation' => $operation, 'form_company_public_id' => $publicId]);

        $input = $request->all();
        if ($operation === 'configure' && isset($input['reminder_days']) && is_string($input['reminder_days'])) {
            // Preserve invalid tokens for authoritative validation instead of silently
            // changing "14oops, 0" into a different, apparently valid policy.
            $input['reminder_days'] = array_map('trim', explode(',', $input['reminder_days']));
        }

        $manageSubscription->execute($publicId, $operation, $input);

        $message = match ($operation) {
            'configure' => __('subscriptions.configured_success'),
            'renew' => __('subscriptions.renewed_success'),
            'payment' => __('subscriptions.payment_success'),
        };

        return redirect()
            ->route('platform.companies.subscription.show', $publicId)
            ->with('platform_success', $message);
    }
}
