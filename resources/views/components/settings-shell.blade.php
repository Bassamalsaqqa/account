@props(['activeSection' => 'identity', 'company' => null])

<div class="space-y-6">

    <!-- Page Header & Breadcrumbs -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-border">
        <div>
            <!-- Breadcrumb -->
            <nav class="flex items-center gap-1.5 text-xs text-text-muted mb-1.5 font-medium" aria-label="{{ __('settings.breadcrumb_settings') }}">
                <a href="{{ route('dashboard') }}" class="hover:text-primary transition-colors">{{ __('settings.breadcrumb_dashboard') }}</a>
                <span class="text-text-muted/60">/</span>
                <a href="{{ route('settings.index') }}" class="text-text-secondary font-bold hover:text-primary transition-colors">{{ __('settings.breadcrumb_settings') }}</a>
                @if ($activeSection !== 'identity')
                    <span class="text-text-muted/60">/</span>
                    <span class="text-primary font-bold">
                        {{ $currentSectionLabel() }}
                    </span>
                @endif
            </nav>

            <!-- Title & Active Company Identity -->
            <div class="flex items-center gap-3">
                <h1 class="text-2xl sm:text-[26px] font-extrabold text-text-primary tracking-tight leading-tight">
                    {{ __('settings.title') }}
                </h1>
                @if ($company)
                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 text-text-secondary border border-border">
                        {{ $company->displayName() }}
                    </span>
                @endif
            </div>
        </div>
    </div>

    <!-- Mobile Horizontal Section Selector Pills -->
    <div class="md:hidden flex items-center gap-1.5 overflow-x-auto pb-2 border-b border-border no-scrollbar text-xs">
        @foreach ($sections as $sec)
            <a href="{{ $sec['url'] }}"
               wire:navigate
               @if ($activeSection === $sec['key']) aria-current="page" @endif
               class="px-3 py-1.5 rounded-control font-bold whitespace-nowrap shrink-0 transition-colors cursor-pointer {{ $activeSection === $sec['key'] ? 'bg-primary text-white shadow-xs' : 'bg-surface-soft text-text-secondary hover:bg-slate-200' }}">
                {{ $sec['label'] }}
            </a>
        @endforeach
    </div>

    <!-- Master Two-Column Layout (Desktop Section Index on Left, Active Content on Right) -->
    <div class="grid grid-cols-1 md:grid-cols-12 gap-6 items-start">

        <!-- Left Column: Unified Settings Section Index (Desktop Sidebar) -->
        <aside class="hidden md:block md:col-span-4 lg:col-span-3 sticky top-20 max-h-[calc(100dvh-208px)] overflow-y-auto space-y-4 shrink-0"
               x-data="accountingSettingsNavigation" x-init="$nextTick(() => restoreScroll())"
               @scroll.debounce.150ms="saveScroll()" @click.capture="saveScroll()">
            <div class="bg-white border border-border rounded-card p-3 shadow-panel">
                <div class="px-3 py-2 text-xs font-extrabold text-text-muted uppercase tracking-wider border-b border-border/60">
                    {{ __('settings.breadcrumb_settings') }}
                </div>
                <nav class="mt-2 space-y-1 text-sm font-semibold" aria-label="{{ __('settings.title') }}">
                    @foreach ($sections as $sec)
                        @php
                            $isActive = $activeSection === $sec['key'];
                            $activeClass = $isActive
                                ? 'bg-primary-50 text-primary font-bold'
                                : 'text-text-secondary hover:bg-surface-soft hover:text-text-primary';
                        @endphp

                        <a href="{{ $sec['url'] }}"
                           wire:navigate
                           @if ($isActive) aria-current="page" @endif
                           class="w-full flex items-center justify-between gap-2.5 px-3 py-2.5 rounded-control transition-colors cursor-pointer text-start {{ $activeClass }}">
                            <div class="flex items-center gap-2.5">
                                <x-icon name="{{ $sec['icon'] }}" class="w-4.5 h-4.5 shrink-0 {{ $isActive ? 'text-primary' : 'text-text-muted' }}" />
                                <span>{{ $sec['label'] }}</span>
                            </div>
                        </a>
                    @endforeach
                </nav>
            </div>
        </aside>

        <!-- Right Column: Active Section Content -->
        <div class="md:col-span-8 lg:col-span-9 min-w-0 space-y-6" wire:key="settings-pane-{{ $activeSection }}">
            {{ $slot }}
        </div>

    </div>

</div>
