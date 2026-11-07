<button {{ $attributes->merge(['type' => 'button', 'class' => 'app-button inline-flex gap-2 min-h-10.5 items-center justify-center px-4 bg-surface border border-border-strong rounded-control font-semibold text-sm text-text-secondary hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50 transition-colors shadow-xs']) }}>
    {{ $slot }}
</button>
