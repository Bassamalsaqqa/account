# UX-0: single-name party onboarding and truthful Settings

Baseline: `bfac733d2c43ee99e246062a859825f42970a0c6`.
Branch: `feature/ux0-party-onboarding-trust-20261028`.
Status: **GO FOR INDEPENDENT SOURCE REVIEW; no merge or deployment**.

Full-QA source: `3c466663c9434f8a170f5c19ee22d845e63b4c8d`, tree `876594886bf3fc889c4aac46608b455b77541c4b`. Later acceptance updates contain documentation/evidence only. Draft review: [PR #66](https://github.com/Bassamalsaqqa/account/pull/66).

Customers and vendors accept a trimmed Arabic name, English name, or both. Neither language is individually mandatory. Empty names reject; `"0"` remains valid. Catalog services validate effective names under the existing company/actor locks, including partial updates. Quick creation retains durable payload hashes, permissions and transaction boundaries. Posted document identity uses stored snapshots with locale fallback; history is not rewritten.

The forward migration makes only `customers.name_ar` and `vendors.name_ar` nullable. Both tables are preflighted before reversal, including soft-deleted rows. Reversal refuses when null Arabic names exist. MariaDB DDL implicitly commits: maintenance and recovery planning are required before any future authorized production migration. Never fabricate translations or restore an old business database to force rollback.

Advanced details use native keyboard-operable disclosures. Server validation reopens invalid advanced fields without clearing input. Optional personal 2FA remains in Profile; ineffective company enrollment checkboxes are removed. Saving security Settings changes link expiry only and preserves stored legacy flags, including against hydrated client tampering. CSV export remains explicitly non-restorable.

## Verification checkpoint

- Integrated focused MariaDB gate: **65 tests, 436 assertions, exit 0**. Includes real two-process customer/vendor contention, conflicting payloads, name validation, migration roundtrip/refusal, draft continuity and Settings authorization/preservation.
- PHPStan: **662 files, zero errors, exit 0** in the final full gate.
- JavaScript: **44 passed, zero failed, exit 0**.
- Production build: **310 modules, exit 0**.
- npm audit and Composer locked audit: **zero reported vulnerabilities/advisories**, exit 0; dependencies unchanged.
- Historical failed focused attempts are retained privately: stale advanced-email assertion and reused cross-kind race key were fixture defects, corrected before the successful integrated gate.
- First full `composer qa` on `b71a9e0`: **exit 1**, 3,548 passed, one failed, four Windows symlink warnings, 42,588 assertions. Pint and PHPStan passed. The sole failure was a legacy fixture requiring Arabic despite a valid English name. The corrected dataset blanks both names and retains rejection and zero-write assertions; application code is unchanged.
- Corrected focused gate: **72 tests, 409 assertions, exit 0**, including Purchasing Foundation, single-name onboarding and actual contention. Scoped Pint passed. Final full-QA rerun on the exact source above: **exit 0, 3,549 passed, 42,589 assertions, 4 Windows symlink warnings, 3,785.64 seconds**. Pint and PHPStan passed. Source unchanged; owned schema/server/environment cleanup verified. See [sanitized receipt](evidence/ux0/verification.json).
- All six reconciliation domains passed canonical synthetic suites again in the final full run, including a posted-data migration-preservation fixture with unchanged economic fingerprints. This is synthetic evidence, not live-company certification. Four Windows symlink cases could not execute downstream assertions; no Linux pass is claimed.

## Browser evidence and limitations

Actual synthetic application browser checks exercised EN/LTR and AR/RTL at requested 390/768/1440 widths. English-only customer save and Arabic display succeeded; blank-name errors are localized and linked; collapsed invalid email reopens with input preserved. Enter/Space operate the native disclosure and retain summary focus. Security Settings show optional personal 2FA and omit retired company controls.

**Affected browser matrix completed:** 18 settled AR/EN captures cover customer creation, vendor creation and Security Settings at 390/768/1440px. Explicit viewport clipping and reloading after party-form resize resolved earlier screenshot scaling/clipping without application changes. Form bounds remain within each viewport; representative screenshots were independently inspected. English-only synthetic customer/vendor save and display succeeded. Earlier flawed captures remain private superseded diagnostics. Browser capture/input timeouts and one earlier local PHP timeout are environmental limitations; no production performance inference is made. See [browser matrix and screenshot hashes](evidence/ux0/browser-matrix.json). Physical-device, screen-reader and production UAT remain unverified.

Synthetic quick-create measurements from AGY are current-source observations only (roughly 9 ms / 20 SQL queries; replay roughly 4.5 ms / 10 queries). There is no comparable pre-change baseline and no demonstrated speed improvement. A measurement-writing test was removed from the committed suite because it depended on a private workstation directory.

An English-only vendor payment in Arabic retains the existing empty-name posting memo suffix. Canonical posting balances and payables reconciliation passed the synthetic regression. Economic reconstruction was deliberately not changed; no historical memo rewriting is authorized.

## Preserved boundaries

No financial writer, stock authority, tenant policy, provider activation, dependency versions, licensing, routes or production configuration changed. All protected private files and preserved translation work remain outside this change. Cleanup was inventory-only; no old private artifacts, PDFs, worktrees or branches were deleted. Production remains on the accepted baseline. Owner authorization and independent acceptance are required before merge/deployment.

## Independent acceptance and next gate

Codex inspected the combined service, authorization, partial-update, snapshot, migration, quick-create, display and Settings changes and independently verified AGY receipts. GO is for independent source review of this bounded draft. No merge or deployment until accepted and separately authorized. Remaining limitations: Windows symlink coverage, physical-device/assistive-technology UAT, historical vendor memo presentation and unavailable comparable performance baseline. No mandatory MFA or future subscription enforcement is introduced.

Owner follow-up: on actual phone/desktop, create an Arabic-only or English-only party, inspect advanced validation, open/cancel quick create from an unfinished invoice/purchase/quotation and verify quantity/price/currency/FX remain intact. Check Arabic/English navigation and optional personal 2FA wording. This draft is not deployed.

## Prerequisite Mime security correction (2026-10-28)

The authorized UX-0 deployment stopped before maintenance because a fresh Composer audit reported `symfony/mime v8.1.7` affected by `CVE-2026-45070` / `PKSA-wtxr-p26d-nn42`. Production was left online at `bfac733d2c43ee99e246062a859825f42970a0c6`; UX-0 main is `380e82fb7b6bcc422f5da21f758c5155b108aa75`.

The focused prerequisite resolves only Mime to `v8.1.10` through Composer's normal resolver. Every other package, the manifest, lock content hash and package sources remain unchanged. The official source/dist commit is `8d1232052c9cdef170ed9608b3147bca4f61ff69`; PHP requirement remains >=8.4.1 (Hostinger CLI verified 8.4.27). The current [advisory database](https://github.com/FriendsOfPHP/security-advisories/blob/master/symfony/mime/CVE-2026-45070.yaml) marks 8.1 versions below 8.1.10 affected. The [official upstream comparison](https://github.com/symfony/mime/compare/v8.1.7...v8.1.10) contains header token validation/quoting and multipart serialization/type-validation repairs. No application framework, policy or financial writer is replaced.

New standalone synthetic regressions cover unsafe names in constructor, single and bulk parameter APIs; unchanged state on rejection; valid quoting; bilingual mail/PDF attachment content and multipart serialization. Engineering smoke gate: 20 tests / 27 assertions, exit0; no mail delivery or paid provider call. Consolidated QA on `240e7bc688948bb04d510c47336800fb23a2ca38`: composer qa exit0, 3,569 passed / 42,616 assertions, Pint and PHPStan662 clean, four Windows symlink limitations; JS44, build310 and Composer/npm audits clean at verification. Codex independently verified receipts, lock scope and asset hashes. See [security receipt](evidence/ux0/mime-security-verification.json). Deployment still requires fresh production recovery and preservation gates.

Actual PR64/65 review records were inspected. Password-only operator access remains an explicitly accepted product/security tradeoff; grant/session proof, revocation, throttling, password confirmation and transactional audit remain unchanged. Administrator-set `email_verified_at` is a provisioning marker, not proof of mailbox ownership; this correction introduces no new authorization consumer. MS-2 must preserve that distinction and must not grant billing authority from email matching or tenant Owner role.

After this prerequisite passes independent inspection and release/recovery gates, the Owner authorizes its integration and the controlled security-plus-UX-0 deployment. MS-2 implementation remains a separately reviewed draft-only phase, with no production entitlement activation.
