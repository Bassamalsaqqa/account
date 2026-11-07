@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'min-h-11 lg:min-h-9.5 border border-border-strong bg-surface rounded-control px-3 text-sm text-text-primary focus:border-primary focus:ring-primary/20 disabled:bg-surface-soft disabled:text-text-muted aria-invalid:border-danger aria-invalid:bg-danger-bg/30']) }}>
