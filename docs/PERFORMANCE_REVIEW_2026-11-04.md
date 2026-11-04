# Performance and interface follow-up

Baseline: `8c1d04d1fa410db5e8d2f5269fa4b900fcccd67c`. Measurements use the existing isolated synthetic loopback preview, not customer production sessions. Thirty-seven authenticated routes, first request followed by three repeated requests, sequential and without concurrent load. HTTP time includes response download; it is not browser paint time. A first request is not guaranteed to be a completely cold OS cache.

## Report before implementation

| Slowest first request | First / warm average | Matching case | Location to investigate | Expected improvement |
|---|---:|---|---|---|
| Products | 6995.8 / 132.5 ms | Cold template/runtime work; no evidence for an array rewrite | `resources/views/livewire/pages/products/product-index.blade.php`; Blade compilation / local runtime | Precompilation/warming may bring first navigation toward the observed warm range; not a guaranteed 53× speedup |
| Settings | 4237.1 / 152.2 ms | Same cold/warm disparity | `resources/views/livewire/pages/settings-index.blade.php`; shared Settings shell | Toward warm range; confirm actual SQL/view breakdown before redesigning |
| Sales summary | 3950.8 / 185.4 ms | Same cold/warm disparity | `resources/views/livewire/pages/reporting/report.blade.php` | Toward warm range; do not cache financial results to disguise latency |
| Catalogs | 3661.9 / 124.1 ms | Same cold/warm disparity | `resources/views/livewire/pages/catalogs/catalog-composer.blade.php` | Toward warm range; no evidence for packed indexes or SoA |
| Purchase detail | 2869.2 / 128.9 ms | Same cold/warm disparity | `resources/views/livewire/pages/purchasing/purchase-detail.blade.php` | Toward warm range; preserve canonical document reads |

These are measured routes, not five proven independent code bottlenecks. Exact expensive view names/self-times and SQL totals are recorded separately by the metadata-only profiler. No invented line-level attribution or speedup is justified by an HTTP stopwatch alone.

The subsequent warmed metadata profile (four samples each) measured Products 130.1ms total / 41.5ms SQL / 28 queries; Catalogs 125.0ms / 28.1ms / 51 queries; Sales Summary 160.2ms / 59.1ms / 69 queries; Purchase Detail 123.0ms / 33.5ms / 46 queries. Settings pane requests measured roughly 107–141ms total with 15–40ms SQL. This does not identify a multi-second warm SQL bottleneck. The local router was restored byte-for-byte after profiling. No application query rewrite is justified by these small synthetic datasets; larger-data qualification remains necessary.

## Cache inventory and decisions

- Compiled Blade, Laravel configuration/routes and PHP OPcache avoid repeated parsing/compilation. Keep these standard runtime optimizations; they do not replace accounting truth.
- Database cache/locks also hold revocation markers, encrypted AI draft/context state, replay protection and throttling coordination. These are security/state contracts, not evidence of slow query design. Do not remove them under a blanket anti-cache rule.
- No `Cache::remember` result cache was found by the scoped application scan. No new business-result cache is proposed.
- Client draft state/quantity coalescing and deferred sharing history avoid unnecessary requests/work. Preserve their validation, retry and canonical commit boundaries.
- No measured PHP hash-table hot loop, sparse field object pass or independent sequential network await was established. AoS/SoA, packed pointer indexes and speculative parallelization do not match the evidence. First address measured work; profile larger representative data before changing query architecture.

## Interface follow-up scope

Existing shared components already cover inline validation, submit disabling, loading text, styled empty/error surfaces and dropdown transitions. Remaining source-verifiable gaps include keyboard Escape/focus restoration in the shared dropdown, absent entrance treatment in the quick-create dialog, and pressed-state consistency outside `.touch-action`. Apply shared fixes with reduced-motion support, no layout animation or new helper text. Keep real consequence/error text and financial authorization unchanged.

References: [Livewire loading states](https://livewire.laravel.com/docs/3.x/wire-loading). Owner guidance remains in `UI_WORKFLOW_RULES.md`; BoardUI/shadcn anatomy is adapted to the existing Laravel/Livewire stack, without adding React.
