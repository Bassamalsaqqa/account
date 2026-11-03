<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex min-h-10.5 items-center justify-center px-4 bg-danger text-white border border-danger rounded-control font-semibold text-sm hover:bg-danger/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-danger disabled:opacity-50 transition-colors']) }}>
    {{ $slot }}
</button>
