<button {{ $attributes->merge(['type' => 'submit', 'class' => 'app-button cursor-pointer inline-flex gap-2 min-h-10.5 items-center justify-center px-4 bg-danger text-white border border-danger rounded-control font-semibold text-sm hover:bg-danger-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-danger disabled:opacity-50 transition-colors shadow-xs']) }}>
    {{ $slot }}
</button>
