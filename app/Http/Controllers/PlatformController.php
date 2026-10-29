<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Platform\PlatformAccess;
use App\Application\Platform\ProvisionCompany;
use App\Application\Platform\SubscriptionTimeline;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PlatformController extends Controller
{
    public function index(Request $request): View
    {
        app(PlatformAccess::class)->authorize('company.read');
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $search = trim($validated['q'] ?? '');
        $companies = DB::table('companies')->leftJoin('platform_company_health as health', 'health.company_id', '=', 'companies.id')
            ->select(['companies.id', 'public_id', 'name_ar', 'name_en', 'status', 'default_locale', 'base_currency_code', 'health.domains', 'health.checked_at'])
            ->selectSub(DB::table('ai_company_policies')->select('enabled')->whereColumn('ai_company_policies.company_id', 'companies.id')->limit(1), 'ai_enabled')
            ->when($search !== '', fn ($query) => $query->where(function ($q) use ($search): void {
                $q->where('name_ar', 'like', '%'.$search.'%')->orWhere('name_en', 'like', '%'.$search.'%')->orWhere('public_id', $search);
            }))->orderBy('companies.id')->paginate(20)->withQueryString();

        $billingSummary = null;
        if (in_array('subscription.manage', $request->attributes->get('platform_permissions', []), true)) {
            app(PlatformAccess::class)->authorize('subscription.manage');
            $billingSummary = ['approaching' => 0, 'grace' => 0, 'read_only' => 0, 'enforced_read_only' => 0];
            $instant = now()->toDateTimeImmutable();
            foreach (DB::table('platform_subscriptions as subscription')->join('companies', 'companies.id', '=', 'subscription.company_id')
                ->select(['subscription.renews_on', 'subscription.grace_days', 'subscription.reminder_days', 'subscription.enforcement_enabled', 'companies.timezone'])->cursor() as $row) {
                $state = app(SubscriptionTimeline::class)->evaluate($row->renews_on, $row->timezone, (int) $row->grace_days,
                    json_decode($row->reminder_days, true, 512, JSON_THROW_ON_ERROR), $instant)['state'];
                match ($state) {
                    'approaching_renewal' => $billingSummary['approaching']++,
                    'expired_in_grace' => $billingSummary['grace']++,
                    'read_only' => $billingSummary['read_only']++,
                    default => null,
                };
                if ($state === 'read_only' && $row->enforcement_enabled && config('subscriptions.enforcement_enabled', false)) {
                    $billingSummary['enforced_read_only']++;
                }
            }
        }

        return view('platform.index', compact('companies', 'search', 'billingSummary'));
    }

    public function show(string $publicId): View
    {
        app(PlatformAccess::class)->authorize('company.read');
        $company = DB::table('companies')->select(['id', 'public_id', 'name_ar', 'name_en', 'status', 'base_currency_code', 'default_locale', 'timezone'])
            ->where('public_id', $publicId)->first();
        abort_if($company === null, 404);
        $members = DB::table('company_user')->join('users', 'users.id', '=', 'company_user.user_id')
            ->where('company_user.company_id', $company->id)
            ->select(['users.id', 'users.name', 'company_user.status', 'company_user.is_owner'])->orderBy('company_user.id')->paginate(20);
        $roles = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.company_id', $company->id)->where('model_has_roles.company_id', $company->id)
            ->where('model_has_roles.model_type', User::class)->whereIn('model_has_roles.model_id', $members->pluck('id'))
            ->select(['model_has_roles.model_id', 'roles.name'])->get()->groupBy('model_id');
        foreach ($members as $member) {
            $member->role_names = $roles->get($member->id, collect())->pluck('name')->implode(', ');
        }
        $aiEnabled = (bool) DB::table('ai_company_policies')->where('company_id', $company->id)->value('enabled');
        $health = DB::table('platform_company_health')->where('company_id', $company->id)->first();

        return view('platform.show', compact('company', 'members', 'aiEnabled', 'health'));
    }

    public function create(): View
    {
        app(PlatformAccess::class)->authorize('company.provision');

        return view('platform.create', ['requestKey' => (string) Str::uuid()]);
    }

    public function store(Request $request, ProvisionCompany $provision): RedirectResponse
    {
        $company = $provision->execute($request->all());

        return redirect()->route('platform.companies.show', $company->public_id)->with('platform_success', __('platform.created'));
    }

    public function audit(): View
    {
        app(PlatformAccess::class)->authorize('audit.read');
        $events = DB::table('platform_audit_events')->select(['event_key', 'actor_user_id', 'company_id', 'created_at'])
            ->orderByDesc('id')->paginate(30);

        return view('platform.audit', compact('events'));
    }
}
