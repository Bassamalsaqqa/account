# Parent recovery qualification — 7 November 2026

Baseline/result HEAD remains 06e4f6d71b8dc3202f0708dff91a21b32a5afd33. Extensive existing work preserved; no staging, commit, push or deployment yet.

## Platform concurrency acceptance corrections

The agent's server-global InnoDB counters cannot attribute waiting to the revoker under concurrent tests. Parent replaced introspection/global counters with a deliberately bounded one-second InnoDB lock timeout (error1205) on the revoker's own connection against the exact grant held by the authorized creator. The timeout transaction rolls back, the worker restores its normal timeout, and canonical revocation completes after creator release; later provisioning is denied. No privilege expansion or skip. Updated the void-return migration rollback test to include receipt migration000005 and assert command success.

Actual parent verification: PlatformConcurrencyTest and VoidPurchaseReturnTest: **20 tests / 126 assertions PASS**, 32.996s, disposable MariaDB3342 runner; owned schema cleaned. PHPStan for concurrency test/worker PASS. Selected Pint PASS.

## Harness acceptance corrections

Independent review confirmed pointer-replacement TOCTOU, signal interruption of teardown and false clean success after failed schema drop. Parent serialized pointer replacement/removal under a dedicated validated UID-owned0600 flock file (retained inode), ignored termination signals during teardown, preserved diagnostic state and removed only the owned pointer after cleanup failure, and made supervisor/cleanup report failure. No schema adoption/drop by filename. Added a two-process pointer-winner regression and actual HTTP login response check to lifecycle smoke.

Actual parent verification: **25 tests / 77 assertions PASS**, 6.657s, disposable MariaDB3342; owned primary and smoke schemas cleaned. Earlier run before race test:24/72 PASS. These counts overlap. PHPStan initially found a redundant null coalesce in the new header check; corrected without suppression, final check separately recorded.

## Release-plan corrections (authoritative over agent planning claims)

recovery-platform-and-release-plan.md contains unverified assumptions: do not use its83+5=88 table arithmetic, claim of zero downtime, automatic bootstrap flags or targeted persistent-schema rollback guidance. Migrations add columns as well as tables; inspect actual deployed migration history/schema. Use CONTROLLED_RELEASE_CHECKLIST.md: maintenance under existing deployed code BEFORE source/assets change; coherent verified off-host recovery and complete business/file fingerprints; reviewed migration scope only, bootstrap only if specifically required; preserve forward schema on rollback; six economic reconciliations and authenticated EN/AR smoke before reopening. Owner has authorized completing deployment; this does not waive correctness gates.

Read-only SSH inspection actually confirmed deployed HEAD06e4f6d71b8dc3202f0708dff91a21b32a5afd33, PHP8.4.27, real nonsymlink public root, maintenance absent. Production has existing untracked storage/framework staging directories and deploy.lock; preserved untouched. No production changes.

Composer audit: no security advisories. npm audit --omit=dev:0 vulnerabilities. JavaScript full run encountered thread resource warnings; not yet accepted as PASS.

Final parent harness PHPStan PASS after explicitly marking filesystem pointer reads impure (child processes change pointer state), validating the pointer array/slot in the race regression, and removing redundant HTTP-header null coalesce. Selected Pint PASS. JavaScript serial rerun `node --test --test-concurrency=1 tests/JavaScript/*.test.mjs`: **56 tests PASS,0fail/skip**,2.579s. Original parallel Node run was terminated after thread-resource warnings and failure to finish; no claim of parallel PASS.

Final parent harness run after explicit pointer-shape assertions: **25 tests / 79 assertions PASS**,6.071s; owned schema cleaned. Full application PHPStan PASS (no errors); repository-wide Pint `--test --max-processes=1` PASS. Production Vite build PASS with GOMAXPROCS=1 RAYON_NUM_THREADS=1 UV_THREADPOOL_SIZE=1 after unbounded/partially bounded attempts failed with OS thread limits. Build staged separately in private local temp, preserving live browser assets. No source maps. Independent accounting source review found no material defect in bounded replay/transaction scope. Browser report accepted only for its documented dialog/focus/geometry/locale coverage, not blanket100% workflow or browser network-loss proof.

Read-only readiness inspection reports deployed99tables/74migrations, expected5receipt tables and5new migration records after reviewed forward migrations, no historical void ambiguity, all six baseline reconciliation domains healthy for both companies. Exact preservation checks still required at maintenance-held release time. No bootstrap planned. No production writes, staging, commit or push yet. Full application regression remains outstanding;25harness tests qualified separately because browser session owned pointer during suite start.

## Final full-suite result and architecture correction

The final disposable application run (excluding separately verified25harness tests) completed4045tests/46369assertions with0errors/skips/incompletes and1architecture guard failure. Raw log retained in/tmp; recovery-final-full-suite.md records the failed run honestly. The purchasing architecture test intentionally forbids DB::table bypass in purchasing services. Parent preserved that guard unchanged and introduced company-scoped PurchaseConfirmationReceipt Eloquent metadata model, replacing the service's direct receipt reads/create while retaining the original transaction, encrypted consent and payload checks. Full application PHPStan passes on this final model change. Affected architecture/replay/concurrency/failure-injection/migration/settlement/refinement rerun is in progress; no blanket full-suite PASS claimed. Full npm audit including dev dependencies reports0vulnerabilities.

Publication began on release/accounting-recovery-20261107; security dependencies/notices committed separately as eada182. No push, merge or deployment yet. All earlier baseline evidence refers to06e4f6d before publication commits.

Final post-correction affected run: **76 tests / 893 assertions PASS**,2:10.882,99MB,0failures/errors/skips. Targets: unchanged PurchasePostingArchitectureTest plus original-snapshot replay, concurrency, migration preservation, immediate settlement and workflow refinement suites. Owned primary schema cleaned. Full application PHPStan re-run PASS. This resolves the sole full-run failure without relaxing its guard. Full-suite historical1failure plus successful affected rerun is the qualification record; no second full-suite execution claimed. Application/source/tests committed after this PASS; final evidence commit follows.
