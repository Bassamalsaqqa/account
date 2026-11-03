<div class="space-y-5" x-data="{
    activeGroup: 'all',
    query: '',
    matches(report) {
        const matchesGroup = this.activeGroup === 'all' || report.group === this.activeGroup;
        const q = this.query.trim().toLocaleLowerCase();
        const matchesQuery = !q || report.title.toLocaleLowerCase().includes(q) || report.groupTitle.toLocaleLowerCase().includes(q) || report.key.toLocaleLowerCase().includes(q);
        return matchesGroup && matchesQuery;
    }
}">
    <!-- Header -->
    <header class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-2 border-b border-border">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight text-text-primary">{{ __('reports.title') }}</h1>
            <p class="mt-1 text-xs sm:text-sm text-text-secondary max-w-2xl">{{ __('reports.subtitle') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-surface-soft border border-border text-text-secondary">
                <x-icon name="chart" class="w-3.5 h-3.5 text-primary" />
                <span>{{ array_sum(array_map('count', $groups)) }} {{ __('reports.records') }}</span>
            </span>
        </div>
    </header>

    @if ($groups !== [])
        <!-- Search & Category Filters Toolbar -->
        <div class="space-y-3">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
                <!-- Search input -->
                <div class="relative w-full md:max-w-md">
                    <x-icon name="search" class="w-4 h-4 absolute top-1/2 -translate-y-1/2 start-3 text-text-muted pointer-events-none" />
                    <input id="report-search"
                           type="search"
                           x-model="query"
                           placeholder="{{ __('dashboard.find_report_example') }}"
                           aria-label="{{ __('dashboard.find_report') }}"
                           class="w-full h-10 min-h-[40px] max-h-[40px] rounded-control border border-border bg-white ps-9 pe-4 text-xs sm:text-sm focus:border-primary focus:ring-1 focus:ring-primary shadow-2xs box-border" />
                </div>

                <!-- Reset filter action -->
                <div class="flex items-center gap-2" x-cloak x-show="activeGroup !== 'all' || query.trim()">
                    <button type="button"
                            @click="activeGroup = 'all'; query = '';"
                            class="inline-flex items-center gap-1 text-xs font-bold text-text-secondary hover:text-primary transition cursor-pointer">
                        <x-icon name="close" class="w-3.5 h-3.5" />
                        <span>{{ __('reports.reset') }}</span>
                    </button>
                </div>
            </div>

            <!-- Group Filter Tabs -->
            <div class="flex items-center gap-1.5 overflow-x-auto pb-1 no-scrollbar" role="tablist" aria-label="{{ __('reports.groups.sales') }}">
                <button type="button"
                        role="tab"
                        :aria-selected="activeGroup === 'all' ? 'true' : 'false'"
                        @click="activeGroup = 'all'"
                        :class="activeGroup === 'all' ? 'bg-primary text-white shadow-xs font-extrabold' : 'bg-white border border-border text-text-secondary hover:bg-surface-soft hover:text-text-primary font-bold'"
                        class="h-9 min-h-[36px] max-h-[36px] px-3.5 rounded-control text-xs whitespace-nowrap transition cursor-pointer shrink-0 box-border">
                    {{ __('reports.all') }}
                    <span class="ms-1.5 opacity-75">({{ array_sum(array_map('count', $groups)) }})</span>
                </button>

                @foreach ($groups as $group => $reports)
                    <button type="button"
                            role="tab"
                            :aria-selected="activeGroup === '{{ $group }}' ? 'true' : 'false'"
                            @click="activeGroup = '{{ $group }}'"
                            :class="activeGroup === '{{ $group }}' ? 'bg-primary text-white shadow-xs font-extrabold' : 'bg-white border border-border text-text-secondary hover:bg-surface-soft hover:text-text-primary font-bold'"
                            class="h-9 min-h-[36px] max-h-[36px] px-3.5 rounded-control text-xs whitespace-nowrap transition cursor-pointer shrink-0 box-border">
                        {{ __('reports.groups.'.$group) }}
                        <span class="ms-1.5 opacity-75">({{ count($reports) }})</span>
                    </button>
                @endforeach
            </div>
        </div>

        @php
            $groupIcons = [
                'sales' => 'receipt',
                'customers' => 'users',
                'purchases' => 'cart',
                'vendors' => 'truck',
                'inventory' => 'box',
                'money' => 'bank',
                'expenses' => 'money',
                'payroll' => 'users',
                'profit' => 'chart',
            ];
            $groupColors = [
                'sales' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                'customers' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
                'purchases' => 'bg-blue-50 text-blue-700 border-blue-200',
                'vendors' => 'bg-sky-50 text-sky-700 border-sky-200',
                'inventory' => 'bg-amber-50 text-amber-700 border-amber-200',
                'money' => 'bg-teal-50 text-teal-700 border-teal-200',
                'expenses' => 'bg-rose-50 text-rose-700 border-rose-200',
                'payroll' => 'bg-purple-50 text-purple-700 border-purple-200',
                'profit' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            ];
        @endphp

        <!-- Dense Report Workspace Table -->
        <div class="bg-white border border-border rounded-xl shadow-xs overflow-hidden">
            <!-- Desktop Table View -->
            <div class="hidden md:block overflow-x-auto">
                <table class="data-table w-full text-xs text-start border-collapse">
                    <thead class="bg-surface-soft border-b border-border text-[11px] font-extrabold uppercase tracking-wider text-text-muted select-none">
                        <tr>
                            <th scope="col" class="py-3 px-4 text-start">{{ __('reports.title') }}</th>
                            <th scope="col" class="py-3 px-4 text-start w-36">{{ __('reports.category_id') }}</th>
                            <th scope="col" class="py-3 px-4 text-start w-48 font-mono">{{ __('reports.options.code_asc') ?? 'Key' }}</th>
                            <th scope="col" class="py-3 px-4 text-end w-20"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($groups as $group => $reports)
                            @php
                                $groupIcon = $groupIcons[$group] ?? 'chart';
                                $groupBadgeColor = $groupColors[$group] ?? 'bg-surface-soft text-text-secondary border-border';
                            @endphp
                            @foreach ($reports as $report)
                                <tr x-show="matches({ group: '{{ $group }}', title: @js($report['title']), groupTitle: @js(__('reports.groups.'.$group)), key: '{{ $report['key'] }}' })"
                                    class="hover:bg-surface-soft/60 transition-colors group">
                                    <td class="py-3 px-4">
                                        <a href="{{ route('reports.show', ['reportKey' => $report['key']]) }}"
                                           class="touch-action font-bold text-xs sm:text-sm text-text-primary group-hover:text-primary transition flex items-center gap-2.5">
                                            <div class="w-7 h-7 rounded-lg bg-surface-soft group-hover:bg-primary-50 group-hover:text-primary text-text-muted grid place-items-center transition shrink-0">
                                                <x-icon :name="$groupIcon" class="w-3.5 h-3.5" />
                                            </div>
                                            <span>{{ $report['title'] }}</span>
                                        </a>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border {{ $groupBadgeColor }}">
                                            {{ __('reports.groups.'.$group) }}
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 font-mono text-[11px] text-text-muted dir-ltr">
                                        {{ $report['key'] }}
                                    </td>
                                    <td class="py-3 px-4 text-end">
                                        <a href="{{ route('reports.show', ['reportKey' => $report['key']]) }}"
                                           title="{{ __('reports.show') ?? 'View' }}"
                                           class="touch-action inline-flex items-center justify-center w-8 h-8 rounded-control border border-border/80 bg-white hover:bg-surface-soft text-text-secondary hover:text-primary transition shadow-2xs">
                                            <x-icon name="chevron" class="w-3.5 h-3.5 rtl:rotate-180" />
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Mobile Compact List View -->
            <ul class="md:hidden divide-y divide-border">
                @foreach ($groups as $group => $reports)
                    @php
                        $groupIcon = $groupIcons[$group] ?? 'chart';
                        $groupBadgeColor = $groupColors[$group] ?? 'bg-surface-soft text-text-secondary border-border';
                    @endphp
                    @foreach ($reports as $report)
                        <li x-show="matches({ group: '{{ $group }}', title: @js($report['title']), groupTitle: @js(__('reports.groups.'.$group)), key: '{{ $report['key'] }}' })">
                            <a href="{{ route('reports.show', ['reportKey' => $report['key']]) }}"
                               class="touch-action p-3.5 flex items-center justify-between gap-3 hover:bg-surface-soft/60 transition">
                                <div class="flex items-center gap-3 min-w-0">
                                    <div class="w-8 h-8 rounded-lg bg-surface-soft text-text-muted grid place-items-center shrink-0">
                                        <x-icon :name="$groupIcon" class="w-4 h-4 text-primary" />
                                    </div>
                                    <div class="min-w-0">
                                        <div class="font-bold text-xs sm:text-sm text-text-primary truncate">{{ $report['title'] }}</div>
                                        <div class="flex items-center gap-2 mt-0.5">
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold border {{ $groupBadgeColor }}">
                                                {{ __('reports.groups.'.$group) }}
                                            </span>
                                            <span class="font-mono text-[10px] text-text-muted dir-ltr">{{ $report['key'] }}</span>
                                        </div>
                                    </div>
                                </div>
                                <x-icon name="chevron" class="w-4 h-4 text-text-muted shrink-0 rtl:rotate-180" />
                            </a>
                        </li>
                    @endforeach
                @endforeach
            </ul>
        </div>

        <!-- No Match Found Notice -->
        <div x-cloak
             x-show="@js(array_merge(...array_map(fn ($r, $g) => array_map(fn ($item) => ['group' => $g, 'title' => $item['title'], 'groupTitle' => __('reports.groups.'.$g), 'key' => $item['key']], $r), array_values($groups), array_keys($groups)))).every(rep => !matches(rep))"
             class="rounded-xl border border-border bg-white p-8 text-center space-y-3">
            <x-icon name="search" class="w-8 h-8 text-text-muted mx-auto opacity-50" />
            <p class="text-sm font-bold text-text-primary">{{ __('dashboard.no_report_match') }}</p>
            <button type="button"
                    @click="activeGroup = 'all'; query = '';"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-control bg-surface-soft hover:bg-surface-muted text-xs font-bold text-text-primary border border-border transition cursor-pointer">
                <span>{{ __('reports.reset') }}</span>
            </button>
        </div>
    @else
        <div class="rounded-xl bg-white border border-border p-8 text-center text-sm text-text-secondary">
            {{ __('reports.restricted') }}
        </div>
    @endif
</div>
