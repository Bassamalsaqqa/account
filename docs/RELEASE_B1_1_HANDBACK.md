# Accounting B.1.1 corrective release candidate

Proprietary - All Rights Reserved. Third-party notices remain applicable.

**Decision: GO FOR INDEPENDENT REVIEW.** The complete source gate and focused checks passed on committed source (3,450 tests / 41,668 assertions, exit 0). Baseline/deployed B.1: `183b7074790bb6f1a25d8223116be4514055e405`. Corrective source under test: `82f58d3f26b94531f12bd5f9acb77e1a459f78f0`, tree `dd7e200d3ac29b8594d144ad5d3da71c6dfaf683`, [draft PR #61](https://github.com/Bassamalsaqqa/account/pull/61). No merge/deployment is authorized or performed by this correction. External independent acceptance remains pending.

Branch: `fix/release-b1-1-stabilization-20261025`. The licensing follow-up is separate: `docs/laravel-provenance-20261025` at `ef9105201e6272e931ef7f83f6d43f76ebd69f1c`, [draft PR #60](https://github.com/Bassamalsaqqa/account/pull/60).

## Findings and verification

| Item | Finding / action | Verified | Remaining |
|---|---|---|---|
| Omnibox accessibility | Replace this modal's Alpine asynchronous sibling-inert trap with browser-owned native dialog; guard delayed opens and teardown, restore accessible trigger focus after local dismissal. | New regression suite: baseline 5 failures / 2 passes; correction 7 passes. All 39 JavaScript tests pass. Local Edge: repeated EN/AR Escape, search-result morph, separate-company empty results, navigation Back/Forward, result navigation, outside click, Ctrl+K, native Tab traversal and responsive layout. Complete source QA (3,450 passed, 4 warnings, exit 0). | Real mobile Safari/Chrome, screen readers, physical keyboard/scanner lifecycle and live Owner acceptance. |
| AI error handling (#35) | No new defect found: safe categories distinguish authentication, credit, timeout, rate limit, outage, format/parameters and invalid response; unknown reasons remain generic. Operator diagnostics allowlist provider/stage/status without raw response, prompt or credentials. Recommend issue closure after review, without changing historical requests/reservations. | Existing mocked transport/adapter and AR/EN diagnostic tests passed in focused and full gates. No provider calls. | Unknown historical charges remain unresolved, separate authorized provider/account review. |
| Licensing provenance (#57) | Separate [draft PR #60](https://github.com/Bassamalsaqqa/account/pull/60) retains upstream MIT notice, documents exact comparisons and corrects inherited root package identity. Proprietary policy unchanged. | Root LICENSE/README unchanged; package versions and lock data unchanged except metadata content hash; Composer validate passes. | Independent provenance/legal review where needed; no exclusive upstream ownership asserted. |
| GitHub documentation | #59 is current B.1 record. #43/#37/#34/#32/#29/#23 are earlier release records, not current release instructions. Preserve unique historical evidence; propose closure rather than merging stale records, after Owner accepts current documentation. | #59 additions are documentation-only and bounded privacy scan found no private markers. Eight original Linux failures are retained, superseded by isolated-fixture 21 tests / 147 assertions, exit 0. No script/assertion changes. | Owner merge/closure decisions; no PR/issue autonomously closed. |
| Physical-device acceptance | Desktop browser exercise is not real-device certification. | Local Edge on synthetic data; requested viewport sizes map to actual client widths 417/836/1583 under browser zoom, no horizontal overflow observed. | Owner's iPhone/Android/desktop checklist below. |
| Company / AI interaction | Search authorization, membership, company binding and policy boundaries unchanged. | Focused search isolation, master switch and deterministic AI-OFF concurrency regressions pass. Separate-company search browser check passed. | Owner live OFF/ON with password, unsaved form retention, physical lifecycle. |
| Backup / email operations | Existing B.1 evidence proves SQL restore fingerprints and separate key custody, not full restored application boot or actual reset-email delivery. Plain backups are Owner-selected, not encrypted. | PR #59's existing 91-table restore result and preservation receipt reviewed; no new confidential restore/email performed. | Separately authorize isolated full application recovery and real mail delivery; decide off-host access controls/custody. |
| Performance | Dashboard has largest measured TTFB; source can execute multiple canonical report reads, with fresh authorization. This is an attribution hypothesis, not a proven query defect. No performance implementation. | Existing authenticated serial baseline: Dashboard 493.4 ms, invoice create 194.3 ms, purchase create 218.5 ms; Omnibox 109.8-140.8 ms. | SQL/PHP/paint attribution unavailable. Proposed profiler requires separate approval; benefit remains unquantified. |

## Verification gates and complete QA

- **Complete `composer qa` (attempt `qa-07`)**: **PASS, exit 0**. Duration: 6,427.49s (~107 min).
  - Pint: 1,193 files checked, 0 style violations.
  - PHPStan: 651 files analysed, 0 errors.
  - PHPUnit: **3,450 tests passed, 41,668 assertions, 4 warnings, 0 failures**.
  - Four Windows warnings: `Tests\Unit\ProductExperience\DeploymentScriptContractTest` symlink permissions (`symlink(): Permission denied`) on Windows host without elevated symlink privilege. Those four symlink scenarios are not certified by the Windows run; prior Linux fixture evidence is recorded in PR #59.
  - Schema custody: Dedicated disposable MariaDB 10.4.32 on loopback port 3318. The owned disposable test schema was completely removed after execution.
  - Owned primary TEST-schema counts unchanged (0 across all 10 core tables before and after). These are not snapshots of the persistent development database; the wrapper explicitly selects a fresh isolated schema and ownership capability.
  - Synthetic six-domain reconciliation before and after QA: **all six domains healthy** (`accounting: true`, `inventory: true`, `sales: true`, `money: true`, `payables: true`, `phase7: true`). Economic fingerprints unchanged.
- **Focused PHP gate**: **148 tests / 814 assertions, exit 0**.
- **Frontend gate**: **39 JavaScript unit/regression tests passed**, production build passed cleanly (`public/build` manifest verified).
- **Security audits**: Composer audit exit 0 (0 vulnerabilities); npm audit exit 0 (0 vulnerabilities).
- **Sanitized complete evidence archive**: [qa-output-sanitized.zip](evidence/release-b1/qa-output-sanitized.zip) (255,102 bytes). Contains sanitized full logs for all attempts, original SHA-256 hashes, receipts, security audit outputs, and browser observations. Codex decoded and scanned every archive member for workstation paths and credential markers, and checked original-log hashes and receipt consistency. No findings; this bounded review is not an all-history secret certification.

### Historical attempt log

| Attempt | Mode | Tested HEAD | Outcome / Findings |
|---|---|---|---|
| `qa-01` | Focused PHP (148 tests / 814 assertions) | `183b707...` plus uncommitted Omnibox correction | Exit 0; patch was subsequently committed as `ad76598`. |
| `qa-02` | Complete `composer qa` | `ad76598...` | Exit 1: 3,449 passed, one failure, four warnings, 41,668 assertions. Old empty untracked root language folders violated the existing directory assertion; preserved privately. |
| `qa-03` | Focused translation (6 tests / 100 assertions) | `ad76598...` | Exit 0 after empty-directory preservation; source/assertions unchanged. |
| `qa-04` | Complete `composer qa` | `ad76598...` | Exit 1: 3,449 passed, one failure, four warnings, 41,661 assertions. Baseline fixed-date legacy-share fixture expired against the real clock. |
| `qa-05` | Focused expiry/translation (17 tests / 196 assertions) | `ad76598...` plus uncommitted test-clock correction | Exit 0; patch was subsequently committed as `82f58d3`. No assertion or production expiry behavior changed. |
| `qa-06` | Complete `composer qa` | `82f58d3...` | Interrupted by verified machine restart; Windows process/scheduler result 1073807364. No complete exit, totals or post-run checks certified. Exact owned schema cleaned during recovery; original partial output retained. |
| `qa-07` | Complete `composer qa` | `82f58d3...` | **PASS, exit 0**. 3,450 passed, 41,668 assertions, four Windows warnings. Six reconciliations healthy, schema cleaned. |

## Source and local preservation review

- **Tracked tree**: Clean against HEAD `82f58d3f26b94531f12bd5f9acb77e1a459f78f0` (`git diff HEAD --binary` is empty).
- **Private local files**: All 40 original private files remain in place with hashes independently rechecked against the private inventory. The separate translation stash/patch remain preserved. Two verified empty legacy language folders were moved into private custody; no file was deleted or incorporated.
- **Privacy review**: Full sanitization performed by `package-evidence.py` across all attempts and audits. Codex found no workstation-path or credential markers in the packaged archive; no confidential recovery materials or customer data are included. Historical repository exposures previously documented in B.1 were not rewritten.
- **Browser observations**: Local Edge manual evaluation on synthetic data confirmed accessible modal behavior: EN/AR repeated Escape focus restoration, search-response morphing, separate-company empty results, outside click dismiss, Ctrl+K and native Tab traversal, product navigation with Back/Forward, and responsive modal layout across viewports (417px, 836px, 1583px). Browser and router servers terminated cleanly.

## Independent Codex source acceptance

Codex inspected the combined four-file source diff and surrounding Omnibox authorization/lifecycle code, then independently checked AGY's actual untracked documents, archive contents and the original QA output/receipts. No runtime PHP, financial writer, migration, dependency, AI policy or licensing change belongs to PR #61. The fourth file only freezes the legacy-share test clock; teardown restores it and every expiry assertion remains. Search membership/company checks are unchanged. The final evidence commit adds documentation only; the exact fully tested source is `82f58d3f26b94531f12bd5f9acb77e1a459f78f0`. Resolve the final documentation commit from PR #61's head before acceptance or deployment; it must have the same application/test tree.

Verdict: **GO FOR INDEPENDENT REVIEW**, with external approval pending. Recommend approval of documentation-only PR #59 as the current B.1 record, subject to Owner review; its prior Omnibox limitation is addressed by this still-unmerged candidate. No automated closure/merge is proposed as an executed action. Keep deployment on HOLD until explicit authorization and the operational gates below. Physical-device, screen-reader, real camera/scanner and live password-confirmed AI transitions are unverified. One contended local browser startup exceeded PHP's autoload time limit; a private launcher allowance permitted the checks without source/production configuration changes. No performance conclusion is drawn from that run.

## Owner acceptance steps

1. On real iPhone, Android and desktop, open Dashboard, Sales, Purchasing, Products and Settings in Arabic and English. Check RTL/LTR, scrolling, long amounts/names, decimal quantity entry, cancel, legitimate saving and Back/Forward/tab resume. Record device/browser and result; Codex creates no live transactions.
2. Test camera grant/deny, repeated barcode scans, external scanner, cancel/resume and focus; require no duplicated lines or stale listeners.
3. Open Omnibox by trigger and shortcut, type/search, cycle Tab, Escape, outside click and result navigation repeatedly. Require focus on an accessible trigger after dismissal and no lingering hidden/inert page. Include slow/offline request recovery and company switching.
4. Switch authorized companies with different permissions. Require fresh company results, cost redaction and correct document context; test explicit handling of unsaved manual values.
5. In an authorized company, retain unsaved manual form entries; confirm AI OFF/ON with Owner password, without asking a question or testing provider health. Verify launcher removal/reappearance, company binding and unchanged manual values. OFF rejects new grants/dispatch; external work authorized earlier can still complete or charge. Late output cannot revive revoked drafts or create financial history. Do not activate providers or incur test charges.

## Performance recommendation

First measure Dashboard attribution using a separately approved, temporary Owner-only aggregate profiler: elapsed PHP time, SQL count/time and template hashes, without bindings, raw SQL or customer values. Test locally, use serial comparable samples, then remove it. Complexity small, measurement benefit concrete but speed benefit unknown; low collection risk with strict access/output limits. Pair with Owner DevTools aggregate render/interaction measurements on real devices. Only then select an optimization and verify fresh authorization, same-company results and reconciliations. No index, cache, job or frontend rewrite is supported by present evidence.

## Controlled deployment plan - not executed

After independent source acceptance and explicit Owner deployment authorization, pin the exact accepted commit and build manifest. Recheck PHP/Composer compatibility and Linux dialog/assets/deployment contract; preserve current database, uploaded files, source, vendor/build, private environment/key and live web configuration with verified off-host custody. Confirm restoration instructions and rollback assets before entering maintenance. Use the existing Hostinger process; no migrations/bootstrap, provider changes or business-data mutation belong to this patch. Hold maintenance on failure. Before reopening compare nonvolatile financial/security fingerprints, run all six reconciliation domains for all companies, validate authenticated AR/EN and Omnibox, HTTPS/assets and fresh errors. Roll back source/vendor/build/web configuration together if needed; never restore a stale database over newer business events without an explicit recovery decision. Reopen only after required gates pass, then repeat bounded online checks.

**Decision: GO FOR INDEPENDENT REVIEW.** The complete source gate and focused checks pass; external independent acceptance remains pending. Current B.1 stays online and unchanged. Baseline review included read-only production source/status verification. No production configuration/data changes, provider calls, merge or deployment occurred in this correction cycle.
