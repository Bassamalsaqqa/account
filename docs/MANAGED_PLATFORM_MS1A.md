# MS-1A managed-platform candidate

Baseline: `543cc6a410499085b16dc664e1579bce526c69c7` (B.1.2). Owner initially accepted the MS-0 proposal for bounded implementation toward a draft PR. **Not authorized for merge, deployment, paid provider calls or live customer onboarding.**

## Scope

The platform operator manages the first 1–10 businesses using a separate `/platform` control plane. Company Owner roles do not grant platform authority. The existing accounting, stock, tenant membership and staff-management boundaries remain authoritative.

- Existing User/Fortify authentication; explicit independent operator grant and current-session MFA proof. Enrollment, password-only login and remembered authentication alone cannot grant platform access.
- Company list/search/detail and member/Owner metadata only. No financial records, customer impersonation, provider credentials, customer-account linking or general user directory.
- Full-page business/new-Owner creation, review and recent password confirmation. The new identity, canonical company foundations, replay result and success audit commit together. Existing email identities are rejected without changing passwords or memberships.
- AI starts OFF through the existing absent/disabled company policy, with no duplicate policy column or provider activation.
- Separate platform audit and timestamped precomputed six-domain health booleans. Missing, stale or malformed results are not shown as current healthy results. HTTP page loads never run reconciliations.
- Arabic/English, RTL/LTR and responsive layouts. Existing tenant Settings and staff password creation are retained. A confirmed prerequisite repair loads the existing Alpine dependency on the MFA challenge page and supplies its missing AR/EN control labels.

Not included: billing, plans, grace/read-only entitlements, suspension actions, self-registration, email invitations/verification, payment gateways, invoice/receipt composition, opening balances, a general UX redesign or new infrastructure.

## Authorization and session contract

`platform:operator <existing-email>` is an interactive host administration command. Granting requires existing confirmed 2FA enrollment; it creates no credentials and sends no email. No account is granted automatically, including the application Owner. Revocation and permission changes advance the grant revision. Host-command audit uses a null web actor, identifies the target operator separately and does not claim to identify the human behind the SSH account.

A fresh successful Fortify MFA challenge creates private pending proof which is transferred only to the corresponding real web login. Proof binds user, credential/MFA fingerprint, grant revision and time. It expires after one hour; credential/MFA/recovery changes and grant revisions invalidate it. Logout removes it; a remembered session cannot use it. The five-second pending transfer window is internal to the same challenge/login flow, not a client-controlled authorization token. Operators reauthenticate after expiry; enrollment timestamps are not challenge proof.

Every platform HTTP route uses authentication, independent platform permission checks and throttling. Each controller/action repeats authorization. Platform mutation is ordinary CSRF-protected HTTP, not a Livewire component; altered Livewire parameters do not expose a platform mutation endpoint. Ordinary tenant Livewire behavior remains company-bound.

Provisioning locks the current operator grant and identity in a transaction, then checks current session proof/permission. A revocation or credential reset committed before the lock/check rejects new work. A transaction already authorized while holding these locks may finish before concurrent revocation commits. No external calls occur under these locks. Canonical company foundation actions run inside the outer transaction, with bounded deadlock retries. Finally cleanup clears/restores tenant/team state without reviving revoked membership.

The operator-scoped request key compares a keyed digest of canonical input, including the password, without storing plaintext passwords or ordinary password digests in request/audit records. Identical retries return the same business; changed payloads are rejected. Successful retry does not re-create identities, replace credentials, add audit duplicates or post financial events. The administrator-created account semantics are preserved; initial credentials need secure Owner-controlled handoff.

## Operational commands and additive migration

- `php artisan platform:operator <existing-email>`: interactive grant; append `--revoke` for revocation. This is powerful trusted-host administration, not tenant self-service. Do not run in production without separate authorization.
- `php artisan platform:health-snapshot <company-public-id>`: bounded read-only reconciliation for one company followed by saving six booleans and a timestamp. Suitable for a separately approved shared-hosting cron; no scheduler or production cron is enabled by this candidate. Failure leaves the old snapshot to age into a stale indication.

The new migration creates only platform operator grants, provisioning requests, audit events and health snapshots. It changes no financial/tenant table definitions and bootstraps no operator. Foreign keys protect access/audit custody from tenant/user deletion. Down migration refuses populated platform tables before any DDL. Source rollback should retain this additive schema. MariaDB DDL is not transactional: after an interrupted migration, inspect actual created tables and migration ledger before retrying; do not blindly rerun/drop partially created access/audit tables. No migration has been applied to production.

Model audit update/delete guards protect application paths; this is not database-administrator tamper-proof logging. No implementation can turn the existing data export into full recovery media. Full confidential restored-application boot, physical-device acceptance, actual mail delivery and independent-customer hosting capacity remain separate gates.

## Verification status

This is an implementation checkpoint, **not final source acceptance**. Exact focused commands, historical failures and corrected passes are retained in private receipts. No old B.1.2 QA total is counted as proof of this feature.

- Source-pinned focused platform/authentication gate: 40 tests / 327 assertions, exit 0. Includes provisioning/replay/rollback, enrollment-vs-challenge, permission/revocation/credential/MFA changes, privacy, localized server rendering, guarded additive migration roundtrip and existing identity recovery.
- Separate actual multi-process MariaDB concurrency gate: 4 tests / 37 assertions, exit 0. Identical concurrent submissions converge; changed payload conflicts; revocation before authorization denies. The final test observes a real InnoDB lock wait when revocation follows transaction authorization, verifies the authorized create completes before revocation commits, and rejects subsequent work. This is local synthetic proof, not production stress/capacity testing.
- Forty-four existing JavaScript tests passed. Production build passed with 310 modules. Locked Composer/npm audits both exit 0 with zero known advisories/vulnerabilities at the checkpoint. No dependency versions changed.
- Six canonical reconciliations healthy for each of two fictional browser companies. Inspected business posting/stock/payment/check tables remain empty; no provider/policy rows created. Owned browser and QA processes stopped, disposable schemas absent. Protected private work remains preserved.
- Actual local browser: successful MFA/recovery switch and challenge, platform grant access, password-confirmed create workspace, review of current nonsensitive values including changed currency, password excluded from review, directory search and company-specific member projection. Captured AR/EN mobile views and English final desktop table. Some earlier captures predate final desktop-table markup; they are discovery evidence, not final visual acceptance. Exact successful files/viewport facts remain private; no production data captured.
- Browser control intermittently timed out, then automatic approval review reached its usage limit. No workaround was used. The corrected prototype's old file-protocol restriction remains separate; no alternate server exposed that prototype. Final complete AR/EN screenshot/keyboard/screen-reader certification remains incomplete.

Earlier failures are retained: overly long new MariaDB index name, a named parameter mismatch in the new health command, misplaced translation files, a test-only Livewire asset-render lifecycle assumption and a stale review-summary implementation were corrected. Passing receipts supersede those attempts; failed/incomplete attempts are never counted as PASS.

Remaining mandatory source gates: one full isolated `composer qa` on the exact committed candidate, final integrated source inspection/evidence sanitization, and unresolved browser verification. Physical-device and full disaster-recovery gates remain explicit; no paid AI quality/cost evaluation is performed. Draft PR only; merge/deployment remain unauthorized.
