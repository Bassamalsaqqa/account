@props(['disabled' => false])

<textarea @disabled($disabled) {{ $attributes->merge(['class' => 'min-h-20 border border-border-control bg-surface rounded-control px-3 py-2 text-base lg:text-sm text-text-primary focus:border-primary focus:ring-primary/20 disabled:bg-surface-soft disabled:text-text-muted aria-invalid:border-danger aria-invalid:bg-danger-bg/30']) }}>{{ $slot }}</textarea>
