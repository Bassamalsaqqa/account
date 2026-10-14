<dl class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
    @foreach ($values as $field => $value)
        @if (is_scalar($value) || $value === null)
            <div class="rounded-control bg-surface p-2">
                <dt class="text-xs text-text-secondary">{{ \Illuminate\Support\Facades\Lang::has('assistant_briefing.fields.'.$field) ? __('assistant_briefing.fields.'.$field) : (\Illuminate\Support\Facades\Lang::has('assistant_v22.fields.'.$field) ? __('assistant_v22.fields.'.$field) : (\Illuminate\Support\Facades\Lang::has('reports.totals.'.$field) ? __('reports.totals.'.$field) : (\Illuminate\Support\Facades\Lang::has('reports.columns.'.$field) ? __('reports.columns.'.$field) : str_replace('_', ' ', $field)))) }}</dt>
                <dd class="font-semibold mt-1"><bdi>{{ $value ?? __('assistant_v2.value_unavailable') }}</bdi></dd>
            </div>
        @elseif (is_array($value))
            <div class="sm:col-span-2 space-y-2">
                <p class="text-xs font-semibold">{{ \Illuminate\Support\Facades\Lang::has('assistant_briefing.fields.'.$field) ? __('assistant_briefing.fields.'.$field) : (\Illuminate\Support\Facades\Lang::has('assistant_v22.fields.'.$field) ? __('assistant_v22.fields.'.$field) : (\Illuminate\Support\Facades\Lang::has('reports.totals.'.$field) ? __('reports.totals.'.$field) : (\Illuminate\Support\Facades\Lang::has('reports.columns.'.$field) ? __('reports.columns.'.$field) : str_replace('_', ' ', $field)))) }}</p>
                @foreach ($value as $code => $amount)
                    @if (is_array($amount))
                        <p class="font-semibold"><bdi>{{ $code }}</bdi></p>
                        @include('livewire.ai.partials.turn-values', ['values' => $amount])
                    @elseif (is_scalar($amount) || $amount === null)
                        <p><bdi>{{ $code }} {{ $amount ?? __('assistant_v2.value_unavailable') }}</bdi></p>
                    @endif
                @endforeach
            </div>
        @endif
    @endforeach
</dl>
