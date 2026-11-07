<button {{ $attributes->merge(['type' => 'submit', 'class' => 'app-button inline-flex gap-2 min-h-10.5 items-center justify-center px-4 bg-primary border border-primary rounded-control font-semibold text-sm text-white hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50 transition-colors shadow-xs']) }}>
    {{ $slot }}
</button>
