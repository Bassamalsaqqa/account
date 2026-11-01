@php($supportDetails = app(\App\Application\Platform\SupportViewContext::class)->details())
<section role="region" aria-label="{{ __('support.title') }}" class="mb-5 rounded-card border border-primary/30 bg-primary-50 p-4 text-text-primary">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <h2 class="break-words text-sm font-bold">{{ __('support.banner', ['company' => $supportDetails['company_name']]) }}</h2>
            <p class="mt-1 text-sm leading-relaxed">{{ __('support.acting', ['operator' => $supportDetails['operator_name'], 'owner' => $supportDetails['target_name'], 'time' => \Carbon\Carbon::createFromTimestamp($supportDetails['expires_at'])->setTimezone($activeCompany->timezone)->format('H:i')]) }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if($englishEnabled || app()->getLocale() !== 'ar')
            <form method="POST" action="{{ route('platform.support.locale') }}">
                @csrf
                <input type="hidden" name="locale" value="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">
                <button class="min-h-11 min-w-11 rounded-control border border-border bg-white px-3 text-sm font-semibold" type="submit" aria-label="{{ __('app.switch_language') }}">{{ app()->getLocale() === 'ar' ? 'EN' : 'العربية' }}</button>
            </form>
            @endif
            <form method="POST" action="{{ route('platform.support.end') }}">
                @csrf
                <button class="min-h-11 rounded-control bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" type="submit">{{ __('support.exit') }}</button>
            </form>
        </div>
    </div>
</section>
