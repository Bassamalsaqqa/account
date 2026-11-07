# Huda runtime release qualification — 2026-11-07

Status: **QUALIFICATION COMPLETED WITH FAILURES — final patched-vendor/source qualification remains with parent.**

Baseline HEAD: `06e4f6d71b8dc3202f0708dff91a21b32a5afd33`. Existing dirty changes preserved. User-space MariaDB provisioning succeeded without sudo, privilege/sandbox changes, global install, Docker, persistent application DB or production access. Runtime: PHP 8.4.27, MariaDB 11.8.8, dedicated loopback port 3342. See [reproducible setup and preserved-server handoff](huda-runtime-setup.md).

## Focused results, before parent return-loop follow-up

Command: documented disposable runner with `APP_ENV=testing`, explicit `PHASE8_*` test settings, and timeout 14400; targets VoidPurchaseTest, VoidPurchaseReturnTest, PurchaseCorrectionTest, Phase5E/VendorPaymentReconciliationAndAuditTest.

Actual result: **62 tests, 320 assertions, 1 failure**, exit 1, 01:01.381. Owned schema `accounting_p8_tmp_94673c9b89a3` cleaned by runner.

Failure: `VoidPurchaseReturnTest::test_successful_purchase_return_void_restores_stock_and_accounting`, line 113: Payables incorrectly remained healthy after raw corruption removed the original return batch's reciprocal `reversed_by_batch_id`. The valid void's Inventory, Accounting and Payables healthy assertions earlier in that same test passed. Diagnosis: `PayablesReconciliationService` return audit loop selected posted returns only, leaving its `isVoid()`/PurchaseVoidIntegrity branch unreachable. Parent subsequently changed this loop to include posted and void. This passing/failing focused result predates that follow-up and does not qualify the corrected line.

Focused Payables source SHA256: `520a55530ea26643109b38f4dd94c7c70625b093e86baea216cc75af3ff533c2`. After parent follow-up observed SHA256: `65c2d8e910abd23577e5116405873bdcc10b7f5a31b4cdbe6370e4fd267617b3`.

Log: `huda-runtime-focused.log`.

## New compound six-service regression

Only owned test change: `tests/Feature/Purchasing/ReleaseReconciliationTest.php`.

Canonical fixture includes purchase, posted child return, dedicated vendor payment, unrelated posted service invoice, operating expense and salary recognition. It asserts healthy Accounting, Inventory, Sales, Payables, Money and Phase7 reports with empty finding collections before correction, after atomic edit correction (source + child void, dedicated payment reversal, replacement draft), and after idempotent receipt replay. Every reconciliation group is surrounded by fingerprints of all persisted table rows. Unrelated document attributes remain identical; receipt replay must leave all persisted rows identical.

Actual final focused result: **1 test, 49 assertions, pass**, exit 0, 00:04.678. Owned schema `accounting_p8_tmp_b712f19ff830` cleaned. Pint and PHP syntax check passed. An initial fixture run failed because it compared freshly hydrated rows to unsaved in-memory attribute representations (missing default columns/decimal normalization); Huda fixed the fixture to snapshot fresh persisted attributes and reran successfully. This was a fixture issue, not an application defect. No application source was changed by Huda.

Log: `huda-runtime-six-reconciliation.log` (final successful fixture run). This pass also predates parent's return-loop follow-up; reciprocal corruption is covered by parent's test, not this healthy fixture.

## Full suite / dependency sequencing

Started full `-- tests` using the documented disposable runner and 14400 timeout, before user requested no additional PHP test processes and before the parent return-loop follow-up. PHPUnit discovered 4000 tests. This process is being allowed to finish as explicitly instructed. No further PHP test processes will be started by Huda. Parent will update Symfony to 8.1.11 and qualify final dependencies/source in a new run after terminal handoff. Do not infer final-source acceptance from a process that may have loaded Payables before the parent one-line follow-up.

Actual result: **4000 tests, 44,998 assertions, 4 errors, 36 failures**, exit **2**, **57:13.558**, peak memory **314.50 MB**. No skipped/risky/incomplete totals were reported. Primary schema `accounting_p8_tmp_0bd074953861` cleaned by runner. The full run reproduced the pre-follow-up return reciprocal-corruption failure, confirming it had loaded the earlier Payables implementation; do not treat this as a failure of the parent's corrected iteration. No further PHP test process was started after the user sequencing instruction.

Logs: `huda-runtime-full-php-suite.log` and compact `huda-runtime-failure-index.log`. ANSI codes and large rendered HTML bodies containing transient session payloads were removed; test identities, expected strings, stacks and totals retained. Structured results: `huda-runtime-result.json`. Composer lock SHA256 throughout this pre-update run: `ec35d103aa9548d2b04e0b057dbce6dd8203afa0f9b6bd32c1b74e9711d082e6`. Relevant pre-update versions include symfony/http-foundation and http-kernel v8.1.7; no dependency mutation by Huda.

### Actual errors to triage

1. `ManagedSaas/PlatformBillingConcurrencyTest::test_old_repeatable_read_snapshot_cannot_hide_a_committed_receipt`: MariaDB error 1020 (record changed since last read) / Laravel DeadlockException on locking `platform_subscriptions` in auxiliary schema. Requires qualification of transaction retry behavior on MariaDB 11.8; no blanket application defect claim from this runtime result.
2. `ManagedSaas/PlatformConcurrencyTest::test_authorized_transaction_finishes_before_waiting_revocation_and_later_work_is_denied`: PROCESS privilege required for `information_schema.INNODB_LOCK_WAITS` / INNODB_TRX. Documented prefix-only runner grants do not permit it. No privilege expansion performed.
3. `Refinement/HumanWorkflowRefinementTest::test_purchase_confirm_creates_one_canonical_receipt_and_retries_do_not_duplicate_it`: ImmutableRecordException, "Only persisted Purchase drafts are mutable." Retry of `PurchaseForm::saveAndConfirm` reaches `UpdatePurchaseDraftAction.php:27` and `Purchase.php:269` after the first call posted successfully. Parent should inspect the retry path.
4. `Support/DisposableMariaDbSchemaTest::test_preserves_sentinel_in_independently_owned_test_schema`: access denied creating `accounting_p8_sentinel_*`. This test deliberately uses a prefix outside the documented `accounting_p8_tmp_%` grants. No sentinel schema was created and no privilege expansion performed.

### Actual failures grouped for parent

| Count | Tests | Observed evidence / implication |
| --- | --- | --- |
| 1 | BusinessWorkflow/QuickProductReceiveTest | Existing-draft quantity expected `7.000000`, got `7`; representation assertion. |
| 14 | CompanyDataExport limits/export/reauthentication | Four configured row/byte-limit cases instead report memory-limit exceeded; ten export/reauth cases return 422 instead of 200. Export service checks absolute `memory_get_usage(true)` against a default 128 MiB cap (config/company_data_export.php:34); full-suite process grew to 314.50 MiB. Suite accumulation/memory-budget interaction is indicated; isolated retest not run under the user's stop instruction. |
| 2 | ManagedSaas PlatformAccess / PlatformFinalSecurityContract | Challenge HTML lacks expected `livewire.min.js` substring. Synthetic denied-audit failure response contains the word `password` in a 1,039,183-byte debug HTML page; assertion is a string match, not evidence of an actual credential value. Parent should distinguish expected assets/error-rendering contract from actual secret disclosure. |
| 3 | Phase10/BarcodeCertificationTest | Decoder exits 127: `exec: python: not found`. `python3` exists; `python` command does not. Runtime tooling prerequisite. |
| 2 | Phase5E/VendorPaymentFinalInputChronologyRegressionTest (USD/JOD) | First assertion at line 53 chooses wrong money-account ID despite foreign account sort_order -1; expected/actual IDs differ. Parent inspect default-account policy vs test contract. |
| 1 | Purchasing/VoidPurchaseReturnTest | Known missing reciprocal corruption detection, preceding parent's posted+void return-loop fix. |
| 13 | Unit/ProductExperience/DeploymentScriptContractTest | Synthetic shell fixtures encounter `awk: command not found` at deploy.sh lines 217/273; expected success/failure paths and exit codes diverge. These tests used synthetic directories/stubs, not a live deployment. Install/provide normal user-space awk before final qualification. |

No failures/errors were listed for ReleaseReconciliationTest or the other three requested focused classes. The new compound fixture passed both its standalone run and the full-suite pass through its class (no named failure/error); only the standalone count is separately attributable (49 assertions).

## Safety / handoff

No children, account switching, dependency changes, application source edits, other test edits, commits, push, merge, deployment, Hostinger or production access. Only the assigned test and uniquely Huda-prefixed release evidence written. The owned MariaDB server and directory are deliberately preserved for the parent's patched-vendor run per explicit user request. Runner-owned schemas are cleaned by their runners; no other agent schema cleanup is authorized. Server teardown instructions are in huda-runtime-setup.md.


## Final owned-runtime state

Observed after full-suite exit: PID **3597**, user **t3dev**, command line matches `/tmp/huda-runtime-9dx5swDe` data directory and bind `127.0.0.1:3342`. No remaining test-client connection. Main runner cleanup succeeded. One auxiliary `accounting_p8_tmp_2f13bbe960c8` remained; it was created during this run on the newly initialized, exclusively Huda-provisioned server (no pre-existing schemas), and Huda dropped that exact owned schema. Final `SHOW DATABASES`: only information_schema, mysql, performance_schema, sys. No wildcard cleanup or other server access.

The server and extracted package/data directory remain intentionally live for parent handoff. PID may change on a later restart: always verify the command line and private PID file before stopping. Do not reinitialize preserved data. Current direct restart command:

```bash
/tmp/huda-runtime-9dx5swDe/root/usr/sbin/mariadbd --no-defaults --basedir=/tmp/huda-runtime-9dx5swDe/root/usr --datadir=/tmp/huda-runtime-9dx5swDe/data --socket=/tmp/huda-runtime-9dx5swDe/server.sock --pid-file=/tmp/huda-runtime-9dx5swDe/server.pid --port=3342 --bind-address=127.0.0.1 --skip-name-resolve --log-error=/tmp/huda-runtime-9dx5swDe/server.log --innodb-buffer-pool-size=256M
```

Safe eventual stop: verify `ps -p "$(cat /tmp/huda-runtime-9dx5swDe/server.pid)" -o args=` points to this data directory, send TERM to that exact PID, and wait for shutdown before deleting only `/tmp/huda-runtime-9dx5swDe`. Do not stop it until the parent finishes subsequent tests. No test_user password secret is required: test_runner@127.0.0.1 intentionally has an empty password and schema-prefix-only grants.

Resulting HEAD remains `06e4f6d71b8dc3202f0708dff91a21b32a5afd33`. No application source, dependencies, other tests or shared evidence changed by Huda. No static-analysis/full-build/audit or additional migration qualification was claimed; build/browser/security are other owners. No merge, deployment or production access occurred.
