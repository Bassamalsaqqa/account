<div class="space-y-6">
    <!-- Header with Breadcrumb and Export -->
    <header class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-2 border-b border-border">
        <div>
            <nav class="flex items-center gap-1.5 text-xs text-text-muted mb-1 font-semibold" aria-label="Breadcrumb">
                <a href="{{ route('reports.index') }}" class="touch-action hover:text-primary transition flex items-center gap-1">
                    <x-icon name="chart" class="w-3.5 h-3.5 text-primary" />
                    <span>{{ __('reports.title') }}</span>
                </a>
                <span class="rtl:rotate-180 text-text-muted/60">&rsaquo;</span>
                <span class="text-text-primary font-bold">{{ $title }}</span>
            </nav>
            <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight text-text-primary">{{ $title }}</h1>
        </div>
        @if($result)
            <div class="flex items-center gap-2">
                <a href="{{ $exportUrl }}" class="touch-action inline-flex items-center gap-2 px-3.5 py-2 bg-surface border border-border rounded-control text-sm sm:text-sm font-bold text-text-primary hover:bg-surface-soft shadow-2xs transition">
                    <x-icon name="file" class="w-4 h-4 text-primary" />
                    <span>{{ __('reports.export') }}</span>
                </a>
            </div>
        @endif
    </header>

    <!-- Filter Form -->
    <form wire:submit="applyFilters" class="rounded-xl bg-surface border border-border p-4 sm:p-5 space-y-4 shadow-2xs" aria-label="{{ __('reports.filters') }}">
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 items-end">
            @unless($definition['current'])
                <div class="flex flex-col">
                    <label for="report-preset" class="block text-sm font-bold text-text-secondary mb-1.5">{{ __('reports.preset') }}</label>
                    <select id="report-preset" wire:model.live="filters.preset" class="min-h-[44px] sm:min-h-10 w-full rounded-control border border-border bg-surface px-3 py-0 text-sm sm:text-sm box-border focus:border-primary focus:ring-1 focus:ring-primary">
                        <option value="">{{ __('reports.all') }}</option>
                        @foreach(\App\Application\Reporting\DTO\ReportPeriod::ALLOWED_PRESETS as $preset)
                            <option value="{{ $preset }}" @selected(($filters['preset'] ?? '') === $preset)>{{ __('reports.presets.'.$preset) }}</option>
                        @endforeach
                    </select>
                </div>
                @if(($filters['preset'] ?? '') === 'custom')
                    <div class="flex flex-col">
                        <label for="report-from" class="block text-sm font-bold text-text-secondary mb-1.5">{{ __('reports.from') }}</label>
                        <input id="report-from" type="date" wire:model="filters.from" value="{{ $filters['from'] ?? '' }}" class="min-h-[44px] sm:min-h-10 w-full rounded-control border border-border bg-surface px-3 py-0 text-sm sm:text-sm font-mono box-border focus:border-primary focus:ring-1 focus:ring-primary" dir="ltr" />
                        @error('filters.from')<p role="alert" class="text-sm text-danger">{{ $message }}</p>@enderror
                    </div>
                    <div class="flex flex-col">
                        <label for="report-to" class="block text-sm font-bold text-text-secondary mb-1.5">{{ __('reports.to') }}</label>
                        <input id="report-to" type="date" wire:model="filters.to" value="{{ $filters['to'] ?? '' }}" class="min-h-[44px] sm:min-h-10 w-full rounded-control border border-border bg-surface px-3 py-0 text-sm sm:text-sm font-mono box-border focus:border-primary focus:ring-1 focus:ring-primary" dir="ltr" />
                        @error('filters.to')<p role="alert" class="text-sm text-danger">{{ $message }}</p>@enderror
                    </div>
                @endif
            @endunless

            @foreach($options as $field=>$values)
                <div class="flex flex-col">
                    <label for="report-{{ $field }}" class="block text-sm font-bold text-text-secondary mb-1.5">
                        {{ __('reports.'.$field) }}@if(in_array($field,$definition['required'],true)) <span class="text-danger" aria-hidden="true">*</span>@endif
                    </label>
                    @if($field !== 'currency_code')
                        <x-report-party-selector :field="$field" :values="$values" :required="in_array($field, $definition['required'], true)" />
                    @else
                        <select id="report-{{ $field }}" wire:model="filters.{{ $field }}" class="min-h-[44px] sm:min-h-10 w-full rounded-control border border-border bg-surface px-3 py-0 text-sm sm:text-sm box-border focus:border-primary focus:ring-1 focus:ring-primary">
                            <option value="">{{ __('reports.all') }}</option>
                            @foreach($values as $option)
                                <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>
            @endforeach

            @foreach($definition['required'] as $requiredField)
                @if(!array_key_exists($requiredField, $options))
                    <p role="status" class="text-sm text-text-muted">{{ __('reports.'.$requiredField) }}: {{ __('reports.restricted') }}</p>
                @endif
            @endforeach

            @foreach($definition['options'] as $field=>$values)
                <div class="flex flex-col">
                    <label for="report-{{ $field }}" class="block text-sm font-bold text-text-secondary mb-1.5">{{ __('reports.'.$field) }}</label>
                    <select id="report-{{ $field }}" wire:model="filters.{{ $field }}" class="min-h-[44px] sm:min-h-10 w-full rounded-control border border-border bg-surface px-3 py-0 text-sm sm:text-sm box-border focus:border-primary focus:ring-1 focus:ring-primary">
                        <option value="">{{ __('reports.all') }}</option>
                        @foreach($values as $value)
                            <option value="{{ $value }}">{{ __('reports.options.'.$value) }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach

            <div class="flex flex-col">
                <label for="report-page-size" class="block text-sm font-bold text-text-secondary mb-1.5">{{ __('reports.page_size') }}</label>
                <select id="report-page-size" wire:model="filters.per_page" class="min-h-[44px] sm:min-h-10 w-full rounded-control border border-border bg-surface px-3 py-0 text-sm sm:text-sm box-border focus:border-primary focus:ring-1 focus:ring-primary">
                    @foreach(\App\Support\TablePageSize::OPTIONS as $size)
                        <option value="{{ $size }}">{{ $size }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 pt-2 border-t border-border/60">
            <button type="submit" wire:loading.attr="disabled" class="min-h-[44px] sm:min-h-10 px-4 rounded-control bg-primary text-white text-sm sm:text-sm font-bold hover:bg-primary-hover disabled:opacity-60 transition cursor-pointer shadow-xs inline-flex items-center justify-center">
                {{ __('reports.apply') }}
            </button>
            <button type="button" wire:click="resetFilters" class="min-h-[44px] sm:min-h-10 px-3.5 rounded-control border border-border bg-surface text-sm sm:text-sm font-semibold text-text-secondary hover:text-text-primary hover:bg-surface-soft transition cursor-pointer" wire:loading.attr="disabled" wire:target="resetFilters">
                {{ __('reports.reset') }}
            </button>
            <span wire:loading class="text-xs font-semibold text-text-muted flex items-center gap-1.5" role="status">
                <span class="inline-block w-3.5 h-3.5 border-2 border-primary/30 border-t-primary rounded-full animate-spin"></span>
                <span>{{ __('reports.loading') }}</span>
            </span>
        </div>
        @error('selectorSearch')<p class="text-sm text-danger" role="alert">{{ $message }}</p>@enderror
        @error('filters')<p class="text-sm text-danger font-semibold" role="alert">{{ $message }}</p>@enderror
    </form>

    @if($missing)
        <p class="rounded-xl bg-surface border border-border p-5 text-sm text-text-secondary">{{ __('reports.select_required') }}</p>
    @endif

    @if($definition['current'] && (!isset($result->meta['as_of_date']) || $result->meta['as_of_date'] === \App\Application\Reporting\DTO\ReportPeriod::fromPreset(\App\Application\Reporting\DTO\ReportPeriod::PRESET_TODAY, $company)->endDate))
        <p class="text-xs font-semibold text-text-muted">{{ __('reports.current_balance') }}</p>
    @endif

    @if($result)
        <!-- Scope & Currency Banner -->
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-xs font-semibold text-text-secondary px-1">
            <span>{{ __('reports.base_currency') }}: <bdi class="font-bold text-text-primary">{{ $company->base_currency_code }}</bdi></span>
            @if(isset($result->meta['as_of_date']))
                <span>{{ __('reports.as_of') }}: <bdi class="font-bold text-text-primary">{{ $result->meta['as_of_date'] }}</bdi></span>
            @elseif(isset($result->filters['period']['start_date']))
                <span><bdi class="font-bold text-text-primary">{{ $result->filters['period']['start_date'] }} &mdash; {{ $result->filters['period']['end_date'] }}</bdi></span>
            @endif
        </div>

        <!-- Restrained Summary Strip -->
        @if($totals)
            <section class="flex flex-wrap items-center gap-2.5 p-3 rounded-xl bg-surface-soft border border-border/80" aria-labelledby="report-summary-heading">
                <span id="report-summary-heading" class="text-xs font-extrabold uppercase tracking-wider text-text-muted me-1">{{ __('reports.summary') }}:</span>
                @foreach($totals as $total)
                    <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-surface border border-border shadow-2xs">
                        <span class="text-xs font-semibold text-text-secondary">{{ $total['label'] }}</span>
                        <span class="text-xs sm:text-sm font-extrabold text-text-primary tabular-nums">
                            <bdi dir="ltr">{{ $total['currency'] !== '' ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($total['value']) : \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($total['value']) }} {{ $total['currency'] }}</bdi>
                        </span>
                    </div>
                @endforeach
            </section>
        @endif

        <!-- Results Table -->
        <section class="rounded-xl bg-surface border border-border overflow-hidden shadow-xs">
            @if($result->rows === [])
                <p class="p-8 text-center text-sm font-semibold text-text-muted">{{ __('reports.empty') }}</p>
            @else
                <div class="hidden md:block overflow-x-auto">
                    <table class="data-table w-full text-sm text-start border-collapse">
                        <thead class="bg-surface-soft border-b border-border text-xs font-bold uppercase tracking-wider text-text-muted">
                            <tr>
                                @foreach($columns as $column)
                                    <th scope="col" class="py-3 px-4 text-start whitespace-nowrap">{{ $column['label'] }}</th>
                                @endforeach
                                <th scope="col" class="py-3 px-4 text-end whitespace-nowrap">{{ __('reports.open_source') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach($result->rows as $rowIndex=>$row)
                                <tr class="hover:bg-surface-soft/60 transition-colors">
                                    @foreach($columns as $column)
                                        <td class="py-3 px-4 {{ $column['type']==='decimal' ? 'tabular-nums whitespace-nowrap' : 'max-w-64' }}">
                                            <bdi dir="{{ in_array($column['type'], ['decimal', 'integer', 'date', 'datetime'], true) ? 'ltr' : 'auto' }}">{{ app(\App\Application\Reporting\Presentation\ReportPresenter::class)->displayValue($row,$column) ?? __('reports.unavailable') }}</bdi>
                                        </td>
                                    @endforeach
                                    <td class="table-actions py-3 px-4 text-end">
                                        <div class="inline-flex items-center gap-1">
                                            @foreach($sourceLinks[$rowIndex] ?? [] as $link)
                                                <x-row-action :href="$link['url']" :label="$link['label']" icon="eye" />
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card List -->
                <ul class="md:hidden divide-y divide-border">
                    @foreach($result->rows as $rowIndex=>$row)
                        <li class="p-4 space-y-3">
                            <dl class="grid grid-cols-2 gap-x-4 gap-y-2.5 text-xs">
                                @foreach($columns as $column)
                                    <div class="min-w-0">
                                        <dt class="text-xs font-semibold text-text-muted">{{ $column['label'] }}</dt>
                                        <dd class="mt-0.5 text-sm font-bold text-text-primary break-words {{ $column['type']==='decimal' ? 'tabular-nums' : '' }}">
                                            <bdi dir="{{ in_array($column['type'], ['decimal', 'integer', 'date', 'datetime'], true) ? 'ltr' : 'auto' }}">{{ app(\App\Application\Reporting\Presentation\ReportPresenter::class)->displayValue($row,$column) ?? __('reports.unavailable') }}</bdi>
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                            @if(!empty($sourceLinks[$rowIndex]))
                                <div class="pt-2 border-t border-border flex flex-wrap gap-2">
                                    @foreach($sourceLinks[$rowIndex] as $link)
                                        <x-row-action :href="$link['url']" :label="$link['label']" icon="eye" />
                                    @endforeach
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if($result->pagination)
                <footer class="border-t border-border px-4 py-3 flex flex-wrap items-center justify-between gap-3 text-xs bg-surface-soft/40">
                    <span class="font-semibold text-text-secondary">
                        {{ __('reports.page') }} {{ $result->pagination['current_page'] }} {{ __('reports.of') }} {{ $result->pagination['last_page'] }} &middot; {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($result->pagination['total']) }} {{ __('reports.records') }}
                    </span>
                    <div class="flex gap-2">
                        <button type="button" wire:click="goToPage({{ max(1,$result->pagination['current_page']-1) }})" wire:loading.attr="disabled" wire:target="goToPage" @disabled($result->pagination['current_page']<=1) class="touch-action h-8 min-h-[32px] rounded-control border border-border bg-surface px-3 py-0 text-sm font-bold text-text-secondary hover:text-text-primary hover:bg-surface-soft disabled:opacity-40 transition cursor-pointer">
                            {{ __('reports.previous') }}
                        </button>
                        <button type="button" wire:click="goToPage({{ $result->pagination['current_page']+1 }})" wire:loading.attr="disabled" wire:target="goToPage" @disabled($result->pagination['current_page'] >= $result->pagination['last_page']) class="touch-action h-8 min-h-[32px] rounded-control border border-border bg-surface px-3 py-0 text-sm font-bold text-text-secondary hover:text-text-primary hover:bg-surface-soft disabled:opacity-40 transition cursor-pointer">
                            {{ __('reports.next') }}
                        </button>
                    </div>
                </footer>
            @endif
        </section>
    @endif
</div>
