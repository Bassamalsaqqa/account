<div class="space-y-6" x-data="{ query: '', matches(title) { return title.toLocaleLowerCase().includes(this.query.trim().toLocaleLowerCase()); } }">
    <header><h1 class="text-2xl font-extrabold text-text-primary">{{ __('reports.title') }}</h1><p class="mt-2 text-sm text-text-secondary max-w-2xl">{{ __('reports.subtitle') }}</p></header>
    @if ($groups !== [])
        <div class="max-w-lg">
            <label for="report-search" class="mb-2 block text-sm font-semibold">{{ __('dashboard.find_report') }}</label>
            <input id="report-search" type="search" x-model="query" placeholder="{{ __('dashboard.find_report_example') }}" class="w-full rounded-control border border-border bg-surface px-4 py-2" />
        </div>
        <p x-cloak x-show="query && ! @js(array_merge(...array_map(fn ($reports) => array_column($reports, 'title'), array_values($groups)))).some(title => matches(title))" role="status" class="rounded-card border border-border bg-surface p-5 text-sm text-text-secondary">{{ __('dashboard.no_report_match') }}</p>
    @endif
    @forelse($groups as $group => $reports)
        <section x-show="@js(array_column($reports, 'title')).some(title => matches(title))" aria-labelledby="report-group-{{ $group }}" class="rounded-card bg-white border border-border overflow-hidden">
            <h2 id="report-group-{{ $group }}" class="px-5 py-4 text-base font-bold bg-surface-soft">{{ __('reports.groups.'.$group) }}</h2>
            <ul class="grid sm:grid-cols-2 lg:grid-cols-3 divide-y divide-border">
                @foreach($reports as $report)<li x-show="matches(@js($report['title']))"><a class="flex items-center justify-between gap-4 px-5 py-4 text-sm font-semibold text-text-primary hover:bg-primary-50 focus-visible:outline-2 focus-visible:outline-primary focus-visible:outline-offset-[-2px]" href="{{ route('reports.show',['reportKey'=>$report['key']]) }}"><span>{{ $report['title'] }}</span><x-icon name="chart" class="w-4 h-4 text-primary shrink-0" /></a></li>@endforeach
            </ul>
        </section>
    @empty <div class="rounded-card bg-white border border-border p-6 text-sm text-text-secondary">{{ __('reports.restricted') }}</div> @endforelse
</div>
