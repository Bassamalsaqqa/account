# Phase 10 local capacity evidence

Baseline `c74c9a4e0b4135055dbb8ed7d3e3715d2ddba24f`. Production runtime remains Phase9 `8d8428261cd2a10690ab77a5c7271e46b5ff5217`. Measurements use synthetic canonical Actions and proof-owned local MariaDB schemas only.

| Profile | Products / canonical events | Actual disposition |
|---|---|---|
| S | 10 / 50 | PASS; independent measurement and all six reconciliations healthy |
| M | 500 / 5,000 | IN PROGRESS; initial harness exceeded the existing500-document historical-read cap, then corrected to call bounded chunks |
| L | 2,500 / 25,000 | IN PROGRESS; never inferred from S or M |

Independent corrected S plus concurrency:5 tests/277 assertions PASS,100.570s,102MiB; schema `accounting_p8_tmp_841db209da32` cleaned. The S economic mix is15 purchases,4 expenses,18 invoices,2 sales returns,1 purchase return,4 customer receipts,3 vendor payments,2 stock adjustments,1 employee advance. No direct ledger/stock inserts. The benchmark records canonical source types; this check alone is not proof against all orphan conditions. All six reconciliations are separately asserted.

Measurement timestamp: `2026-10-10T14:05:31Z`. Fixture generation: 5537.44ms. Independent runs occurred alongside other local QA; wall times are observations, not controlled provider thresholds. No production latency SLA is inferred.

| S operation | Wall time ms | Queries |
|---|---:|---:|
| dashboard | 1309.91 | 622 |
| sales_summary | 61.04 | 21 |
| purchase_summary | 63.93 | 21 |
| money_movement | 77.99 | 71 |
| customer_statement | 46.31 | 37 |
| ar_position_batching | not captured | 2 |
| ap_position_batching | not captured | 4 |
| pdf_render_invoice | 6962.49 | not captured |
| barcode_labels_render | 737.99 | not captured |
| csv_streaming | 153.23 | not captured |

CSV uses actual report fields,20 economic rows and exact net sales1179.000000 matching canonical totals. The earlier worker export used nonexistent fields; those nearly empty rows are not accepted evidence. Historical AR/AP callers now explicitly chunk at500; the runtime cap is unchanged. PDF byte/time/page and CSV row/byte refusal boundaries are tested separately; no generic128MiB memory promise is inferred from caps.

## Measured navigation remediation

Before correction, actual S HTTP-kernel dashboard/report/PDF requests used996/507/27 queries. Independent SQL/callsite grouping identified367 unnecessary sidebar queries from enumerating all69 report definitions merely to check whether any link could be shown. A short-circuit existence check preserves existing fresh authorization and report-specific intersections. Source-link navigation now skips empty in-memory IDs before authorizing; every real lookup remains authorized.

After correction:634/105/27 queries. No request/session authorization caching, policy broadening, financial writer changes, schema/index changes or alternative ledger. Focused role-downgrade, membership revocation, inactive/foreign-company, empty source and permission-before-lookup tests passed within21 tests/1291 assertions including related view/security checks. The dashboard still runs multiple fresh protected report queries; no unmeasured optimization is claimed.

## Independent process concurrency

A separate S mix uses10 products,20 purchases,20 invoices and10 allocated receipts. Seven independent processes perform21 actual authenticated Laravel HTTP-kernel dashboard/report/invoice-PDF requests at widths1/2/4. Every response200 with required HTML/PDF signature. Width2/4 workload overlap is2331.212/2265.317ms; actual per-operation intervals are retained. Primary/control economic fingerprints unchanged and all six reconciliations healthy. Peak child memory64MiB. Initial/warm caches differ; timings are not scaling guarantees. M/L concurrent HTTP-kernel workloads: NOT RUN. Windows CPU, web-ingress overhead and Hostinger/LSAPI/LVE limits: NOT VERIFIED / BLOCKED EXTERNAL AUTHORIZATION.

## Reproducibility and evidence

`tests/Feature/Phase10/CapacityMeasurementTest.php` defaults to S; explicitly set `PHASE10_CAPACITY_WORKLOAD=M` or `L` for larger fixtures. Use only the owned-schema runner and a finite timeout adequate for canonical generation and reconciliation. Longer local measurements use an ignored copy retaining the same ownership guard; the accepted runner is unchanged. Generated metrics and SQL/EXPLAIN plans remain under ignored `.ai/phase10-capacity/`, with separate profile files. Tests never rewrite tracked documentation.

Observed local environment: Windows,PHP8.4.25 CLI,512M memory limit,no CLI execution-time cap,MariaDB10.4.32,64MiB packet limit,128MiB InnoDB buffer pool,strict mode. These are local process/server settings. Provider CPU/process/IO/concurrency quotas, web-handler memory/time guarantees and actual Hostinger capacity require separately authorized inspection. No production load, real data, provider access, merge or deployment occurred. PERF-01 acceptance remains IN PROGRESS until required measurements have dispositions; operational acceptance is not established.
