# Platform Owner Impersonation

Current operational delegation contract (supersedes earlier read-only `support` interpretation; compatibility route/class names retain `support`).

## Authority & Session Lifecycle

- **Entry:** Requires explicit `company.impersonate` platform grant (via `php artisan platform:operator`), fresh platform session proof, real admin's current password, and operational reason. Old `company.support` and tenant Owner roles confer zero access. MFA remains optional.
- **Actor isolation:** Persistent login/DB session/remember identity stays the real administrator; only request-local actor and `CompanyContext` become the company's active Owner, unconditionally cleared/restored in `finally`. No target credentials/membership change.
- **Expiry & revocation:** Sessions rotate on entry/exit and expire after `15` minutes. Every request rechecks grant/revision, credential proof, selected company, and active Owner membership; personal security/platform browsing remain real-admin actions. Banner displays both actors, company, and deadline with CSRF exit (deny-only cache + append-only audit).

## Canonical Writes & Trusted-Host Import

- `TenantWriteAccess` binds both effective Owner and locked real-admin DB session, rechecking delegation at admission and immediately before root PDO commit (preserving company-first lock order; expiry/revocation/audit failure rolls back atomically).
- Business `created_by`/`posted_by` track effective actor; immutable `AuditService` events, stock movements, and posting batches separately log the real administrator without credentials/session IDs.
- `inventory:opening-import --as-owner` requires trusted shell custody, `company.impersonate`, exact source/plan hashes, and interactive confirmation (see [Controlled Opening Inventory Import](CONTROLLED_OPENING_INVENTORY_IMPORT.md)).
