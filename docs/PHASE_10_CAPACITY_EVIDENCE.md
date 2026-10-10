# Phase 10 local capacity evidence

Baseline `c74c9a4e0b4135055dbb8ed7d3e3715d2ddba24f`. Production runtime remains Phase9 `8d8428261cd2a10690ab77a5c7271e46b5ff5217`. Measurements use synthetic canonical Actions and proof-owned local MariaDB schemas only.

| Profile | Products / canonical events | Actual disposition |
|---|---|---|
| S | 10 / 50 | PASS; independent measurement and all six reconciliations healthy |
| M | 500 / 5,000 | PASS;1 test/4,029 assertions,16:02.958,178MiB, all six reconciliations healthy; export measurement used25-row pages as qualified below |
| L | 2,500 / 25,000 | Local measurement PASS with bounded CSV refusal: corrected serial4/39 and concurrency1/200; all six reconciliations healthy. Complete L CSV row/net/BOM verification remains NOT VERIFIED; no provider acceptance inferred |

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

The completed M CSV measurement used25-row pages, whereas the shipped `ReportCsvController` uses100. It is valid evidence for that narrower writer workload, not the controller's latency. The harness has been corrected to100-row export pages; focused S verification passed and the corrected L measurement subsequently hit the existing time limit; disposition below. The original L attempt was deliberately interrupted after this mismatch was identified, and its parent confirmed owned-schema cleanup; no result or acceptance is inferred from that attempt.

Actual M EXPLAIN plans include derived-table scans with temporary/filesort work, primary-key joins, company/status/date indexes and an account/company index merge. Representative row cardinalities and complete plans are retained privately. A scan is not itself a defect or justification for an index; no schema/index change was made.

## Measured navigation remediation

Before correction, actual S HTTP-kernel dashboard/report/PDF requests used996/507/27 queries. Independent SQL/callsite grouping identified367 unnecessary sidebar queries from enumerating all69 report definitions merely to check whether any link could be shown. A short-circuit existence check preserves existing fresh authorization and report-specific intersections. Source-link navigation now skips empty in-memory IDs before authorizing; every real lookup remains authorized.

After correction:634/105/27 queries. No request/session authorization caching, policy broadening, financial writer changes, schema/index changes or alternative ledger. Focused role-downgrade, membership revocation, inactive/foreign-company, empty source and permission-before-lookup tests passed within21 tests/1291 assertions including related view/security checks. The dashboard still runs multiple fresh protected report queries; no unmeasured optimization is claimed.

## Independent process concurrency

A separate S mix uses10 products,20 purchases,20 invoices and10 allocated receipts. Seven independent processes perform21 actual authenticated Laravel HTTP-kernel dashboard/report/invoice-PDF requests at widths1/2/4. Every response200 with required HTML/PDF signature. Width2/4 workload overlap is2331.212/2265.317ms in the recorded earlier run; actual per-operation intervals are retained. Primary/control economic fingerprints unchanged and all six reconciliations healthy. Peak child memory64MiB. Initial/warm caches differ; timings are not scaling guarantees. Opt-in M concurrent HTTP-kernel workload passed as recorded below; Corrected L concurrency subsequently passed; original failed-attempt dispositions and new results are separately recorded below. Both use separately owned auxiliary schemas and canonical5000/25000-event fixtures. Windows CPU, profile-specific lock-wait/deadlock counters, web-ingress overhead and Hostinger/LSAPI/LVE limits: NOT VERIFIED / BLOCKED EXTERNAL AUTHORIZATION. Successful responses establish no observed request failure in that window, not zero lock contention.

The independent M concurrency run subsequently passed1 test/200 assertions in15:40.728, with200MiB overall harness peak. Its distinct canonical mix is500 products,2000 purchases,2000 invoices and1000 allocated receipts. Fixture generation took782,417.442ms; measurement timestamp `2026-10-10T14:49:43Z`. All21 authenticated requests returned200 with valid HTML/PDF signatures; each worker recorded812 queries and64MiB peak. Width1/2/4 batch wall times were4281.432/4515.671/4656.570ms. Width2/4 workload overlap was4352.024/4555.201ms; actual same-operation overlap was also measured. Primary/control fingerprints remained identical and all six reconciliations healthy. Primary schema `accounting_p8_tmp_65cc59575193` and its independently owned auxiliary fixture schema were cleaned. The earlier L concurrency failed in the harness fingerprint allocation before request measurements; its corrected independent result is recorded below, without extrapolating M.

## Reproducibility and evidence

`tests/Feature/Phase10/CapacityMeasurementTest.php` defaults to S; explicitly set `PHASE10_CAPACITY_WORKLOAD=M` or `L` for larger fixtures. `ConcurrentCapacityMeasurementTest.php` separately selects `PHASE10_CONCURRENT_CAPACITY_WORKLOAD=S/M/L`; it uses committed canonical fixtures in an independently owned auxiliary schema, then proves primary-schema preservation and cleanup. Use only the owned-schema runner and a finite timeout adequate for canonical generation and reconciliation. Longer local measurements use an ignored copy retaining the same ownership guard; the accepted runner is unchanged. Generated metrics and SQL/EXPLAIN plans remain under ignored `.ai/phase10-capacity/`, with separate profile files. Tests never rewrite tracked documentation.

Observed local environment: Windows,PHP8.4.25 CLI,512M memory limit,no CLI execution-time cap,MariaDB10.4.32,64MiB packet limit,128MiB InnoDB buffer pool,strict mode. These are local process/server settings. Provider CPU/process/IO/concurrency quotas, web-handler memory/time guarantees and actual Hostinger capacity require separately authorized inspection. No production load, real data, provider access, merge or deployment occurred. All required local profiles now have actual dispositions; PERF-01 independent architect acceptance and operational acceptance are not established.

The subsequent corrected L serial and concurrent processes ended without completed summaries or observed cleanup. Those interrupted attempts are NOT VERIFIED and contribute no test/assertion result. Only those two measurements were restarted at2026-10-10T15:24Z as hidden background processes using the same fresh proof-owned schema guards and finite7200-second timeout. Those runs have now completed with the exact dispositions below; earlier interrupted attempts remain NOT VERIFIED.

## Completed L attempt dispositions — 2026-10-10

The persistent serial run completed4 tests/31 assertions with1 ERROR and zero assertion failures in01:00:17.177,378MiB. After canonical large-fixture generation, CsvReportWriter refused execution beyond its existing30-second budget. This is an observed bounded writer refusal, not proof of a production incident or successful complete export. Earlier operation metrics were not persisted before the exception; missing figures and L six-domain results remain NOT VERIFIED. Primary schema accounting_p8_tmp_659b888390e6 was confirmed cleaned.

The persistent concurrent run terminated with a512MiB fatal allocation at ConcurrentCapacityMeasurementTest::fingerprint, which accumulated all rows from24 tables and encoded one combined JSON value. This is a confirmed test-harness allocation defect; no L authenticated worker timing or completed assertion summary is accepted. Primary schema accounting_p8_tmp_92800fabec1a was confirmed cleaned. Auxiliary cleanup after the fatal termination is NOT VERIFIED. The planned bounded correction streams every ordered, company-filtered row into an incremental hash, preserving all before/after economic invariants.

CSV measurement correction must retain the100-row page size and30-second runtime limit, distinguish complete canonical CSV equality from REFUSED_TIME_LIMIT, preserve measured operations, close internal streams and run all six reconciliations. Internal partial spool bytes are not delivered bytes; existing controller regressions establish its separate fail-closed delivery behavior. Original errors remain recorded; only new focused owned-schema executions may establish corrected results. No runtime export limit or canonical writer is changed.

Reviewed automatic AGY corrections retain full-column company-filtered fingerprints across24 tables using ordered500-row hash chunks, and retain the existing CSV30-second cap while distinguishing COMPLETED from REFUSED_TIME_LIMIT. New independent S capacity/concurrency and existing writer-negative regression PASS10 tests/295 assertions,52.585s/102MiB; primary82509e194946 cleaned, all six reconciliations healthy and economic/control fingerprints identical. Only the corrected L serial/concurrent measurements were restarted18:39UTC with fresh proof-owned schemas and finite7200-second guards. Those corrected executions completed as recorded below; no previous error was rewritten as PASS.

## Corrected L measurements — independently verified 2026-10-10

Serial PASS4 tests/39 assertions,56:28.479,482MiB whole-harness PHP peak. Final status `MEASURED_WITH_LIMIT_REFUSAL`; all six reconciliations HEALTHY. Actual measurement-start timestamp2026-10-10T18:40:15Z; canonical fixture generation2,577,553.85ms. The fixed mix is2,500 products,7,500 purchases,2,000 expenses,9,000 invoices,1,000 sales returns,500 purchase returns,2,000 customer receipts,1,500 vendor payments,1,000 stock adjustments and500 employee advances:25,000 canonical events. Primary schema `accounting_p8_tmp_869d0e900e8d` cleaned.

| L serial operation | Wall time ms | Queries / observed output |
|---|---:|---|
| dashboard | 5675.20 |769;360MiB process peak at this point |
| sales summary |720.15 |21;exact net586500.000000 |
| purchase summary |602.89 |21;exact net1794000.000000 |
| money movement |14283.00 |71;25 paginated rows |
| Customer Statement |139.27 |104;25 paginated rows |
| historical AR |not captured |36 queries;9,000 invoices in18 chunks capped500 |
| historical AP |not captured |60 queries;7,500 purchases in15 chunks capped500 |
| invoice PDF |563.00 |44,102 bytes;10MiB memory delta |
| barcode PDF |564.33 |50 labels/10 distinct codes;66,376 bytes |
| CSV writer |30121.07 |`REFUSED_TIME_LIMIT`;100-row pages;40 pages attempted;128,793 internally staged bytes |

CSV refused the existing30-second limit. Complete CSV row identity/count, net equivalence and BOM verification remain **NOT VERIFIED** for L. Internal partial stream bytes are not delivered response bytes; this direct writer measurement does not establish an HTTP delivery result. Separate existing controller limit/spool/settings-cleanup regression PASS1 test/5 assertions supports its fail-closed behavior. Runtime row/byte/time caps were unchanged. Recognized refusal did not skip any of the six reconciliations.

Independent L concurrency PASS1 test/200 assertions,57:48.690,408MiB whole-harness peak; child peaks64MiB. Its distinct fixture is2,500 products,10,000 purchases,10,000 invoices and5,000 allocated receipts. Canonical generation2,695,821.388ms; request measurement timestamp2026-10-10T19:25:14Z. All21 authenticated HTTP-kernel dashboard/report/PDF responses were200 with required HTML/PDF signatures. Each worker recorded1,012 queries (dashboard880/report105/PDF27). Width1/2/4 batch times after release were7,923.853/8,712.037/10,379.261ms; width2/4 workload overlaps8,326.355/10,165.055ms, with positive measured same-operation overlaps. Full-column ordered500-row fingerprints across24 company-filtered tables remained identical for both economic and control companies; all six reconciliations HEALTHY. Primary `accounting_p8_tmp_7454961f0f19` and auxiliary `accounting_p8_tmp_dfec14db5882` cleanup independently verified absent.

**Measured limits for architect review:** L money-movement latency14.283s, dashboard5.675s and whole serial-harness peak482MiB are observed local resource findings. They are not confirmed production incidents or web-request memory limits. The two canonical fixture/QA processes shared the owned local server; these are observations, not controlled scaling or provider SLA guarantees. Full large CSV export is unproven and was safely refused. CPU, profile-specific lock/deadlock counters, real web overhead and Hostinger quotas remain NOT VERIFIED/BLOCKED EXTERNAL AUTHORIZATION. No new financial writer, schema/index, runtime limit or economic policy was introduced.

Final read-only cleanup check confirmed the three schemas from these corrected runs absent. Four earlier interrupted synthetic schemas remain in the ignored scratch datadir; they were not adopted or deleted by name. The exact task-owned PID/start time/executable/datadir/loopback33310 binding were verified before graceful shutdown at2026-10-10T19:40:05Z; process absent and port closed were subsequently verified. These retained private local fixtures are quarantined in the stopped scratch server. No persistent3306 server, protected artifact or production data was changed.
