<x-app-layout>
    <div class="space-y-5">
        <header>
            <h1 class="text-2xl font-extrabold text-text-primary">{{ __('app.nav_dashboard') }}</h1>
            <p class="mt-1 text-sm text-text-secondary">{{ __('app.dashboard_phase0_desc') }}</p>
        </header>

        <section class="max-w-2xl rounded-card border border-border bg-white p-5 shadow-panel">
            <h2 class="font-bold text-text-primary">{{ __('app.phase_zero_preview') }}</h2>
            <p class="mt-2 text-sm leading-relaxed text-text-secondary">{{ __('app.dashboard_phase0_notice') }}</p>
            <a href="{{ route('settings.index') }}" class="mt-4 inline-flex min-h-10.5 items-center rounded-control bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                {{ __('app.nav_settings') }}
            </a>
        </section>
    </div>
</x-app-layout>
