@props(['activeSection' => 'identity', 'company' => null])

<div class="space-y-6">

    <!-- Page Header & Breadcrumbs -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-border">
        <div class="min-w-0">
            <!-- Breadcrumb -->
            <nav class="flex flex-wrap items-center gap-1.5 text-xs text-text-muted mb-1.5 font-medium" aria-label="{{ __('settings.breadcrumb_settings') }}">
                <a href="{{ route('dashboard') }}" class="hover:text-primary transition-colors">{{ __('settings.breadcrumb_dashboard') }}</a>
                <span class="text-text-muted/60" aria-hidden="true">/</span>
                <a href="{{ route('settings.index') }}" class="text-text-secondary font-bold hover:text-primary transition-colors">{{ __('settings.breadcrumb_settings') }}</a>
                @if ($activeSection !== 'identity')
                    <span class="text-text-muted/60" aria-hidden="true">/</span>
                    <span class="text-primary font-bold" aria-current="page">
                        {{ $currentSectionLabel() }}
                    </span>
                @endif
            </nav>

            <!-- Title & Active Company Identity -->
            <div class="flex flex-wrap items-center gap-2.5 min-w-0">
                <h1 class="text-xl md:text-2xl font-bold text-text-primary tracking-tight leading-tight min-w-0 break-words">
                    {{ __('settings.title') }}
                </h1>
                @if ($company)
                    <x-badge variant="neutral">{{ $company->displayName() }}</x-badge>
                @endif
            </div>
        </div>
    </div>

    <!-- Mobile Horizontal Section Selector Pills -->
    <nav class="md:hidden flex items-center gap-2 overflow-x-auto pb-2 border-b border-border no-scrollbar"
         aria-label="{{ __('settings.title') }}">
        @foreach ($groupedSections as $groupIndex => $group)
            @if ($groupIndex > 0)
                <span class="h-5 w-px bg-border shrink-0" aria-hidden="true"></span>
            @endif
            @foreach ($group['items'] as $sec)
                @php
                    $isMobileActive = $activeSection === $sec['key'];
                @endphp
                <a href="{{ $sec['url'] }}"
                   wire:navigate
                   @if ($isMobileActive)
                       aria-current="page"
                       x-init="const p = $el.parentElement; if (p) { p.scrollLeft = Math.max(0, $el.offsetLeft - p.clientWidth / 2 + $el.clientWidth / 2); }"
                   @endif
                   class="touch-action min-h-11 inline-flex items-center px-3.5 py-2 rounded-control border text-sm font-bold whitespace-nowrap shrink-0 transition-colors cursor-pointer {{ $isMobileActive ? 'bg-primary border-primary text-white shadow-xs' : 'bg-surface border-border text-text-secondary hover:bg-surface-soft hover:text-text-primary' }}">
                    {{ $sec['label'] }}
                </a>
            @endforeach
        @endforeach
    </nav>

    <!-- Master Two-Column Layout (Desktop Categorized Section Index, Active Content) -->
    <div class="grid grid-cols-1 md:grid-cols-12 gap-6 items-start">

        <!-- Left / Start Column: Categorized Settings Section Index (Desktop Sidebar) -->
        <aside class="hidden md:block md:col-span-4 lg:col-span-3 sticky top-20 max-h-[calc(100dvh-112px)] overflow-y-auto space-y-4 shrink-0"
               x-data="accountingSettingsNavigation" x-init="$nextTick(() => restoreScroll())"
               @scroll.debounce.150ms="saveScroll()" @click.capture="saveScroll()">
            <div class="bg-surface border border-border rounded-card p-3 shadow-panel">
                <div class="px-3 py-2 text-xs font-bold text-text-muted border-b border-border/60">
                    {{ __('settings.breadcrumb_settings') }}
                </div>
                <nav class="mt-2 space-y-3 text-sm" aria-label="{{ __('settings.title') }}">
                    @foreach ($groupedSections as $group)
                        <div class="space-y-0.5">
                            <div class="px-3 pt-1 pb-1 text-xs font-bold text-text-muted">
                                {{ $group['label'] }}
                            </div>
                            @foreach ($group['items'] as $sec)
                                @php
                                    $isActive = $activeSection === $sec['key'];
                                    $activeClass = $isActive
                                        ? 'bg-primary-50 text-primary font-bold shadow-[inset_3px_0_0_#255fd6] rtl:shadow-[inset_-3px_0_0_#255fd6]'
                                        : 'text-text-secondary font-semibold hover:bg-surface-soft hover:text-text-primary';
                                @endphp
                                <a href="{{ $sec['url'] }}"
                                   wire:navigate
                                   @if ($isActive) aria-current="page" @endif
                                   class="w-full min-h-9.5 flex items-center gap-2.5 px-3 py-2 rounded-control transition-colors cursor-pointer text-start {{ $activeClass }}">
                                    <x-icon name="{{ $sec['icon'] }}" class="w-4.5 h-4.5 shrink-0 {{ $isActive ? 'text-primary' : 'text-text-muted' }}" />
                                    <span class="min-w-0 break-words leading-snug">{{ $sec['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endforeach
                </nav>
            </div>
        </aside>

        <!-- Right / End Column: Active Section Content -->
        <div class="md:col-span-8 lg:col-span-9 min-w-0 space-y-6" wire:key="settings-pane-{{ $activeSection }}">
            {{ $slot }}
        </div>

    </div>

</div>
