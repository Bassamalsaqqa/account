# MS-2 — manual subscriptions and tenant write admission

Status: **draft; not approved for merge, deployment or production enforcement**.
Baseline: `6453f2845ea4bd0dcf60009274d27d5fee48df50` (accepted security + UX-0 production release).
The candidate's exact source identity and final QA result must accompany the draft PR; earlier MS-1A/UX-0 totals do not certify this source.

## Product contract

An explicitly authorized platform operator records a plan, exact fee/currency, period dates, immutable charges and received payment receipts. There is no payment gateway, tenant billing access, impersonation or tenant-ledger posting. A partial or complete receipt settles its selected charge; **it never renews access**. Renewal explicitly extends the current date and creates a new immutable charge. Term edits affect future charges without rewriting previous periods. All operations retain durable request keys, payload comparison, revision checks and transactional platform auditing.

`subscription.manage` is a separate platform grant permission. Existing grants are not automatically expanded. Tenant Owner roles and administrator-set email verification timestamps do not authorize billing. Platform access remains password-based with optional personal MFA; sensitive billing submissions require password confirmation within 15 minutes, checked again inside the transaction. Reconfirmation returns to a company-bound reviewable draft and never replays a POST automatically. Passwords are never retained in that draft.

Defaults: reminders 14/7/1 days before renewal, seven **company-local calendar days** of grace. Renewal is the first unpaid date: a renewal on October 29 with seven grace days allows writes through November 4; read-only admission starts November 5. UTC calendar arithmetic follows conversion of the instant into the company's timezone, avoiding daylight-saving duration errors. Reminders are in-app indications, not delivered emails or scheduled jobs.

Enforcement requires **both** global `SUBSCRIPTION_ENFORCEMENT_ENABLED=true` and that company's policy flag. Both default OFF. Unmanaged companies retain existing workflows. Administrative company suspension remains a separate existing security boundary. Preview calendar states are labelled separately from effective write restrictions. Tenant notices expose dates/status/contact guidance without operator fees, receipts or internal notes.

## Server enforcement matrix

The existing permission, company-context, canonical event and immutable-history checks remain mandatory. `TenantWriteAccess` adds commercial admission; it grants no accounting authority.

| Surface | After grace with both switches ON | Boundary |
|---|---|---|
| Invoice/quotation/return drafts, conversion, posting and reversal | Deny writes | Canonical Sales actions and `OwnsStockEvent` |
| Purchases/returns, receiving, landed costs, vendor payments and reversals | Deny writes | Canonical Purchasing actions; purchase draft-line writer |
| Customer receipts/allocations, transfers and check transitions | Deny writes | Canonical Sales/Money/Purchasing actions |
| Stock movements, transfers and accounting postings/reversals | Deny writes | `InventoryMovementService`, `AccountingPostingService`, stock-event scope |
| Expenses, payroll, advances and reversals | Deny writes | `OwnsPhase7Event` and master-data actions |
| Products, parties, units, barcodes, images, brands, categories, warehouses | Deny writes | Catalog/image/quick-create services and existing direct Livewire transactions |
| Company identity, staff/roles, financial configuration, FX and numbering | Deny writes | Company/configuration actions, sequence writer, foundation services and sales bootstrap |
| New financial shares and catalog mutations | Deny writes | Issued-share/catalog services; existing share resolution remains readable |
| AI-generated economic writes | Deny writes | Same canonical business actions; no alternate economic writer |
| AI setup/policy expansion | Deny writes | Governance/quick-connect settings transactions |
| AI OFF/revocation, canceled proposals and retention/budget metadata | Preserve safety/operational behavior | Existing authorization and AI-OFF contract; these are not tenant economic history |
| Login, profile/security, company selection and authorized read/search | Allow | Existing authentication, membership and read permissions |
| Reports, existing documents, PDF/print/export, public-link resolution | Allow | Existing fresh read authorization/redaction; shared actor guards are not blanket-blocked |
| Reconciliation and derived-cache rebuilding | Preserve existing authorized maintenance | No new posting, stock history or editable economic authority |
| Platform provisioning, subscription receipts/renewals and platform audits | Allow authorized operator | Separate platform grants/password proof and commercial transaction |

Future mutation entrypoints must join the same canonical admission boundary. Static call-site inspection is supporting evidence, not proof that an arbitrary SQL writer is safe.

## Atomicity, concurrency and safety

Admission runs inside the canonical transaction before business effects, after the callback, and immediately before the framework's outermost PDO commit. Company then subscription row locks serialize policy/renewal changes with admitted writers. Checks use fresh locking reads, not a session entitlement cache or a potentially old repeatable-read snapshot. Wall-clock expiry is re-evaluated at final admission; a row lock does not freeze time. A denied final admission explicitly rolls back the transaction and leaves no open PDO transaction.

The guarantee is admission **immediately before PDO commit**, not an atomic lock on the wall clock. A tiny interval between checking time and the database finishing commit necessarily remains. No database lock is held across an AI provider call by this integration. Previously authorized external calls may still finish or incur costs; any later economic mutation must pass current canonical admission and existing AI policy checks.

Billing lock order is operator grant/identity, company, subscription, selected charge and receipts. The company lock serializes competing operators. Exact current receipt reads prevent overpayment even if a caller previously opened an older repeatable-read snapshot. Identical retries converge to one receipt; changed payloads and stale revisions fail. Audit failure rolls back all commercial records. Cross-company charge ownership and currency matching are enforced server-side.

Existing product-image deletion is deferred until commit when enforcement is enabled, so late admission rejection cannot remove an existing upload. Filesystem writes are not database transactions: existing nested-upload rollback/orphan-cleanup limitations are not claimed to be solved by commercial admission. No financial calculation, posting formula, immutable snapshot policy, dependency version or project licensing policy is changed.

## Verification record

- Codex focused MariaDB gate before the last read-side/default-row correction: **35 tests, 263 assertions, exit 0**, including real concurrent worker processes, stale-snapshot overpayment rejection, expired writes and an actual delayed outer commit.
- Final focused migration/default-row checks: **12 tests, 82 assertions, exit 0**. They verify all four populated-table downgrade refusals, empty downgrade/reapply, company isolation, sequence admission, readable settings/export/profile and unchanged economic rows. Final frozen-source full QA remains **pending**. Do not substitute focused passes for full acceptance.
- Browser checks used one isolated synthetic application/database with AI disabled. Arabic RTL and English LTR at 390/768/1440px had no document-level horizontal overflow or untranslated subscription keys. All three review/edit forms moved focus into review and restored the correct field using keyboard Enter in both languages. No browser billing submission or production transaction was made.
- Evidence: [responsive and keyboard observations](evidence/ms2/browser-checks.json), [Arabic mobile form](evidence/ms2/billing-mobile-ar.jpg), [English desktop billing/history](evidence/ms2/billing-desktop-en.jpg). Screenshots contain fictional fixture data only.
- The owned browser database/schema and web server were stopped and removed from service. Protected private-file hashes and preserved translation stash were verified.
- Windows symlink limitations, physical-device/scanner testing, assistive-technology speech and production activation remain separate checks. No Linux pass or Owner UAT is inferred from browser automation.
- No performance improvement is claimed. Global enforcement OFF skips subscription-policy queries in commercial admission and tenant notices; enabled-mode lock/query cost requires measurement before broad activation.

## Migration and deployment boundary

`2026_10_29_100000_create_platform_subscription_records.php` adds four platform-only tables. It does not alter tenant financial tables or existing history. MariaDB DDL implicitly commits: an interrupted migration may leave partial tables, so inspect and repair the exact allowlisted schema state before retrying; never use destructive reseeding or blind migration rollback.

Before a separately authorized deployment: pin reviewed SHA/locked packages/build; confirm actual host compatibility, no unexpected migrations and both enforcement defaults OFF; capture a fresh database/files/config/key snapshot with verified off-host custody and isolated restore; compare financial/security/upload fingerprints and all six reconciliation domains. Run only the accepted forward migration under controlled maintenance, warm caches, verify AR/EN authorized reads, billing separation and application errors, compare preservation fingerprints and reopen only when gates pass. Do not create real trial receipts, enable enforcement or call a provider for verification.

Rollback retains populated commercial tables/history. `down()` refuses before its first drop if **any** commercial table is populated; an empty-schema downgrade/reapply is tested separately. Never delete receipts or overwrite later business transactions to force rollback. Keep enforcement OFF while resolving a compatibility problem through the accepted source rollback or a reviewed forward fix. Activation for real companies requires independent source review, final policy acceptance and the verified enforcement matrix.

## Owner pilot checklist after separate release approval

Review the company subscription and preview status in AR/EN on desktop and a phone. Check configure/review/edit, explicit renewal versus receipt behavior, validation/reconfirmation recovery and exact outstanding values using synthetic fixtures first. With separately authorized pilot enforcement, confirm grace/calendar boundaries, denied stale writes, readable historical reports/exports, personal security access, company isolation and AI OFF behavior. Physical-device results remain Owner testing until actually recorded.
