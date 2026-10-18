# A.1 focused performance evidence

Baseline source: `e5129d50aa7e9abbdd04a21b006a87afd59b2ca1`.
Final application source: `001272d801b3c3479795de9da0cb83e8fad9f766`.

Measurements ran against the integration tree at `459e07a2d9cd74ab3a81c041e5b035d459dfa7f6` plus pending quantity/UI guard edits, before the final application commit. Subsequent selection-loading guards, whitespace/glyph fixes and positive-balance validation do not change the measured successful protocol operations. These are not measurements of the exact final committed head.

## Method and limits

Actual HTTP GET and Livewire protocol requests used a local PHP 8.4 development server and an owned disposable MariaDB 10.4 synthetic merchant fixture. No production access, provider calls or GitHub CI was involved. The request harness used normal authentication and CSRF protection. Published files exclude login credentials and local schema identifiers.

Six page routes have three sequential samples each; page comparisons below use samples 2 and 3. Five Livewire operations have one sample each. Query counts include authorization, tenancy and rendering. Response sizes are uncompressed bytes. Concurrency with local tests/builds varied, so latency differences do **not** establish an application-wide speedup, a regression, or Hostinger performance. Deterministic quantity request-count reduction is the principal demonstrated improvement.

## Quantity batching and draft save

| Measurement | Baseline | A.1 |
| --- | ---: | ---: |
| Authoritative quantity requests for 20 increments | 20 serial | 1 ordered batch |
| Confirmed quantity | 1 -> 21 | 1 -> 21 |
| Combined wall time | 9,914.49 ms | 358.71 ms |
| SQL queries | 800 | 40 |
| Request bytes | 58,459 | 3,425 |
| Response bytes | 1,390,614 | 72,236 |
| Canonical draft-save wall time | 1,141.54 ms | 410.80 ms |
| Draft-save SQL queries | 49 | 49 |
| Draft-save response bytes | 3,033 | 3,033 |

These are protocol measurements: baseline deliberately submitted 20 serial requests to apply all 20 increments, while A.1 submitted one bounded ordered batch. They are not physical-device tap timings. JavaScript tests separately verify coalescing, ordering, save/scan barriers, timeout quarantine and safe explicit recovery. Each draft save created a synthetic draft through `saveDraft`; neither posted financial or stock history.

## Representative page operations

| Route | Baseline warm mean ms | A.1 warm mean ms | SQL before -> after | Response bytes before -> after |
| --- | ---: | ---: | --- | --- |
| Dashboard | 4,701.83 | 745.60 | 337 -> 337 | 88,089 -> 88,255 |
| Invoice create | 676.58 | 1,726.15 | 38 -> 38 | 107,999 -> 110,235 |
| Purchase create | 577.28 | 464.41 | 68 -> 68 | 91,420 -> 93,906 |
| Products | 538.65 | 399.44 | 36 -> 36 | 98,038 -> 100,536 |
| Customers | 504.35 | 414.23 | 33 -> 33 | 62,650 -> 62,583 |
| Sales summary | 581.85 | 455.68 | 73 -> 73 | 68,667 -> 68,913 |

Product search, Customer search, Omnibox, Invoice product selection and Purchase camera lookup retained respectively 18, 15, 42, 30 and 75 SQL queries. Their raw times and payload sizes are in `performance-comparison.json`. Added touch markup modestly enlarges several responses; no oversized-state optimization is claimed.

## Dashboard investigation

A separately profiled warm baseline Dashboard request took 906.05 ms wall / 881.28 ms server / 308.79 ms SQL, with the same 337 queries. This large timing variation reinforces the local-load limitation above.

Repeated normalized SQL shapes included 57 fresh team-aware permission unions, 55 active membership/company checks, 19 persisted active-company reloads, 16 direct permission relationship reloads, 16 role reloads, 14 membership checks and 12 active-company checks. SQL shapes were captured without bindings; identical shapes may have different bindings.

`DashboardReports::read()` deliberately executes canonical report definitions and rechecks final authority. `ReportingGuard::readPermissions()`, `permissionNames()` and `company()` enforce fresh membership, permissions and persisted company configuration. The source already skips hidden secondary positions. No business-row N+1 was demonstrated. Caching those grants or removing provenance checks would compromise established safeguards. Consolidating a few tiny expense aggregates would save little and expand accounting-source scope; no speculative optimization was made.

AGY was asked for a read-only review of a sanitized source snapshot, but its CLI authentication expired and the review returned no findings. The diagnosis above comes from Codex's independent SQL profiling and source inspection, not an accepted AGY review.

## Preservation and reconciliations

`reconciliations-final.json` records all six canonical synthetic reconciliations healthy and unchanged table fingerprints across their read-only execution. `preview-preservation.json` records the original fixture's before/after immutable economic fingerprints preserved and all six reconciliation domains healthy before and after UI/protocol work. The owned fixture was then stopped and cleaned. These are synthetic local checks, not production reconciliation evidence.
