<x-guest-layout>
    <div class="space-y-6">
        <div class="text-center">
            <div class="w-14 h-14 mx-auto rounded-2xl bg-primary-50 text-primary border border-primary-100 grid place-items-center">
                <x-icon name="store" class="w-7 h-7" />
            </div>
            <h1 class="text-xl font-extrabold text-text-primary tracking-tight mt-4">
                {{ __('settings.select_company') }}
            </h1>
            <p class="text-xs text-text-secondary mt-1">
                {{ __('settings.select_company_instruction') }}
            </p>
        </div>

        <div class="space-y-2.5">
            @foreach (auth()->user()->activeCompanies as $company)
                <form method="POST" action="{{ route('company.switch') }}">
                    @csrf
                    <input type="hidden" name="public_id" value="{{ $company->public_id }}" />
                    <button type="submit" class="w-full p-4 rounded-card border border-border bg-white hover:bg-surface-blue hover:border-primary transition-all text-start flex items-center justify-between group shadow-panel cursor-pointer">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-control bg-surface-soft group-hover:bg-primary-50 text-text-secondary group-hover:text-primary grid place-items-center shrink-0 border border-border">
                                <x-icon name="store" class="w-4 h-4" />
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-xs font-bold text-text-primary group-hover:text-primary truncate">
                                    {{ $company->displayName() }}
                                </h3>
                                <p class="text-[11px] text-text-muted mt-0.5">
                                    {{ $company->base_currency_code }} &bull; {{ $company->timezone }}
                                </p>
                            </div>
                        </div>
                        <span class="text-xs font-bold text-primary opacity-0 group-hover:opacity-100 transition-opacity">
                            {{ __('settings.activate') }} &rarr;
                        </span>
                    </button>
                </form>
            @endforeach
        </div>

        <div class="pt-4 border-t border-border flex items-center justify-center">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-xs font-bold text-text-muted hover:text-danger transition-colors">
                    {{ __('settings.sign_out') }}
                </button>
            </form>
        </div>
    </div>
</x-guest-layout>
