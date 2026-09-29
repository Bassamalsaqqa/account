<x-guest-layout>
    <div class="space-y-6 text-center">
        <div class="w-14 h-14 mx-auto rounded-2xl bg-amber-50 text-amber-600 border border-amber-200 grid place-items-center">
            <x-icon name="store" class="w-7 h-7" />
        </div>

        <div>
            <h1 class="text-xl font-extrabold text-text-primary tracking-tight">
                {{ __('settings.no_active_company') }}
            </h1>
            <p class="text-xs text-text-secondary mt-2 max-w-sm mx-auto leading-relaxed">
                {{ __('settings.no_company_bootstrap_notice') }}
            </p>
        </div>

        <div class="pt-4 border-t border-border flex items-center justify-center gap-4">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="px-4 py-2 text-xs font-bold text-text-secondary hover:text-danger rounded-control border border-border bg-white transition-colors">
                    {{ __('settings.sign_out') }}
                </button>
            </form>
        </div>
    </div>
</x-guest-layout>
