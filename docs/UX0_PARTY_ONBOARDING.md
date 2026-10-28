# UX-0: single-name party onboarding and truthful Settings

Baseline: `bfac733d2c43ee99e246062a859825f42970a0c6`.
Branch: `feature/ux0-party-onboarding-trust-20261028`.
Status: **HOLD for final QA and browser acceptance; no merge or deployment**.

Customers and vendors accept a trimmed Arabic name, English name, or both. Neither language is individually mandatory. Empty names reject; `"0"` remains valid. Catalog services validate effective names under the existing company/actor locks, including partial updates. Quick creation retains durable payload hashes, permissions and transaction boundaries. Posted document identity uses stored snapshots with locale fallback; history is not rewritten.

The forward migration makes only `customers.name_ar` and `vendors.name_ar` nullable. Both tables are preflighted before reversal, including soft-deleted rows. Reversal refuses when null Arabic names exist. MariaDB DDL implicitly commits: maintenance and recovery planning are required before any future authorized production migration. Never fabricate translations or restore an old business database to force rollback.

Advanced details use native keyboard-operable disclosures. Server validation reopens invalid advanced fields without clearing input. Optional personal 2FA remains in Profile; ineffective company enrollment checkboxes are removed. Saving security Settings changes link expiry only and preserves stored legacy flags, including against hydrated client tampering. CSV export remains explicitly non-restorable.

## Verification checkpoint

- Integrated focused MariaDB gate: **65 tests, 436 assertions, exit 0**. Includes real two-process customer/vendor contention, conflicting payloads, name validation, migration roundtrip/refusal, draft continuity and Settings authorization/preservation.
- PHPStan: **662 files, zero errors, exit 0** at the preceding implementation checkpoint; final full gate pending.
- JavaScript: **44 passed, zero failed, exit 0**.
- Production build: **310 modules, exit 0**.
- npm audit and Composer locked audit: **zero reported vulnerabilities/advisories**, exit 0; dependencies unchanged.
- Historical failed focused attempts are retained privately: stale advanced-email assertion and reused cross-kind race key were fixture defects, corrected before the successful integrated gate.
- First full `composer qa` on `b71a9e0`: **exit 1**, 3,548 passed, one failed, four Windows symlink warnings, 42,588 assertions. Pint and PHPStan passed. The sole failure was a legacy fixture requiring Arabic despite a valid English name. The corrected dataset blanks both names and retains rejection and zero-write assertions; application code is unchanged.
- Corrected focused gate: **72 tests, 409 assertions, exit 0**, including Purchasing Foundation, single-name onboarding and actual contention. Scoped Pint passed. Final complete QA rerun remains **pending**.
- All six reconciliation domains passed canonical synthetic suites in the first full run, including a posted-data migration-preservation fixture with unchanged economic fingerprints. This is synthetic evidence, not live-company certification. Four Windows symlink cases could not execute downstream assertions; no Linux pass is claimed.

## Browser evidence and limitations

Actual synthetic application browser checks exercised EN/LTR and AR/RTL at requested 390/768/1440 widths. English-only customer save and Arabic display succeeded; blank-name errors are localized and linked; collapsed invalid email reopens with input preserved. Enter/Space operate the native disclosure and retain summary focus. Security Settings show optional personal 2FA and omit retired company controls.

**Visual matrix remains HOLD:** rapid viewport changes produced clipped/scaled RTL screenshots despite reported document bounds. Browser capture/input timeouts and one local PHP 30-second timeout occurred during concurrent build/testing. Captures are private diagnostic evidence, not a certified visual pass. Repeat settled-page captures and inspect screenshots before acceptance. No physical-device or production UAT is claimed.

Synthetic quick-create measurements from AGY are current-source observations only (roughly 9 ms / 20 SQL queries; replay roughly 4.5 ms / 10 queries). There is no comparable pre-change baseline and no demonstrated speed improvement. A measurement-writing test was removed from the committed suite because it depended on a private workstation directory.

An English-only vendor payment in Arabic retains the existing empty-name posting memo suffix. Canonical posting balances and payables reconciliation passed the synthetic regression. Economic reconstruction was deliberately not changed; no historical memo rewriting is authorized.

## Preserved boundaries

No financial writer, stock authority, tenant policy, provider activation, dependency versions, licensing, routes or production configuration changed. All protected private files and preserved translation work remain outside this change. Cleanup was inventory-only; no old private artifacts, PDFs, worktrees or branches were deleted. Production remains on the accepted baseline. Owner authorization and independent acceptance are required before merge/deployment.
