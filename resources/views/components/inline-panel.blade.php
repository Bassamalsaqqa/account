@props([
    'tone' => 'neutral',
    'tag' => 'div',
    'legend' => null,
    'title' => null,
    'description' => null,
    'padding' => 'p-4 sm:p-5',
])

@php
$toneClasses = match ($tone) {
    'payment', 'info' => 'bg-primary-50/60 border-primary/25 text-text-primary',
    'success' => 'bg-success-bg border-success/25 text-text-primary',
    'warning' => 'bg-warning-bg border-warning/25 text-text-primary',
    'danger' => 'bg-danger-bg/60 border-danger/20 text-text-primary',
    default => 'bg-surface-soft border-border text-text-primary',
};
$tag = in_array($tag, ['div', 'fieldset', 'section'], true) ? $tag : 'div';
$classes = "app-inline-panel rounded-card border shadow-panel {$toneClasses} {$padding} space-y-4";
@endphp

<{{ $tag }} {{ $attributes->merge(['class' => $classes]) }}>
    @if ($tag === 'fieldset' && $legend)
        <legend class="px-2 text-base font-bold text-text-primary">
            {{ $legend }}
        </legend>
    @endif

    @if ($title || $description)
        <div class="space-y-1">
            @if ($title)
                <h3 class="text-base font-bold text-text-primary">
                    {{ $title }}
                </h3>
            @endif
            @if ($description)
                <p class="text-sm text-text-secondary">
                    {{ $description }}
                </p>
            @endif
        </div>
    @endif

    {{ $slot }}
</{{ $tag }}>
