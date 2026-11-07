# Controlled release completed — 7 November 2026

PR: https://github.com/Bassamalsaqqa/account/pull/85 (merged).

Baseline production SHA: `06e4f6d71b8dc3202f0708dff91a21b32a5afd33`.
Accepted runtime release SHA: `8362c15263920b7ebeb5b3efc90d62b951b62daf`.
Release commits: `eada182` (security), `13ee4d8` (application and tests), `d873d58` (qualification evidence), merged through PR85.

## Qualification record

- Final full application run: 4,045 tests / 46,369 assertions; zero errors, skips or incomplete tests; one purchasing architecture assertion failed. Preserved that guard unchanged and replaced direct receipt SQL with the company-scoped `PurchaseConfirmationReceipt` model.
- Final affected rerun: 76 tests / 893 assertions PASS, including the architecture guard, original-snapshot replay, concurrency, rollback injection, populated migration preservation, immediate settlement and workflow refinement.
- Harness independently qualified: 25 tests / 79 assertions PASS, including two-process pointer ownership and HTTP lifecycle smoke.
- Full application PHPStan, repository-wide Pint, 56 JavaScript tests, production Vite build, Composer audit and complete npm audit PASS. Build workers bounded after OS resource failures. No second full-suite execution after the receipt-model adjustment is claimed.
- Independent bounded accounting source review found no material defect before that small architecture correction; parent reviewed and qualified the model correction. Synthetic live browser coverage is limited to the documented screens/dialogs/focus/validation/geometry/locales, not every financial workflow or browser network-loss condition.

## Production execution and preservation

Target remains `account.palsync.net`, private application root `/home/u556956644/domains/palsync.net/accounting`, real public root `/home/u556956644/domains/palsync.net/public_html/account`.

Maintenance entered under the old deployed code before source/assets changed; anonymous requests verified503. Took coherent SQL, code/vendor/build/configuration, private/public-file recovery archives. Owner explicitly waived backup encryption. Archives remained in restricted0700 directories with0600 files, transferred over SSH to separate off-host custody; applicable application key history retained separately. No secret values entered Git/evidence. Verified checksums, SQL completion/99table definitions and required archive components. This is backup completeness/custody verification, not a new full disaster-restoration rehearsal.

Off-host recovery custody: `/home/t3dev/.t3/private-release-backups/release-20261107-8362c152/`.
Separate key custody: `/home/t3dev/.t3/private-release-key-custody/release-20261107-8362c152/`.
These locations contain private recovery data and are outside this repository/public root.

Applied exact accepted SHA and independently hashed matching build, using reviewed `bin/deploy.sh --run-migrations` under PHP8.4.27. No purchasing/documents bootstrap. All five new migrations applied (batch25), yielding104tables/79migrations and five empty receipt tables.

Full historical-row hashes on the original column projections unchanged except additive migration records, exactly five reviewed permissions and20Owner/Manager grants. Existing permission/pivot rows preserved. All45pre-existing storage files hash-identical; one expected new private web-configuration custody copy. An initial comparison excluded custody files only on the after side; corrected the scan coverage and verified all originals rather than accepting the mismatch.

Live `.htaccess` retained byte-for-byte: SHA256 `071652f64ad14f324544e95317aed883e59a9337acb5e1b3519bf0e71a36bdcc`. `.env` checksum unchanged. All nine serving build files matched the staged artifact, with0755directories/0644files and no source maps. Manifest SHA256 `4f7ea5b3a956aca1eaf120c0ee67d499b9fc91854f672c0db32bdceee4ac96ab`.

All six read-only reconciliation services healthy for both companies before and after deployment (12results per pass). Server audit enforced a read-only transaction. No new financial documents, paid AI calls, financial reversals or corrections created during production checks.

## Authenticated verification and reopening

Used the existing authenticated browser session and Laravel-issued maintenance bypass. Dashboard, Settings, reports, purchase creation and sales-invoice creation rendered200 in Arabic RTL and English LTR. Livewire and Alpine initialized; CSS/JS/Livewire assets200; dashboard no horizontal overflow at checked desktop viewport. Language switched through the normal authorized UI and restored to Arabic. Users-table semantic hash excluding `updated_at` matched before/after; only that normal preference-check timestamp may differ. No passwords, roles or company settings changed by smoke checks.

Web manifest200 with `application/manifest+json`. Debug and subscription enforcement remain off. Fresh production error/critical log count0. Reopened only after preservation/reconciliation/authenticated checks passed. Verified authenticated dashboard200; anonymous login200; anonymous protected dashboard redirects to login. Removed the temporary maintenance bypass cookie and closed the owned browser tab.

Owned release staging is removed after off-host verification; original unrelated storage/framework staging directories and deploy.lock preserved. Forward schema and immutable history retained; no destructive rollback/reset/force-push occurred. Known-good source/build recovery remains in verified private off-host custody. The final evidence-only documentation commit may advance the Git checkout; it changes no qualified runtime source or assets.

Original redundant `agy-runtime-contract-fixes.md` and raw `docs/evidence/corrections-20261107/` artifacts deliberately remain untracked and preserved locally. All deliberate application changes are committed and pushed.
