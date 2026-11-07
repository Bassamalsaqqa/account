@php($supportDetails = app(\App\Application\Platform\SupportViewContext::class)->details())
<aside role="region" aria-label="{{ __('support.title') }}" class="mb-5 rounded-card border border-primary-100 bg-surface-blue/90 p-4 text-text-primary shadow-panel">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-start gap-3 min-w-0">
            <div class="w-8 h-8 rounded-control bg-primary text-white grid place-items-center shrink-0 mt-0.5">
                <x-icon name="shield" class="w-4 h-4" />
            </div>
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-primary-100 text-primary-hover text-xs font-bold border border-primary-100">
                        {{ __('support.title') }}
                    </span>
                    <h2 class="break-words text-sm font-bold text-text-primary">
                        {{ __('support.banner', ['company' => app(\App\Support\Tenancy\CompanyContext::class)->company()->displayName()]) }}
                    </h2>
                </div>
                <p class="mt-1 text-xs sm:text-sm leading-relaxed text-text-secondary">
                    {{ __('support.acting', ['operator' => $supportDetails['operator_name'], 'owner' => $supportDetails['target_name'], 'time' => \Carbon\Carbon::createFromTimestamp($supportDetails['expires_at'])->setTimezone($activeCompany->timezone)->format('H:i')]) }}
                </p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2 shrink-0">
            @if($englishEnabled || app()->getLocale() !== 'ar')
            <form method="POST" action="{{ route('platform.support.locale') }}">
                @csrf
                <input type="hidden" name="locale" value="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">
                <button class="min-h-11 min-w-11 rounded-control border border-border bg-surface px-3 text-sm sm:text-sm font-semibold text-text-secondary hover:bg-surface-soft transition-colors cursor-pointer" type="submit" aria-label="{{ __('app.switch_language') }}">
                    {{ app()->getLocale() === 'ar' ? 'EN' : 'العربية' }}
                </button>
            </form>
            @endif
            <form method="POST" action="{{ route('platform.support.end') }}">
                @csrf
                <button class="min-h-11 rounded-control bg-primary px-4 text-sm sm:text-sm font-semibold text-white hover:bg-primary-hover shadow-panel transition-colors cursor-pointer" type="submit">
                    {{ __('support.exit') }}
                </button>
            </form>
        </div>
    </div>
</aside>
