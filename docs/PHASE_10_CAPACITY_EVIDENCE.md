# Phase 10 local capacity evidence

Baseline `c74c9a4e0b4135055dbb8ed7d3e3715d2ddba24f`. Production runtime remains Phase9 `8d8428261cd2a10690ab77a5c7271e46b5ff5217`. Measurements use synthetic canonical Actions and proof-owned local MariaDB schemas only.

| Profile | Products / canonical events | Actual disposition |
|---|---|---|
| S | 10 / 50 | PASS; independent measurement and all six reconciliations healthy |
| M | 500 / 5,000 | PASS;1 test/4,029 assertions,16:02.958,178MiB, all six reconciliations healthy; export measurement used25-row pages as qualified below |
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

CSV uses actual report fields,20 economic rows and exact net sales1179.000000 matching canonical totals. The table above records the earlier25-row writer measurement. After matching the shipped100-row export page size and extending concurrency-profile support, a focused5-test/282-assertion run passed in74.354s/102MiB; actual S CSV took79.97ms with753 bytes and the same20 rows/exact total. Owned schema `accounting_p8_tmp_e0fb8af646b3` was cleaned. The earlier worker export used nonexistent fields; those nearly empty rows are not accepted evidence. Historical AR/AP callers now explicitly chunk at500; the runtime cap is unchanged. PDF byte/time/page and CSV row/byte refusal boundaries are tested separately; no generic128MiB memory promise is inferred from caps.

## Medium workload measurements

The completed M run used1,500 purchases,400 expenses,1,800 invoices,200 sales returns,100 purchase returns,400 customer receipts,300 vendor payments,200 stock adjustments and100 employee advances. Canonical fixture generation took779,753.62ms. Owned schema `accounting_p8_tmp_c7db7e6efeb5` was cleaned. The first M harness attempt failed because it passed more than500 sources to the existing historical-position guard; the corrected caller chunks explicitly, preserving the runtime guard.

| M operation | Wall time ms | Queries / observed output |
|---|---:|---|
| dashboard | 2785.27 | 648;130MiB process peak at this point |
| sales summary | 334.41 | 21; exact net117300.000000 |
| purchase summary | 274.21 | 21; exact net358800.000000 |
| money movement | 1024.30 | 71;25 paginated rows |
| Customer Statement | 163.02 | 104;25 paginated rows |
| historical AR | not captured | 8 queries;1,800 invoices in4 bounded chunks |
| historical AP | not captured | 12 queries;1,500 purchases in3 bounded chunks |
| invoice PDF | 512.27 | 44,102 bytes;12MiB memory delta |
| barcode PDF | 700.67 | 50 labels;66,376 bytes |
| CSV writer | 27545.94 | 2,000 rows;66,093 bytes;exact net117300.000000 |

The completed M CSV measurement used25-row pages, whereas the shipped `ReportCsvController` uses100. It is valid evidence for that narrower writer workload, not the controller's latency. The harness has been corrected to100-row export pages; focused S verification passed and the corrected L measurement is pending. The original L attempt was deliberately interrupted after this mismatch was identified, and its parent confirmed owned-schema cleanup; no result or acceptance is inferred from that attempt.

Actual M EXPLAIN plans include derived-table scans with temporary/filesort work, primary-key joins, company/status/date indexes and an account/company index merge. Representative row cardinalities and complete plans are retained privately. A scan is not itself a defect or justification for an index; no schema/index change was made.

## Measured navigation remediation

Before correction, actual S HTTP-kernel dashboard/report/PDF requests used996/507/27 queries. Independent SQL/callsite grouping identified367 unnecessary sidebar queries from enumerating all69 report definitions merely to check whether any link could be shown. A short-circuit existence check preserves existing fresh authorization and report-specific intersections. Source-link navigation now skips empty in-memory IDs before authorizing; every real lookup remains authorized.

After correction:634/105/27 queries. No request/session authorization caching, policy broadening, financial writer changes, schema/index changes or alternative ledger. Focused role-downgrade, membership revocation, inactive/foreign-company, empty source and permission-before-lookup tests passed within21 tests/1291 assertions including related view/security checks. The dashboard still runs multiple fresh protected report queries; no unmeasured optimization is claimed.

## Independent process concurrency

A separate S mix uses10 products,20 purchases,20 invoices and10 allocated receipts. Seven independent processes perform21 actual authenticated Laravel HTTP-kernel dashboard/report/invoice-PDF requests at widths1/2/4. Every response200 with required HTML/PDF signature. Width2/4 workload overlap is2331.212/2265.317ms in the recorded earlier run; actual per-operation intervals are retained. Primary/control economic fingerprints unchanged and all six reconciliations healthy. Peak child memory64MiB. Initial/warm caches differ; timings are not scaling guarantees. Opt-in M concurrent HTTP-kernel workload passed as recorded below; L remains IN PROGRESS. Both use separately owned auxiliary schemas and canonical5000/25000-event fixtures. Windows CPU, profile-specific lock-wait/deadlock counters, web-ingress overhead and Hostinger/LSAPI/LVE limits: NOT VERIFIED / BLOCKED EXTERNAL AUTHORIZATION. Successful responses establish no observed request failure in that window, not zero lock contention.

The independent M concurrency run subsequently passed1 test/200 assertions in15:40.728, with200MiB overall harness peak. Its distinct canonical mix is500 products,2000 purchases,2000 invoices and1000 allocated receipts. Fixture generation took782,417.442ms; measurement timestamp `2026-10-10T14:49:43Z`. All21 authenticated requests returned200 with valid HTML/PDF signatures; each worker recorded812 queries and64MiB peak. Width1/2/4 batch wall times were4281.432/4515.671/4656.570ms. Width2/4 workload overlap was4352.024/4555.201ms; actual same-operation overlap was also measured. Primary/control fingerprints remained identical and all six reconciliations healthy. Primary schema `accounting_p8_tmp_65cc59575193` and its independently owned auxiliary fixture schema were cleaned. L concurrency remains IN PROGRESS; no M result is extrapolated to L.

## Reproducibility and evidence

`tests/Feature/Phase10/CapacityMeasurementTest.php` defaults to S; explicitly set `PHASE10_CAPACITY_WORKLOAD=M` or `L` for larger fixtures. `ConcurrentCapacityMeasurementTest.php` separately selects `PHASE10_CONCURRENT_CAPACITY_WORKLOAD=S/M/L`; it uses committed canonical fixtures in an independently owned auxiliary schema, then proves primary-schema preservation and cleanup. Use only the owned-schema runner and a finite timeout adequate for canonical generation and reconciliation. Longer local measurements use an ignored copy retaining the same ownership guard; the accepted runner is unchanged. Generated metrics and SQL/EXPLAIN plans remain under ignored `.ai/phase10-capacity/`, with separate profile files. Tests never rewrite tracked documentation.

Observed local environment: Windows,PHP8.4.25 CLI,512M memory limit,no CLI execution-time cap,MariaDB10.4.32,64MiB packet limit,128MiB InnoDB buffer pool,strict mode. These are local process/server settings. Provider CPU/process/IO/concurrency quotas, web-handler memory/time guarantees and actual Hostinger capacity require separately authorized inspection. No production load, real data, provider access, merge or deployment occurred. PERF-01 acceptance remains IN PROGRESS until required measurements have dispositions; operational acceptance is not established.
