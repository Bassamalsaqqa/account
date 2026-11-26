<button {{ $attributes->merge(['type' => 'button', 'class' => 'app-button cursor-pointer inline-flex gap-2 min-h-10.5 items-center justify-center px-4 bg-surface border border-border-control rounded-control font-semibold text-sm text-text-primary hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50 transition-colors shadow-xs']) }}>
    {{ $slot }}
</button>
