# Phase 10 P1 Stage 0: Account Recovery and Mail Source Assessment

Current status: **STAGE 0 SOURCE ASSESSMENT COMPLETE; LOCAL INVARIANTS CARRIED (11 TESTS / 88 ASSERTIONS PASS); OPERATIONAL EMAIL DELIVERY & RECOVERY PROOF BLOCKED EXTERNAL AUTHORIZATION; ARCHITECT REVIEW PENDING; CUSTOMER READINESS NOT YET ESTABLISHED**.\
Date: 2026-10-10.\
Baseline commit: `1f1fa5874eaffe749d901ee14a21850e327c9687`.\
Accepted main baseline: `c74c9a4e0b4135055dbb8ed7d3e3715d2ddba24f`.\
Deployed production runtime: `8d8428261cd2a10690ab77a5c7271e46b5ff5217`.\

Controlling references: [Architect handoff](PHASE_10_ARCHITECT_HANDOFF.md) | [Source acceptance matrix](PHASE_10_SOURCE_ACCEPTANCE_MATRIX.md) | [Production baseline](PHASE_10_PRODUCTION_BASELINE.md) | [Baseline risk register](PHASE_10_P0_BASELINE_RISK_REGISTER.md) | [Backup and restore runbook](PHASE_10_BACKUP_RESTORE_RUNBOOK.md) | [Operational monitoring runbook](PHASE_10_OPERATIONAL_MONITORING_RUNBOOK.md) | [Autonomous implementation packages](PHASE_10_AUTONOMOUS_IMPLEMENTATION_PACKAGES.md).

---

## 1. Executive Summary & Operational Authority Boundary

This document records the bounded Stage 0 identity and mail source assessment for Phase 10 P1 operational readiness ([RSK-02](PHASE_10_P0_BASELINE_RISK_REGISTER.md)). It establishes code-level invariants, evaluates log transport risks and encryption key dependencies, specifies requirements for approved delivery, designs a single controlled end-to-end rehearsal run on a restored isolated runtime, explains why negative security tests must not use real mail, and outlines consolidated authorization inputs and cleanup procedures.

### Authority and Isolation Bounds
- **Worker Scope (AGY):** This assessment is strictly bounded to read-only source and documentation analysis within the isolated workspace. The worker has no host, server, or production access. (Codex separately conducted read-only SSH/panel/DNS discovery recorded in central records; [central Stage 0 record](PHASE_10_P1_OPERATIONAL_READINESS.md) controls fresh baseline facts.)
- **Review Status:** Independent architect review of the Phase 10 source candidate remains pending.
- **No Credentials or Secrets Read:** No production `.env`, private API keys, SMTP passwords, application encryption keys, or live user account credentials have been read, logged, or exported. No tokens, private email addresses, or example passwords appear in this document.
- **Operational Gate Status:** Real email delivery via an approved provider proving actual account self-recovery remains **BLOCKED EXTERNAL AUTHORIZATION** / **NOT VERIFIED LIVE**.
- **Provider Capability:** Current mail provider and host deliverability are **NOT VERIFIED**. Hostinger shared email must not be assumed authoritative or functional from hosting presence alone.
- **Production Status:** **CUSTOMER READINESS NOT YET ESTABLISHED**.

---

## 2. Independent Source Assessment: Fortify, Auth, Mail & Session Architecture

An independent inspection of the application source configuration, package lockfile, and framework bindings was conducted without fetching or installing external dependencies.

### 2.1 Package Manifest & Installed Library Versions
From [`composer.lock`](../composer.lock):
- `laravel/fortify`: `v1.40.0` (lines 1431–1433) — provides authentication, two-factor authentication, and password reset contracts.
- `laravel/framework`: `v13.33.0` (lines 1495–1497) — core framework providing session storage, routing, encryption, and password broker services.
- `symfony/mailer`: `v8.1.7` (lines 5791–5793) — email transport implementation.

### 2.2 Source Configuration vs. Runtime Handlers
- **Fortify Configuration ([`config/fortify.php`](../config/fortify.php)):**
  - Defines core settings: Guard `'web'` (line 24), broker `'users'` (line 37), username `'email'` with lowercasing enabled (`'lowercase_usernames' => true`, line 69), redirect `'/settings'` (line 82).
  - Features (lines 152–162): `Features::resetPasswords()`, `Features::updateProfileInformation()`, `Features::updatePasswords()`, and `Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true])`. Public registration is disabled by default (`env('REGISTRATION_ENABLED', false)`).
  - Rate limiters in [`app/Providers/FortifyServiceProvider.php`](../app/Providers/FortifyServiceProvider.php): Login rate limiter allowing 5 attempts per minute keyed by transliterated lower `email|IP` (lines 42–46); two-factor rate limiter allowing 5 attempts per minute keyed by session `login.id` (lines 48–50).
- **Application Routing Distinction ([`routes/auth.php`](../routes/auth.php)):**
  - Authentication UI and reset actions are handled via Livewire Volt components, **not** standard Fortify HTTP controller routes:
    - `forgot-password` (GET, line 18) renders Volt component `pages.auth.forgot-password`. Form submission triggers the Livewire action `sendPasswordResetLink`, which calls `Password::sendResetLink()` ([`forgot-password.blade.php`](../resources/views/livewire/pages/auth/forgot-password.blade.php#L23)).
    - `reset-password/{token}` (GET, line 21) renders Volt component `pages.auth.reset-password`. Form submission triggers the Livewire action `resetPassword`, which calls `Password::reset()` ([`reset-password.blade.php`](../resources/views/livewire/pages/auth/reset-password.blade.php#L45)).
    - Installed Fortify also registers password.email/password.update POST controller routes when resetPasswords is enabled. This UI rehearsal verifies the shipped Volt forms and actual Livewire actions; do not infer route absence from routes/auth.php alone.
- **Authentication Configuration ([`config/auth.php`](../config/auth.php)):**
  - Guard `'web'` uses driver `'session'` and provider `'users'` (lines 51–56).
  - Password broker `'users'` (lines 106–113): table `'password_reset_tokens'`, expiration `60` minutes (`'expire' => 60`), throttle `60` seconds (`'throttle' => 60`).
- **Mail Configuration ([`config/mail.php`](../config/mail.php)):**
  - Source default mailer: `env('MAIL_MAILER', 'log')` (line 17).
  - Transports: `'smtp'`, `'ses'`, `'postmark'`, `'resend'`, `'sendmail'`, `'log'`, `'array'`, `'failover'`, `'roundrobin'` (lines 40–100).
  - Default From address: `env('MAIL_FROM_ADDRESS', 'hello@example.com')` (line 114).
- **Session Configuration ([`config/session.php`](../config/session.php)):**
  - Driver: `env('SESSION_DRIVER', 'database')` (line 21), lifetime: `120` minutes (line 35), table: `'sessions'` (line 89).
  - Security cookie parameters: `'http_only' => true` (line 185), `'same_site' => 'lax'` (line 202), `'secure' => env('SESSION_SECURE_COOKIE')` (line 172).

### 2.3 Source Defaults vs. Production Facts
> [!IMPORTANT]
> **Source defaults are not production facts.**
> In repository source defaults, `MAIL_MAILER` defaults to `log` and `MAIL_HOST` to `127.0.0.1`.
> In the production baseline ([`PHASE_10_PRODUCTION_BASELINE.md`](PHASE_10_PRODUCTION_BASELINE.md) line 181), SMTP was recorded as selected in `.env`, but the explicit SMTP host key was absent. End-to-end delivery, SMTP URL / alternative configuration, provider deliverability, and an approved recovery path remain **NOT VERIFIED**.
> Shared-hosting mail presence must not be assumed operational or authorized for production delivery without explicit testing.

---

## 3. Account Recovery, Auth & Session Invariants (Carried Evidence)

### 3.1 Token Hashing Architecture
In Laravel's `DatabaseTokenRepository` (`Illuminate\Auth\Passwords\DatabaseTokenRepository`):
- The token stored in `password_reset_tokens` is hashed via `$this->hasher->make($token)` (using bcrypt/argon2 via `Hash::make`), **not** an HMAC.
- Verification is performed via `$this->hasher->check($token, $record['token'])`.
- `APP_KEY` is utilized solely as random seed material (`hashKey`) during token string creation (`createNewToken`), **not** for stored verification.
- Consequently, rotating `APP_KEY` does **not** by itself invalidate existing broker tokens in `password_reset_tokens` through hashing.

### 3.2 Formal Invariants and Source Test Anchors
All invariants are asserted in [`tests/Feature/Auth/Phase10IdentityRecoveryTest.php`](../tests/Feature/Auth/Phase10IdentityRecoveryTest.php):

| Invariant | Source Enforcement | Test Reference & Anchors |
|---|---|---|
| **1. Token Expiry** | Tokens expire after 60 minutes ([`config/auth.php`](../config/auth.php#L110)). Expired tokens rejected. | `test_password_reset_rejects_expired_token`<br>Lines 122–158: Backdates `password_reset_tokens.created_at` by 61 minutes; asserts password unchanged and validation error returned. |
| **2. Request Throttling** | Reset link dispatches throttled to 1 per 60s ([`config/auth.php`](../config/auth.php#L111)). Login attempts throttled to 5 failures/min per `email\|IP` ([`FortifyServiceProvider.php`](../app/Providers/FortifyServiceProvider.php#L42-L46)). | `test_password_broker_throttles_rapid_reset_link_requests`<br>Lines 207–239: Second request within 60s rejected; advancing clock by 61s permits resend.<br>`test_login_throttling_locks_out_after_maximum_failed_attempts`<br>Lines 241–286: 6th web and API attempt receive HTTP 429 Too Many Requests. |
| **3. Replay Protection** | Tokens are strictly single-use. Successful reset removes token record from database. Consumed tokens cannot be reused. | `test_password_reset_token_cannot_be_replayed_after_successful_reset`<br>Lines 160–205: Asserts `password_reset_tokens` record is deleted (`assertDatabaseMissing`); replay attempt fails.<br>`test_two_factor_challenge_rejects_replayed_recovery_code`<br>Lines 341–380: Recovery code removed upon consumption; second use rejected. |
| **4. Account Binding** | Token issued for User A cannot be bound to User B's email. | `test_password_reset_token_cannot_be_bound_to_different_account`<br>Lines 76–120: Token issued to victim tested against attacker email; rejected, neither password changed.<br>`test_valid_password_reset_changes_password_hash_and_remember_token_and_invalidates_old_password`<br>Lines 34–74: Valid reset updates hash, regenerates remember_token, invalidates old credentials. |
| **5. Session Invalidation** | Logout invalidates session store, regenerates session ID, clears tenant context markers, redirects to login on protected requests. | `test_logout_session_invalidation_and_unauthenticated_state`<br>Lines 288–313: Asserts session ID change, marker destruction, unauthenticated guest state, and 302 to `/login` on `/settings`. |
| **6. Company Tenancy & CSRF** | Tenant switching routes enforce CSRF. Switching to unassigned company aborts fail-closed. | `test_csrf_middleware_enforcement_and_unauthorized_company_switch`<br>Lines 382–403: Synthetic CSRF middleware throws `TokenMismatchException` without token.<br>`test_unauthorized_company_switch_aborts_with_forbidden_status`<br>Lines 405–430: User A attempting switch to Company B aborts HTTP 403 Forbidden. |

### 3.3 Evidence Disposition (Carried Historical Evidence)
- **Status:** **PASS** (11 tests / 88 assertions).
- **Execution Record:** Executed in package P10-A1 (elapsed 39.627s, memory 80 MiB, schema `accounting_p8_tmp_ca2f0170eb87` cleaned).
- **Citation Policy:** Cited as **carried historical evidence** from recorded P10-A1 internal verification, with independent architect acceptance pending. **Not freshly re-run** in this Stage 0 assessment.

---

## 4. Log Transport & Token Disclosure Risks

### 4.1 Conditional Risk Analysis of `MAIL_MAILER=log`
The risk of cleartext reset link logging is **conditional**, relevant only if `MAIL_MAILER=log` is active or email debug logging is enabled:
1. **Cleartext Reset URL Logging:** When active, Laravel writes outbound MIME bodies to the log stream. The message contains the full reset URL, creating an exposure window for anyone with log read access.
2. **Log Destination:** In [`config/logging.php`](../config/logging.php#L55-L60), the default channel is `'stack'`, which configurable via `LOG_STACK` may use `'daily'` (producing date-stamped files like `laravel-YYYY-MM-DD.log`) or `'single'` (`laravel.log`).
3. **Contrast with Database:** Database storage hashes tokens using `Hash::make()`; log files bypass this protection if raw MIME bodies are written to disk.
4. **Logging Policy:** In any operational deployment or live rehearsal, `MAIL_MAILER=log` must never be used for password resets. Application logs must not capture tokens or credential payloads.

---

## 5. Encryption Key History & Encrypted Attributes

Application recovery and session integrity intersect directly with Laravel's application encryption keys ([RSK-12](PHASE_10_P0_BASELINE_RISK_REGISTER.md)).

### 5.1 Relevant Encrypted Attributes
The following model attributes depend on application encryption (`Crypt::encryptString` / `Crypt::decryptString`):
1. **`users.two_factor_secret` ([`2026_09_28_183233_add_two_factor_columns_to_users_table.php`](../database/migrations/2026_09_28_183233_add_two_factor_columns_to_users_table.php#L15)):** Encrypted TOTP secret via Fortify's `TwoFactorAuthenticatable`.
2. **`users.two_factor_recovery_codes` ([`2026_09_28_183233_add_two_factor_columns_to_users_table.php`](../database/migrations/2026_09_28_183233_add_two_factor_columns_to_users_table.php#L19)):** Encrypted JSON array of recovery codes.
3. **`public_shares.encrypted_token` (Financial Shares):** Raw share token encrypted in [`app/Services/Sales/IssuedFinancialShares.php`](../app/Services/Sales/IssuedFinancialShares.php#L101).
4. **`public_shares.encrypted_token` (Catalog Shares):** Raw share token encrypted in [`app/Services/Catalogs/CatalogService.php`](../app/Services/Catalogs/CatalogService.php#L339).
5. **`public_shares.encrypted_snapshot`:** Financial document JSON encrypted in [`app/Services/Sales/IssuedDocumentContent.php`](../app/Services/Sales/IssuedDocumentContent.php#L29).

### 5.2 Key History & Invariants (`APP_KEY` and `APP_PREVIOUS_KEYS`)
- **Cipher:** `AES-256-CBC` ([`config/app.php`](../config/app.php#L98)).
- **Primary Key:** `APP_KEY` ([`config/app.php`](../config/app.php#L100)).
- **Multi-Key Rotation Support:** Configured via `APP_PREVIOUS_KEYS` ([`config/app.php`](../config/app.php#L102-L106)).
- **Key Retention Invariant:** `APP_PREVIOUS_KEYS` must preserve all required key versions across the data lifecycle. If a historical key version is omitted upon rotation, decrypting existing 2FA secrets, catalog grants, and financial shares will fail with `DecryptException`.

---

## 6. Approved Provider, Sender, DNS & Isolated Recipient Requirements

Before any operational mail delivery test or rehearsal can be approved, the following technical prerequisites must be satisfied:

### 6.1 Provider & Transport Requirements
- Approved email provider with verified secure TLS appropriate to the approved transport (STARTTLS or implicit TLS as configured).
- Record unavailable configuration inputs; Hostinger shared email is not assumed authoritative or working.

### 6.2 Sender & DNS Authentication Requirements
- `MAIL_FROM_ADDRESS`: Strictly designated system address.
- `MAIL_FROM_NAME`: Truthful system sender name matching application identity.
- DNS Authentication: Appropriate existing SPF, DKIM, and DMARC alignment on the sending domain. **No DNS changes are authorized.**

### 6.3 Recipient & Privacy Requirements
- Recipient email address must be the exact approved, real, controlled recipient mailbox designated by the operator. (A synthetic email at `.test` or `.local` cannot receive external email across public MX records.)
- **Privacy Policy:** Never publish private email addresses in approval documentation; exact recipient address is maintained within a protected, operator-controlled scope.
- **Strict Prohibition:** Never target the real production Owner account, customer accounts, or employee email addresses for password reset testing. No production password hashes or user accounts may be mutated.

---

## 7. Controlled E2E Account Recovery Run Specification

The following procedure specifies the single controlled end-to-end rehearsal run on a restored isolated runtime **only after explicit Product Owner authorization**.

### 7.1 Separation of Isolation and SMTP Phases
1. **Default Recovery Isolation:** Default for recovery rehearsal is **no outbound network traffic**. The initial restore verification sends no external email.
2. **Separated SMTP Delivery Phase:** Real SMTP delivery is strictly separated, occurring **after** restore verification, key checks, and plaintext cleanup have completed — preferably on an independent pure synthetic copy.
3. **Restricted Egress:** Outbound TLS egress permitted solely to the approved provider, targeting the single approved recipient. Production database access and full public ingress remain strictly blocked.

### 7.2 Detailed Step-by-Step Procedure
1. **Isolated Runtime Verification:** Confirm runtime satisfies [Package D1-C](PHASE_10_AUTONOMOUS_IMPLEMENTATION_PACKAGES.md#d1-c-fail-closed-pre-bootstrap-isolation-both-proof-lanes): network-isolated target, disposable database schema, verified configuration, no access to production databases.
2. **Synthetic Account Provisioning:** Provision an ephemeral test user using the exact approved recipient email, with a known initial password hash and verified active company membership under inherited policy. **Restored historical user passwords remain untouched.**
3. **Password Reset Request via Livewire Action:** Trigger reset request via Livewire Volt component [`pages.auth.forgot-password`](../resources/views/livewire/pages/auth/forgot-password.blade.php) (invoking `sendPasswordResetLink`).
4. **Outbound Dispatch:** Application dispatches exactly one `ResetPassword` notification via the approved mailer over TLS.
5. **Delivery Inspection:** Operator inspects message in the controlled test inbox, confirming recipient, sender, TLS encryption, and SPF/DKIM/DMARC alignment.
6. **Form Retrieval:** Navigate to `/reset-password/[REDACTED-TOKEN]` on the isolated host using `APP_URL` loopback reachable only by the approved operator. Form renders with HTTP 200.
7. **Password Reset Submission via Livewire Action:** Submit new compliant password with confirmation via Livewire Volt component [`pages.auth.reset-password`](../resources/views/livewire/pages/auth/reset-password.blade.php) (invoking `resetPassword`).
8. **Application State Assertions:**
   - Password hash in `users` table is updated and does not match the initial hash.
   - `remember_token` in `users` is regenerated.
   - Corresponding record in `password_reset_tokens` is deleted.
   - Old password fails authentication.
9. **Replay Negative Test:** Immediately re-submit the same token via `pages.auth.reset-password`. Assert request is rejected with validation error on email (`passwords.token` invalid).
10. **Authentication Verification:** Log in with newly set password. Assert redirect to `/settings` and active session created in `sessions`.
11. **Teardown & Cleanup:** Execute immediate cleanup checklist below.

---

## 8. Source-Clock vs. Real Delivery: Justification for Negative Tests

A critical distinction must be maintained between delivery verification and security invariant testing:

### 8.1 Real Delivery Evidence (Transport Layer)
Real email delivery proves that outbound TLS transport, provider authentication, and recipient mailbox delivery function.
> [!IMPORTANT]
> **Isolated Rehearsal vs. Production Host:**
> Real email delivered from an isolated restored runtime proves transport configuration, but does **not** prove production-host outbound egress, firewall rules, or live runtime deliverability. Production-host transport proof remains separate and **NOT VERIFIED**.
> Any future production-host probe must be narrowly scoped, side-effect-free, explicitly Owner-approved, with zero live account changes, and no runtime deployment.

### 8.2 Source-Clock Evidence (Application & Database Layer)
Security invariants (token expiration, throttling, replay prevention, account binding) are internal application and database state transitions. **They must not use repeated real email deliveries:**
1. **Determinism:** Manipulating the application clock via Laravel's `$this->travel()` or updating `password_reset_tokens.created_at` in a disposable database provides exact, deterministic verification of boundary limits (e.g. exactly 60 seconds throttle, exactly 60 minutes expiration).
2. **Efficiency & Safety:** Testing 60-minute expiration by waiting 60 minutes or sending repeated emails is slow, wasteful, and risks hitting provider rate limits.
3. **No Excess SMTP:** Once one real delivery proves transport viability and single-use completion/replay, all negative lifecycle tests are safely proven using deterministic source-clock manipulation and fake/array transports on disposable schemas.

---

## 9. Rollback, Cleanup & Disposal Runbook

Following rehearsal execution, the isolated environment must be immediately sanitized:

### 9.1 Scoped Cleanup Checklist
- [ ] **Delete Ephemeral Fixtures:** Delete strictly the newly created synthetic test user ID from `company_user` and `users` tables, leaving all restored historical users unchanged. Alternatively, drop the entire owned disposable test schema under approval.
- [ ] **Purge Residual Tokens:** Clear `password_reset_tokens` records matching the test recipient email.
- [ ] **Purge Test Sessions:** Clear session records associated with the synthetic test user ID.
- [ ] **Revert Mailer Configuration:** Revert `.env` in the isolated test harness back to `MAIL_MAILER=array` or safe local transport.
- [ ] **Audit Application Logs:** Locally inspect under protected custody without emitting secrets. If a leak is detected, quarantine raw sensitive material privately and retain only a sanitized status; revoke the test token/session and stop before sharing artifacts. Never preserve raw URLs/passwords in screenshots, command history, HAR/traces or browser history evidence.
- [ ] **Verify Zero Production Mutation:** Confirm production database and files were untouched.

---

## 10. Consolidated Authorization Inputs (Single Central Record)

Before operational execution can proceed, the Product Owner must provide the following inputs in a single consolidated request in the [central Stage 0 record](PHASE_10_P1_OPERATIONAL_READINESS.md):

| Input ID | Required Authorization Item | Specification & Boundaries | Current Disposition |
|---|---|---|---|
| **AUTH-01** | **Target Environment** | Non-production isolated target runtime identity. | **PENDING OWNER DECISION** |
| **AUTH-02** | **Mail Provider & Transport** | Approved actual email provider/transport and protected credential custody; no new provider mandated. | **PENDING OWNER DECISION** |
| **AUTH-03** | **Sender Identity** | Specification of `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`. | **PENDING OWNER DECISION** |
| **AUTH-04** | **DNS Alignment Verification** | Confirmation of existing SPF, DKIM, and DMARC alignment. (No DNS changes authorized.) | **PENDING OWNER DECISION** |
| **AUTH-05** | **Controlled Recipient** | Exact email address of operator-controlled test mailbox (in protected scope). | **PENDING OWNER DECISION** |
| **AUTH-06** | **Custodian Identity** | Named independent secrets custodian; operator and custodial access explicitly distinguished. | **PENDING OWNER DECISION** |

---

## 11. Residual Dispositions & Status Ledger

| Area | Requirement / Gate | Current Disposition | Basis & Evidence |
|---|---|---|---|
| **Identity Invariants** | SEC-03; Reset expiry, replay, throttles, session, CSRF | **LOCAL PASS (CARRIED 11/88)** | Carried evidence from [`tests/Feature/Auth/Phase10IdentityRecoveryTest.php`](../tests/Feature/Auth/Phase10IdentityRecoveryTest.php) (39.627s / 80 MiB). |
| **Operational Mail Delivery** | RSK-02; Real SMTP delivery to approved recipient | **BLOCKED EXTERNAL AUTHORIZATION** | External dispatch strictly prohibited without Owner authorization. |
| **Mail Provider Viability** | RSK-02; Hostinger shared mail deliverability | **NOT VERIFIED** | Shared-hosting deliverability unproven; provider capabilities unverified. |
| **Production-Host Transport** | RSK-02; Outbound egress from live host | **NOT VERIFIED** | Separate from isolated rehearsal; requires explicit Owner approval. |
| **Off-Host Key Recovery** | RSK-12; Application keys & backup decryption secrets | **BLOCKED EXTERNAL AUTHORIZATION** | Off-host custody and key restoration unproven; pending authorized rehearsal. |
| **Disaster Recovery Rehearsal** | RSK-01; Historical & current coherent restore | **BLOCKED EXTERNAL AUTHORIZATION** | Restoration rehearsal pending authorized isolated target. |
| **Customer Readiness** | REL-01; Production readiness signoff | **NOT YET ESTABLISHED** | Production hardening incomplete; three P1 operational gates remain open. |
