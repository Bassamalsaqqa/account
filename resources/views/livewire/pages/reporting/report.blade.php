<div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-4"><div><a href="{{ route('reports.index') }}" class="text-sm text-primary font-semibold hover:underline">{{ __('reports.title') }}</a><h1 class="mt-2 text-2xl font-extrabold">{{ $title }}</h1></div>@if($result)<a href="{{ $exportUrl }}" class="inline-flex items-center gap-2 px-4 py-2.5 bg-white border border-border rounded-control text-sm font-bold hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-primary"><x-icon name="file" class="w-4 h-4" />{{ __('reports.export') }}</a>@endif</header>
    <form wire:submit="applyFilters" class="rounded-card bg-white border border-border p-4 sm:p-5 space-y-4" aria-label="{{ __('reports.filters') }}">
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        @unless($definition['current'])
            <div><label for="report-preset" class="block text-xs font-bold mb-1.5">{{ __('reports.preset') }}</label><select id="report-preset" wire:model="filters.preset" class="w-full rounded-control border-border text-sm"><option value="">{{ __('reports.all') }}</option>@foreach(\App\Application\Reporting\DTO\ReportPeriod::ALLOWED_PRESETS as $preset)<option value="{{ $preset }}" @selected(($filters['preset'] ?? '') === $preset)>{{ __('reports.presets.'.$preset) }}</option>@endforeach</select></div>
            @if(($filters['preset'] ?? '') === 'custom')
                <div><label for="report-from" class="block text-xs font-bold mb-1.5">{{ __('reports.from') }}</label><input id="report-from" type="date" wire:model="filters.from" value="{{ $filters['from'] ?? '' }}" class="w-full rounded-control border-border text-sm" /></div>
                <div><label for="report-to" class="block text-xs font-bold mb-1.5">{{ __('reports.to') }}</label><input id="report-to" type="date" wire:model="filters.to" value="{{ $filters['to'] ?? '' }}" class="w-full rounded-control border-border text-sm" /></div>
            @endif
        @endunless
        @foreach($options as $field=>$values)
            <div>
                <label for="report-{{ $field }}" class="block text-xs font-bold mb-1.5">{{ __('reports.'.$field) }}@if(in_array($field,$definition['required'],true)) <span aria-hidden="true">*</span>@endif</label>
                @if($field !== 'currency_code')
                    <div class="space-y-1.5">
                        <input type="text"
                               wire:model.live.debounce.300ms="selectorSearch.{{ $field }}"
                               placeholder="{{ __('reports.search') }}"
                               class="w-full rounded-control border-border text-xs px-2.5 py-1.5 bg-surface-soft"
                               aria-label="{{ __('reports.search') }} {{ __('reports.'.$field) }}" />
                        <select id="report-{{ $field }}" wire:model="filters.{{ $field }}" class="w-full rounded-control border-border text-sm">
                            <option value="">{{ __('reports.all') }}</option>
                            @foreach($values as $option)
                                <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @else
                    <select id="report-{{ $field }}" wire:model="filters.{{ $field }}" class="w-full rounded-control border-border text-sm">
                        <option value="">{{ __('reports.all') }}</option>
                        @foreach($values as $option)
                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                @endif
            </div>
        @endforeach
        @foreach($definition['options'] as $field=>$values)<div><label for="report-{{ $field }}" class="block text-xs font-bold mb-1.5">{{ __('reports.'.$field) }}</label><select id="report-{{ $field }}" wire:model="filters.{{ $field }}" class="w-full rounded-control border-border text-sm"><option value="">{{ __('reports.all') }}</option>@foreach($values as $value)<option value="{{ $value }}">{{ __('reports.options.'.$value) }}</option>@endforeach</select></div>@endforeach
        <div><label for="report-page-size" class="block text-xs font-bold mb-1.5">{{ __('reports.page_size') }}</label><select id="report-page-size" wire:model="filters.per_page" class="w-full rounded-control border-border text-sm">@foreach([25,50,100] as $size)<option value="{{ $size }}">{{ $size }}</option>@endforeach</select></div>
        </div>
        <div class="flex flex-wrap items-center gap-3"><button type="submit" wire:loading.attr="disabled" class="px-4 py-2 rounded-control bg-primary text-white text-sm font-bold hover:bg-primary-700 disabled:opacity-60 focus-visible:outline-2 focus-visible:outline-primary focus-visible:outline-offset-2">{{ __('reports.apply') }}</button><button type="button" wire:click="resetFilters" class="px-3 py-2 text-sm font-semibold text-text-secondary hover:text-primary">{{ __('reports.reset') }}</button><span wire:loading class="text-xs text-text-secondary" role="status">{{ __('reports.loading') }}</span></div>
        @error('filters')<p class="text-sm text-danger" role="alert">{{ $message }}</p>@enderror
    </form>
    @if($missing)<p class="rounded-card bg-white border border-border p-5 text-sm text-text-secondary">{{ __('reports.select_required') }}</p>@endif
    @if($definition['current'] && (!isset($result->meta['as_of_date']) || $result->meta['as_of_date'] === \App\Application\Reporting\DTO\ReportPeriod::fromPreset(\App\Application\Reporting\DTO\ReportPeriod::PRESET_TODAY, $company)->endDate))<p class="text-xs text-text-secondary">{{ __('reports.current_balance') }}</p>@endif
    @if($result)
        <div class="flex flex-wrap gap-x-6 gap-y-2 text-xs text-text-secondary"><span>{{ __('reports.base_currency') }}: <bdi>{{ $company->base_currency_code }}</bdi></span>@if(isset($result->meta['as_of_date']))<span>{{ __('reports.as_of') }}: <bdi>{{ $result->meta['as_of_date'] }}</bdi></span>@elseif(isset($result->filters['period']['start_date']))<span><bdi>{{ $result->filters['period']['start_date'] }} — {{ $result->filters['period']['end_date'] }}</bdi></span>@endif</div>
        @if($totals)<section class="rounded-card bg-white border border-border p-5"><h2 class="font-bold text-sm mb-4">{{ __('reports.summary') }}</h2><dl class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-x-6 gap-y-4">@foreach($totals as $total)<div><dt class="text-xs text-text-secondary">{{ $total['label'] }}</dt><dd class="mt-1 text-sm font-bold tabular-nums"><bdi>{{ $total['value'] }} {{ $total['currency'] }}</bdi></dd></div>@endforeach</dl></section>@endif
        <section class="rounded-card bg-white border border-border overflow-hidden">
            @if($result->rows === [])<p class="p-8 text-center text-sm text-text-secondary">{{ __('reports.empty') }}</p>@else
            <div class="hidden md:block overflow-x-auto"><table class="w-full text-sm text-start"><thead class="bg-surface-soft text-xs text-text-secondary"><tr>@foreach($columns as $column)<th scope="col" class="px-4 py-3 text-start whitespace-nowrap font-bold">{{ $column['label'] }}</th>@endforeach<th scope="col" class="px-4 py-3 text-start">{{ __('reports.open_source') }}</th></tr></thead><tbody class="divide-y divide-border">@foreach($result->rows as $rowIndex=>$row)<tr class="hover:bg-surface-soft/50">@foreach($columns as $column)<td class="px-4 py-3 {{ $column['type']==='decimal' ? 'tabular-nums whitespace-nowrap' : 'max-w-64' }}">{{ app(\App\Application\Reporting\Presentation\ReportPresenter::class)->value($row,$column['key']) ?? __('reports.unavailable') }}</td>@endforeach<td class="px-4 py-3">@foreach($sourceLinks[$rowIndex] ?? [] as $link)<a href="{{ $link['url'] }}" class="block text-xs text-primary font-semibold hover:underline">{{ $link['label'] }}</a>@endforeach</td></tr>@endforeach</tbody></table></div>
            <ul class="md:hidden divide-y divide-border">@foreach($result->rows as $rowIndex=>$row)<li class="p-4"><dl class="grid grid-cols-2 gap-x-4 gap-y-3">@foreach($columns as $column)<div class="min-w-0"><dt class="text-[11px] text-text-secondary">{{ $column['label'] }}</dt><dd class="mt-1 text-sm font-semibold break-words {{ $column['type']==='decimal' ? 'tabular-nums' : '' }}">{{ app(\App\Application\Reporting\Presentation\ReportPresenter::class)->value($row,$column['key']) ?? __('reports.unavailable') }}</dd></div>@endforeach</dl>@foreach($sourceLinks[$rowIndex] ?? [] as $link)<a href="{{ $link['url'] }}" class="inline-block mt-3 me-4 text-xs text-primary font-semibold hover:underline">{{ $link['label'] }}</a>@endforeach</li>@endforeach</ul>
            @endif
            @if($result->pagination)<footer class="border-t border-border px-4 py-3 flex flex-wrap items-center justify-between gap-3 text-xs"><span>{{ __('reports.page') }} {{ $result->pagination['current_page'] }} {{ __('reports.of') }} {{ $result->pagination['last_page'] }} · {{ $result->pagination['total'] }} {{ __('reports.records') }}</span><div class="flex gap-2"><button type="button" wire:click="goToPage({{ max(1,$result->pagination['current_page']-1) }})" @disabled($result->pagination['current_page']<=1) class="rounded-control border border-border px-3 py-2 font-semibold disabled:opacity-40">{{ __('reports.previous') }}</button><button type="button" wire:click="goToPage({{ $result->pagination['current_page']+1 }})" @disabled($result->pagination['current_page'] >= $result->pagination['last_page']) class="rounded-control border border-border px-3 py-2 font-semibold disabled:opacity-40">{{ __('reports.next') }}</button></div></footer>@endif
        </section>
    @endif
</div>
