@props(['title', 'icon' => null])

<header {{ $attributes->class(['workspace-heading flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between']) }}>
    <div class="min-w-0 space-y-1">
        <h1 class="text-2xl font-bold leading-normal text-text-primary min-w-0 break-words">{{ $title }}</h1>
        @isset($description)
            <div class="text-sm text-text-secondary">{{ $description }}</div>
        @endisset
    </div>
    @if (trim((string) $slot) !== '')
        <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $slot }}</div>
    @endif
</header>
