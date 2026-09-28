@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'min-h-10.5 border border-border-strong bg-white rounded-control px-3 text-sm text-text-primary focus:border-primary focus:ring-primary/20 disabled:bg-surface-soft']) }}>
