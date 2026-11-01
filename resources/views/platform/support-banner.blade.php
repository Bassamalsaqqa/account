@php($supportDetails = app(\App\Application\Platform\SupportViewContext::class)->details())
<aside role="region" aria-label="{{ __('support.title') }}" class="mb-5 rounded-xl border border-blue-200 bg-blue-50/90 p-4 text-slate-900 shadow-xs">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-start gap-3 min-w-0">
            <div class="w-8 h-8 rounded-lg bg-primary text-white grid place-items-center shrink-0 mt-0.5">
                <x-icon name="shield" class="w-4 h-4" />
            </div>
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-blue-100 text-blue-800 text-[11px] font-bold border border-blue-200">
                        {{ __('support.title') }}
                    </span>
                    <h2 class="break-words text-sm font-bold text-slate-900">
                        {{ __('support.banner', ['company' => app(\App\Support\Tenancy\CompanyContext::class)->company()->displayName()]) }}
                    </h2>
                </div>
                <p class="mt-1 text-xs sm:text-sm leading-relaxed text-slate-700">
                    {{ __('support.acting', ['operator' => $supportDetails['operator_name'], 'owner' => $supportDetails['target_name'], 'time' => \Carbon\Carbon::createFromTimestamp($supportDetails['expires_at'])->setTimezone($activeCompany->timezone)->format('H:i')]) }}
                </p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2 shrink-0">
            @if($englishEnabled || app()->getLocale() !== 'ar')
            <form method="POST" action="{{ route('platform.support.locale') }}">
                @csrf
                <input type="hidden" name="locale" value="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">
                <button class="min-h-11 min-w-11 rounded-lg border border-slate-200 bg-white px-3 text-xs sm:text-sm font-semibold text-slate-700 hover:bg-slate-50 transition-colors cursor-pointer" type="submit" aria-label="{{ __('app.switch_language') }}">
                    {{ app()->getLocale() === 'ar' ? 'EN' : 'العربية' }}
                </button>
            </form>
            @endif
            <form method="POST" action="{{ route('platform.support.end') }}">
                @csrf
                <button class="min-h-11 rounded-lg bg-primary px-4 text-xs sm:text-sm font-semibold text-white hover:bg-primary-hover shadow-xs transition-colors cursor-pointer" type="submit">
                    {{ __('support.exit') }}
                </button>
            </form>
        </div>
    </div>
</aside>
