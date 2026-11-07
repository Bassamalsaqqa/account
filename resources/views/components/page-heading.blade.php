@props(['title', 'icon' => null])

<header {{ $attributes->class(['workspace-heading flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between']) }}>
    <div class="min-w-0 space-y-1">
        <h1 class="flex items-center gap-3 text-2xl font-bold leading-normal text-text-primary">
            @if ($icon)
                <span class="grid size-10 shrink-0 place-items-center rounded-control border border-primary/15 bg-info-bg text-primary">
                    <x-icon :name="$icon" class="size-5" aria-hidden="true" />
                </span>
            @endif
            <span class="min-w-0 break-words">{{ $title }}</span>
        </h1>
        @isset($description)
            <div class="text-sm text-text-secondary">{{ $description }}</div>
        @endisset
    </div>
    @if (trim((string) $slot) !== '')
        <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $slot }}</div>
    @endif
</header>
