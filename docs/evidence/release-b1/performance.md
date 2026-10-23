# Measured local performance

Baseline: `87e1a4bae08feddd3964f91559684613612768cf`. Final: B.1 source in the commit containing this evidence.
PHP 8.4.25, MariaDB 10.4.32, Vite production build, file sessions, PHP built-in server on loopback. Synthetic owner, two companies, twenty stocked products, one posted sale/purchase plus editable drafts. No remote provider calls. Never use these figures as production-host capacity predictions.

Three sequential authenticated GETs per page/state; discard first for displayed server-time mean. Query counts were stable across all three. Middleware outside the release measured DB query count/time and PHP wall time; browser samples measure navigation/action wall time and UTF-8 payload bytes. Browser/SQL work is synchronous on this local server. Full QA running during some final samples adds contention. No statistical significance or universal latency improvement is claimed.

| Workflow | Baseline ON queries | Final ON queries | Baseline OFF queries | Final OFF queries | ON server ms before / after | OFF server ms before / after |
|---|---:|---:|---:|---:|---:|---:|
| `/dashboard` | 422 | 421 | 343 | 329 | 1122.1 / 599.8 | 638.1 / 499.8 |
| `/invoices/create` | 93 | 92 | 44 | 30 | 327.1 / 240.0 | 238.7 / 199.3 |
| `/invoices/01M5KX7R2MHARBEES1QBSAE2CV/edit` | 157 | 156 | 78 | 60 | 439.3 / 302.2 | 258.2 / 210.8 |
| `/purchases/create` | 127 | 126 | 74 | 60 | 402.1 / 273.8 | 243.9 / 202.0 |
| `/purchases/01M5KX7QTTX1NV0T1EKHHTBGAB/edit` | 166 | 165 | 87 | 73 | 408.7 / 314.2 | 247.8 / 213.1 |
| `/products` | 120 | 119 | 41 | 27 | 384.1 / 286.9 | 236.8 / 215.8 |
| `/inventory` | 121 | 120 | 42 | 28 | 380.3 / 252.4 | 268.9 / 164.3 |
| `/money` | 304 | 303 | 225 | 211 | 412.2 / 346.0 | 326.0 / 285.0 |
| `/money/checks` | 172 | 171 | 93 | 79 | 313.3 / 272.5 | 380.4 / 213.6 |
| `/settings` | 132 | 129 | 53 | 40 | 308.2 / 253.7 | 313.2 / 200.8 |

AI OFF used the same primary company, not the empty second company. Baseline OFF still mounted AssistantLauncher and offered setup; final OFF mounted zero launchers across all ten workflows. ON preserves existing launcher. The initial synthetic policy was subsequently normalized through canonical local setup using a fake, unapproved credential for real ON/OFF UI lifecycle checks. A product was added after page measurements; later Arabic display-name repair changed only fixture masters, not economics. These fixture differences and local scheduling limit latency comparisons.

## Livewire actions

Desktop (1440px) and mobile (390px), one repeat each: see `baseline-livewire.json` / `after-livewire.json`. Product search stayed 18 queries; invoice selection stayed 31; purchase selection stayed 64. Settings identity changed 26 to 23; Omnibox changed 43 to 39. Omnibox request/response stayed 540/30511 bytes. Settings request/response changed 1629/10760 to 1652/10844 bytes: a small increase, not a payload reduction. Measured wall/server latencies vary; consult raw samples. No new wire:navigate transitions were added to transaction forms.

## Investigation and boundaries

Dashboard baseline ON repeated active-membership checks 56 times and permission-resolution queries 58 times (local SQL-shape log). Much of this is intentional fresh security validation. B.1 combines persisted company status and active membership in one indexed EXISTS in Omnibox and removes a redundant hydration authorization query; render and search still independently recheck authority. Settings loads member graphs/permissions only for relevant sections and uses overview counts. No permission, tenant, stock, balance or report cache was added. Dashboard caching was rejected without proven invalidation.

EXPLAIN results are in `reconcile-before.json` and `reconcile-after.json`: barcode/policy/membership lookups use unique/constant access; product substring search scans the small tenant dataset. No speculative index or new infrastructure was added. Broader authorization optimization requires revocation/concurrency proof and a production-shaped dataset; it remains a follow-up.

## Reproduction

Use a fresh owned MariaDB schema through existing `Tests\Support\DisposableMariaDbSchema`; migrate only that schema. Create an owner and two companies with canonical actions, units/warehouse/money account, twenty products/barcodes, a posted purchase of 50 units at 10 and sale of 2 at 15, and matching drafts. Seed existing AI policy via canonical governance/setup with fake transport, provider approval false. At baseline and release, attach local-only DB::listen and monotonic request timing middleware; do not ship it or log bindings/secrets. Login, keep locale/company/data fixed, warm each route once, record two more samples, then repeat with policy OFF. At 1440/390, record product search, selectProduct on invoice/purchase, Settings identity and Omnibox query updates using the browser network response. Capture request bytes, response bytes, query count and timing headers. Re-run before comparing different databases or hosts. Local harnesses and temporary schemas are deliberately excluded from product source.

## Company switch and barcode

`company-switch.json` contains recorded baseline switch requests (10 queries) and four final switch requests at mobile/desktop widths (9 queries); all returned 302 before following the dashboard redirect. `browser-lifecycles.json` records the final company ON/OFF mount state. These switch request timings exclude the redirected dashboard; separate browser wall observations included it.

`barcode-final.json` records software invocation of the existing camera-code handler, not a physical scan: product/invoice/purchase selection returned 200 and `ok=true`, with 21/43/75 queries and 706/2784/2568-byte requests. No isolated before/after speed claim is made for this handler. Existing software decoder certification passed all five symbologies with local verification-only PyMuPDF/zxing libraries. Physical camera, USB and printer certification remains outstanding.
