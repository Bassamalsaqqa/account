# Platform Owner impersonation

This document describes the current full operational delegation contract. It supersedes the earlier read-only support interpretation; the compatibility routes and class names still use `support`.

An explicitly authorized system administrator can act as the selected active company's single active Owner. Normal tenant workflows include product/party management, transaction preparation and canonical posting, stock, payments, and permitted company settings. Existing accounting, permission, company-ownership, confirmation, idempotency and immutable-history boundaries remain authoritative. A tenant Owner role alone grants no platform access.

## Authority and identity

Entry requires the new explicit `company.impersonate` platform grant, a fresh platform session proof, the administrator's own current password, and an operational reason. The old `company.support` permission does not grant this write-capable feature. Deployment never grants every user access; the authorized system Owner's privilege is assigned through the existing trusted-host `platform:operator` procedure. MFA remains optional.

The persistent login, database session and remember identity remain the real administrator. Only the request-local authenticated actor and CompanyContext become the effective Owner. They are cleared/restored in `finally`, including exceptions and Livewire requests. No target password, login, membership, MFA/recovery data or credential is created or changed to establish delegation. A target who separately holds platform privileges can still be impersonated; those privileges are not inherited.

Sessions rotate on entry/exit and expire after 15 minutes. Every request rechecks current grant/revision, administrator credential proof, selected company and current Owner membership. Database sessions must belong to the real administrator and remain unexpired. Old read-only session states are rejected, never silently upgraded. Personal authentication/recovery and platform administration remain real-administrator operations; exit before using the platform directory to select another company. Password-confirmation screens operate on the real administrator. AI settings confirmations also check that administrator's password while retaining the effective Owner's company permissions. No provider is activated by impersonation itself.

## Canonical writes and truthful provenance

Normal signed Livewire and financial API requests run through existing tenant guards. Financial command proofs bind BOTH the effective actor and the real database-session actor; the actual administrator session is locked and checked against current credentials. Foreign-company routes, stale components and command payloads remain subject to the original tenant boundaries.

`TenantWriteAccess` rechecks delegated authority at canonical admission and immediately before the root PDO commit, including when subscription enforcement is OFF. The company-first lock order is preserved. Expiry, revocation or an audit failure refuses the commit and rolls back the complete event. This does not create an alternative financial writer or change valuation/calculation behavior. No external provider request is made under these delegation locks.

Existing business `created_by`/`posted_by` fields retain their effective-actor semantics. The real administrator is recorded separately and atomically: tenant AuditService events carry the actual operator plus effective Owner/correlation metadata; canonical product create/update, stock movements and posting batches add immutable platform action records referencing their objects. Canonical root commits add an administrator write record. Start/end record the actual administrator, company, Owner and reason. Passwords, session identifiers, recovery data and credentials are excluded.

A permanent administrative banner identifies the two actors, company and deadline and provides a CSRF-protected exit. Locale changes affect only the administrative session. Exit removes authority immediately and revokes its correlation through a bounded deny-only cache and durable append-only audit. Late responses cannot revive an ended session. Previously admitted reads can finish. A write that linearizes before revocation may commit; subsequent admissions and final commits check current authority. If one revocation store fails, the other is used. If both fail, current platform proof is removed; no durable-revocation success is claimed, and the absolute expiry remains.

## Trusted-host opening inventory

The separately reviewed `inventory:opening-import --as-owner` CLI mode requires trusted server-shell custody, an actual active administrator with `company.impersonate`, an exact company/source/plan, and interactive exact-plan confirmation. It resolves the effective Owner server-side without customer credentials or membership changes. A numeric actor ID or environment flag is not authority.

The plan binds `actor_id` to the actual administrator and `effective_actor_id` to the effective Owner. Dry-run remains database-enforced read-only with no audit/session/product writes. APPLY encloses delegation audit, the entire canonical import and its completion receipt in one transaction. A failed leg rolls back everything. Repeating the exact import adds no products, stock or financial effects; a new administrator session may add its own truthful access/write audit records. See [controlled import](CONTROLLED_OPENING_INVENTORY_IMPORT.md).

## Release qualification

Use isolated synthetic MariaDB fixtures for write, revocation-at-commit, stale-session, real database-session, tenant isolation, financial API and rollback tests. Verify AR/EN desktop/tablet/mobile rendering and keyboard lifecycle through the supported browser tooling. Retain failed diagnostic attempts as historical evidence. Production deployment requires the reviewed source, fresh recoverable backup, preservation fingerprints and six reconciliations. Actual customer APPLY remains an explicit approved business event, never a UI test.
