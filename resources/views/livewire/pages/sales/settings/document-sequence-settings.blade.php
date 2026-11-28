<x-settings-shell active-section="sequences">
    <div class="space-y-6">
        <div class="flex items-center justify-between gap-4 pb-3 border-b border-border">
            <div>
                <h2 class="text-base font-extrabold text-text-primary">
                    {{ __('sales.settings_sequences') }}
                </h2>
                <p class="text-xs text-text-secondary mt-0.5">
                    {{ __('sales.settings_sequences_desc') }}
                </p>
            </div>
        </div>

    @if (session()->has('success'))
        <div class="p-3 bg-success-bg border border-success/30 rounded-control text-success text-xs font-bold">
            {{ session('success') }}
        </div>
    @endif

    <div class="bg-surface rounded-card border border-border shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="data-table data-table--responsive w-full text-start text-xs border-collapse">
                <thead>
                    <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-xs uppercase">
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
                        <tr wire:key="doc-seq-row-{{ $idx }}" x-data="{ expanded: false }" :data-expanded="expanded ? 'true' : 'false'">
                            <td class="td-primary py-3 px-4 font-bold text-text-primary">
                                <div class="truncate">{{ __(in_array($seq['document_type'], ['purchase', 'purchase_return', 'vendor_payment'], true) ? 'purchasing.' . $seq['document_type'] . '_sequence_type' : 'sales.' . $seq['document_type']) }}</div>
                                <div class="lg:hidden font-normal text-xs text-text-secondary truncate mt-0.5">
                                    <bdi dir="ltr" class="font-mono">{{ $seq['prefix'] }}</bdi> &middot; {{ $seq['year'] ?? __('sales.all_years') }}
                                </div>
                            </td>
                            <td class="td-secondary py-3 px-4" data-label="{{ __('sales.prefix') }}">
                                <input type="text"
                                       wire:model="sequences.{{ $idx }}.prefix"
                                       class="w-24 lg:w-20 min-h-11 lg:min-h-8 lg:h-8 px-2 rounded-control border border-border-control bg-canvas text-base lg:text-sm font-mono font-bold uppercase" />
                            </td>
                            <td class="td-secondary py-3 px-4 font-mono text-text-secondary" data-label="{{ __('sales.year') }}">
                                {{ $seq['year'] ?? __('sales.all_years') }}
                            </td>
                            <td class="td-end py-3 px-4 font-mono font-bold text-text-primary">
                                <div class="inline-flex items-center gap-2">
                                    <bdi dir="ltr" class="tabular-nums">{{ $seq['next_number'] }}</bdi>
                                    <x-row-details-toggle />
                                </div>
                            </td>
                            <td class="td-secondary py-3 px-4" data-label="{{ __('sales.padding') }}">
                                <input type="number" inputmode="decimal"
                                       min="1"
                                       max="10"
                                       wire:model="sequences.{{ $idx }}.padding"
                                       class="w-20 lg:w-16 min-h-11 lg:min-h-8 lg:h-8 px-2 rounded-control border border-border-control bg-canvas text-base lg:text-sm font-mono" />
                            </td>
                            <td class="td-secondary py-3 px-4" data-label="{{ __('sales.reset_policy') }}">
                                <select wire:model="sequences.{{ $idx }}.reset_policy"
                                        class="min-h-11 lg:min-h-8 lg:h-8 px-2 rounded-control border border-border-control bg-canvas text-base lg:text-sm">
                                    <option value="yearly">{{ __('sales.yearly') }}</option>
                                    <option value="never">{{ __('sales.never') }}</option>
                                </select>
                            </td>
                            <td class="table-actions py-3 px-4 text-end">
                                <button type="button"
                                        wire:click="updateSequence({{ $idx }})"
                                        class="min-h-11 lg:min-h-8 px-3 py-1 rounded-control bg-primary text-white text-sm font-bold hover:bg-primary-hover transition-colors" wire:loading.attr="disabled" wire:target="updateSequence">
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
</x-settings-shell>
