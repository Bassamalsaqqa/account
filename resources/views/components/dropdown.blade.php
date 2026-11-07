@props(['align' => 'right', 'width' => '48', 'contentClasses' => 'py-1 bg-surface'])

@php
$alignmentClasses = match ($align) {
    'left' => 'ltr:origin-top-left rtl:origin-top-right start-0',
    'top' => 'origin-top',
    default => 'ltr:origin-top-right rtl:origin-top-left end-0',
};

$width = match ($width) {
    '48' => 'w-48',
    default => $width,
};
@endphp

<div class="relative" x-data="accountingDropdown" @click.outside="close(false)" @close.stop="close(false)"
     @keydown="handleKeydown($event)">
    <div x-ref="trigger" @click="toggle()">
        {{ $trigger }}
    </div>

    <div x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="absolute z-50 mt-2 {{ $width }} rounded-card shadow-overlay {{ $alignmentClasses }}"
            style="display: none;"
            @click="close(false)">
        <div class="rounded-card border border-border {{ $contentClasses }}">
            {{ $content }}
        </div>
    </div>
</div>
