<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Platform\PlatformAccess;
use App\Application\Platform\PlatformReauthenticationRequired;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequirePlatformBillingPassword
{
    public const string PENDING = 'platform.billing.pending';

    public function handle(Request $request, Closure $next): Response
    {
        $reconfirm = false;
        try {
            app(PlatformAccess::class)->authorize('subscription.manage');
        } catch (PlatformReauthenticationRequired) {
            $reconfirm = true;
        } catch (AuthorizationException) {
            // Existing platform middleware owns denial auditing and the restricted response.
            return $next($request);
        }
        $confirmed = $request->session()->get('auth.password_confirmed_at');
        if (! $reconfirm && is_int($confirmed) && $confirmed <= now()->timestamp && $confirmed >= now()->subMinutes(15)->timestamp) {
            return $next($request);
        }
        if ($request->expectsJson()) {
            return response()->json(['message' => __('platform.password_required')], 423);
        }

        $fields = ['request_key', 'expected_revision', 'plan', 'fee', 'currency', 'starts_on', 'renews_on',
            'grace_days', 'reminder_days', 'enforcement_enabled', 'notes', 'charge_id', 'amount', 'received_on', 'reference'];
        $input = $request->only($fields);
        // Bound session custody before validation; never retain credentials or arbitrary input.
        $input = array_filter($input, static fn (mixed $value): bool => $value === null
            || (is_scalar($value) && mb_strlen((string) $value) <= 2000)
            || (is_array($value) && count($value) <= 20 && array_filter($value, static fn ($day) => ! is_scalar($day) || strlen((string) $day) > 8) === []));
        if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 16384) {
            $input = [];
        }
        $company = (string) $request->route('publicId');
        $input['form_operation'] = (string) $request->route('operation');
        $input['form_company_public_id'] = $company;
        $request->session()->put(self::PENDING, [
            'actor' => (int) $request->user()->getAuthIdentifier(), 'company' => $company,
            'at' => now()->timestamp, 'input' => $input,
        ]);
        $request->session()->put('url.intended', route('platform.companies.subscription.show', $company));

        return redirect()->route('password.confirm');
    }
}
