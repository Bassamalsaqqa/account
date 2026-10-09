# ADR 0009 — Documents, secure sharing and approved catalogs

Status: IMPLEMENTED / INDEPENDENT SOURCE ACCEPTANCE PENDING. No merge or deployment.

Baseline: `3f82b947d319d519cea6d25cecd7724bfc909672`.
Branch: `phase/9-documents-catalog-sharing`.
Contract: [accepted P9-0 execution lock](../PHASE_9_P0_DISCOVERY_AND_EXECUTION_LOCK.md).

## Decisions

The existing document DTO, mPDF renderer, canonical statement queries, public
shares, Product master, Product Units and barcodes remain the architecture. No
financial, stock, receipt, reversal, numbering or valuation writer is introduced.
All monetary, FX, quantity and allocation values come from their accepted exact
read models and stored source provenance. Current decorative branding is separate
from posted historical identity; missing immutable identity fails explicitly.

Four Purchasing/Vendor outputs remain authenticated and private. Vendor Payment
uses the existing VendorFinancialRead intersection. Restricted acquisition values
are omitted at the server DTO boundary. Original receipt/payment allocations and
later applications are separate private sections.

New financial grants use a neutral first GET/HEAD and a CSRF-protected POST.
The 15-minute session unlock is tied to that grant, token, subject revision,
issuance hash, password and expiry. Every sensitive format checks fresh Company,
source and grant validity, including after rendering. Legacy null-profile grants
retain their labelled behavior and revoke/reissue workflow.

New Statements and receipts use immutable, allowlisted canonical JSON, SHA-256
integrity and authenticated encrypted issuance content. Statements require a
password and a finite expiry. The original receipt disclosure cannot grow with
later applications. Quotation grants retire when their commercial source revision
changes. A failed decrypt, missing key, unsupported version or integrity mismatch
never triggers live reconstruction. See [key recovery](../PHASE_9_ISSUANCE_KEY_RECOVERY.md).

Publication authority is independent of Product editing. Catalog prices default
OFF and are absent from all public DTOs, JSON, HTML and PDFs. Priced publication
requires explicit permission, enabled currency, exact selling price, Unit and tax
basis. Approved text and image identity remain fixed until authorized publication
of a new revision. Current Product/Company eligibility is checked on reads;
missing approved media becomes a placeholder rather than today's primary image.

Published photographs are public marketing assets. Pausing, expiring, revoking or
disabling a Company blocks managed catalog destinations. Previously distributed
public-storage image URLs and cached/downloaded copies can remain accessible.
The administration UI explains this limitation. There is no new media proxy,
private image duplication or external messaging service.

Owner gets new capabilities. Existing non-Owner grants remain unchanged during
the explicit upgrade. New Administrators do not automatically receive Phase 9
capabilities. Protected publication, price and document-settings delegation
requires a current same-company Owner, including protected role assignment.

## Atomicity, retries and schema

Company-first transaction locks serialize issuance and publication. Request keys
are Company scoped and compared with normalized intent, not merely existence.
Issuance verifies encryption and rolls back share, content and minimal audit
metadata together. Catalog publication commits its approved projection and an
immutable publication receipt together. The `catalog_publications` receipt table
is required to recognize an old successful request after a newer revision; a
single last-request column would incorrectly republish on a delayed retry.

Two additive migrations add nullable issued-share columns and catalog tables,
including the publication receipts and composite same-company Product/Unit
constraints. Existing tokens, economic rows and Product records are preserved.
`down()` intentionally retains issued content and revisions; code rollback is
not a data downgrade. Forward reruns check the retained schema and refuse an
incomplete structure. No already-applied migration was rewritten.

## Bounded rendering and compatibility

Preparation bounds precede expensive rendering: 500 document lines, 1,000 statement
source entries, 256 KiB text and 4 MiB aggregate assets. Issuance additionally caps
canonical plaintext at 2 MiB and actual encrypted stored bytes at 4 MiB.
Issuance also checks the current MariaDB `max_allowed_packet` with a 64 KiB
transport reserve and rejects before creating an active grant when that effective
storage budget is lower. Local near-cap testing exposed this lower server budget;
no database global configuration was changed to make the test pass.
Catalogs have at most 250 selected products and 24 public cards per page. Barcode printing
has at most 100 stored symbols and 500 labels; overwide symbols fail instead of
being silently compressed into unreadable labels. Piece/carton meaning is retained.

mPDF uses private per-render temporary directories with cleanup, local fonts and
approved embedded images. Private delivery refuses output exceeding 50 pages,
15 MiB or 15 measured seconds. Guest PDFs use tighter 100-line/250-entry, 20-page,
4 MiB and 10-second bounds. Time limits are post-render delivery checks, not CPU
preemption guarantees. Local measured evidence is not a Hostinger resource SLA.
No required Redis, permanent worker, Node server, Chromium PDF service or Docker
dependency was added. Chromium is used only for development browser verification.

## Acceptance and deferred work

The [implementation handoff](../PHASE_9_SOURCE_ACCEPTANCE_HANDOFF.md) records exact
source, QA counts, actual PDFs, decoded labels, responsive evidence, migration
preservation and limitations. Worker completion is not acceptance; Codex reviews
and verifies integrated source. Owner authorizes a future merge and deployment
separately. Phase 10 hardening, Phase 11 API readiness, Phase 12 AI voice/text and
Phase 13 optional native mobile remain deferred, as frozen in P9-0.
