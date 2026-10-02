<div class="max-w-4xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-text-primary">
                {{ __('sales.settings_sequences') }}
            </h1>
            <p class="text-xs text-text-secondary mt-1">
                {{ __('sales.settings_sequences_desc') }}
            </p>
        </div>

        <a href="{{ route('settings.index') }}"
           class="px-3 py-1.5 rounded-control bg-surface-soft text-text-secondary hover:text-text-primary text-xs font-bold transition-colors">
            {{ __('sales.back') }}
        </a>
    </div>

    @if (session()->has('success'))
        <div class="p-3 bg-success-bg border border-success/30 rounded-control text-success text-xs font-bold">
            {{ session('success') }}
        </div>
    @endif

    <div class="bg-white rounded-card border border-border shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-start text-xs border-collapse">
                <thead>
                    <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-[11px] uppercase">
                        <th class="py-3 px-4 text-start">{{ __('sales.document_type') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.prefix') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.year') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.next_number') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.padding') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.reset_policy') }}</th>
                        <th class="py-3 px-4 text-end">{{ __('sales.action') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($sequences as $idx => $seq)
                        <tr>
                            <td class="py-3 px-4 font-bold text-text-primary">
                                {{ __('sales.' . $seq['document_type']) }}
                            </td>
                            <td class="py-3 px-4">
                                <input type="text"
                                       wire:model="sequences.{{ $idx }}.prefix"
                                       class="w-20 h-8 px-2 rounded-control border border-border bg-canvas text-xs font-mono font-bold uppercase" />
                            </td>
                            <td class="py-3 px-4 font-mono text-text-secondary">
                                {{ $seq['year'] ?? __('sales.all_years') }}
                            </td>
                            <td class="py-3 px-4 font-mono font-bold text-text-primary">
                                {{ $seq['next_number'] }}
                            </td>
                            <td class="py-3 px-4">
                                <input type="number"
                                       min="1"
                                       max="10"
                                       wire:model="sequences.{{ $idx }}.padding"
                                       class="w-16 h-8 px-2 rounded-control border border-border bg-canvas text-xs font-mono" />
                            </td>
                            <td class="py-3 px-4">
                                <select wire:model="sequences.{{ $idx }}.reset_policy"
                                        class="h-8 px-2 rounded-control border border-border bg-canvas text-xs">
                                    <option value="yearly">{{ __('sales.yearly') }}</option>
                                    <option value="never">{{ __('sales.never') }}</option>
                                </select>
                            </td>
                            <td class="py-3 px-4 text-end">
                                <button type="button"
                                        wire:click="updateSequence({{ $idx }})"
                                        class="px-3 py-1 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors">
                                    {{ __('sales.save') }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-6 text-center text-text-muted">
                                {{ __('sales.no_sequences') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
