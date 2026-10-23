# Verification results and corrected baseline drift

The complete composer QA command was attempted, but multiple processes were interrupted when new user input ended their tool sessions. It is **not reported as a complete PASS**. Formatting/static checks completed; the broad test process also exposed old test drift. Raw incomplete output is retained. Verification then used completed bounded batches on disposable MariaDB, with verification-only CLI OPcache to avoid PHPUnit memory accumulating past the unchanged exporter cap. Barcode decoder libraries were local tools, not application dependencies. No production data or provider activation was used.

| Run | Actual result |
|---|---|
| `composer-qa-frozen` | INCOMPLETE; not a pass |
| `ordered-assistant-isolation-final` | OK (152 tests, 977 assertions) |
| `agy-corrections-final` | OK (50 tests, 355 assertions) |
| `start-compatibility-final` | OK (9 tests, 241 assertions) |
| `export-opcache-final` | OK (28 tests, 488 assertions) |
| `isolated-BarcodeCertificationTest` | OK (9 tests, 300 assertions) |
| `remaining-financial-regressions` | Tests: 1564, Assertions: 26655, Errors: 1, Failures: 23. |
| `phase1-purchasing-final` | Tests: 796, Assertions: 4113, Errors: 2, Failures: 1. |
| `phase1-corrected-complete` | OK (87 tests, 681 assertions) |
| `reversal-date-corrected` | OK (15 tests, 87 assertions) |
| `money-boundary-final` | OK (2 tests, 11 assertions) |
| `accessibility-corrected` | OK (4 tests, 1240 assertions) |
| `full-failure-diagnostics` | Tests: 5, Assertions: 1070, Failures: 4. |
| `catalog-corrected` | OK (13 tests, 128 assertions) |
| `phpstan-final-source` | PASS; No errors |
| `pint-final-source` | PASS; formatting |
| `build-last` | PASS; production assets built |

The purchasing batch's two errors attempted raw GL reversal of posted purchases, which the mature stock boundary correctly forbids. Corrected generic date tests use a canonical synthetic journal and preserve the refusal; all 15 cases then passed. Its one failure expected Settings after company switch; the current default is Dashboard. All corrected Phase 1 tests passed. Administrator defaults deliberately exclude protected AI permissions; the corrected test now explicitly checks those exclusions. AGY independently confirmed this baseline drift.

The financial batch's MoneyArchitectureTest falsely treated Inventory StockEventScope activation as money authority due to the shared `$scope` variable name. The test now distinguishes typed scope source and keeps exact activation allowlists; its focused rerun passed. No runtime financial code changed. The 22 later assistant failures were strict global no-write counts finding two committed invoice fixtures left by PublicationConcurrencyTest. That test now uses the existing disposable-schema helper, passes the child schema ownership proof to both worker paths, restores/drops its own schema, and asserts the primary counts are unchanged. The ordered rerun immediately following publication tests is recorded above; strict assistant no-write assertions remain unchanged. Full-run form accessibility failures were baseline party-search fields missing ID/name; attribute-only fixes on invoice, quotation and purchase preserve behavior. Four bilingual accessibility cases passed 1240 assertions. ConcurrentCapacityMeasurementTest failed once in the long process and passed independently; no production SLA inferred. Windows deployment contract tests warned where symlinks could not be created. Host symlink verification remains open.

Do not add assertion counts across overlapping runs as unique coverage. These corrections change tests/presentation, not economic semantics. Live provider quality/cost, physical-device/scanner/printer and production-host checks remain unverified. Two npm advisories remain documented. The Owner must review these limits before merge/deployment decisions.
