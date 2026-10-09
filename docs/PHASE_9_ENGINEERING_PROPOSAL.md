# Phase 9 engineering proposal — Documents, QR, catalogs and sharing

**Status: PROPOSAL / AWAITING ARCHITECT REVIEW. Phase 9 implementation: UNSTARTED.**

Prepared 2026-10-09 against main `28c2a53f84a8a0525ade6e1be7cee31ac17388ba`, after [independent Phase 7–8 production acceptance](PHASE_7_8_PRODUCTION_ACCEPTANCE.md). Re-fetch and verify the exact approved current baseline before implementation; documentation evolution must be distinguished from runtime drift.

This proposal is not implementation, a migration authorization or a production authorization. Codex owns final integration; AGY receives bounded packages after the architect accepts the contracts below.

## 1. Authority, objective and boundaries

Authority: Master Specification §§16, 44–48, 65–67, 70, 77–80; Engineering Blueprint §§56–62, 67, 99–100 and 106; accepted ADRs, particularly 0004, 0005, 0006, 0007 and 0008. Existing settings references inform settings controls only. No approved full document/catalog visual reference was found; AR/EN layout approval must be obtained against concrete previews during the future phase.

Goal: an operator can consistently view, print, download and deliberately share appropriate documents and a selected live product catalog, with truthful historical identity, exact currency/unit labels and safe access.

Preserve every accepted accounting, inventory, allocation, event-authority, Check and reporting contract. No financial writer changes; no mutable balances; no financial numbering during output. Document rendering must not post, reverse, change lifecycle, consume a sequence or create a public share automatically.

Exclude Phase 10 hardening implementation, new accounting features, delivery-note business flows, bulk/automatic messaging campaigns, WhatsApp API integration, tracking/marketing integrations, OCR, camera-scanning infrastructure, electronic invoicing compliance claims and generic background infrastructure. XLSX is optional future scope, not required to complete this proposal.

## 2. Reuse inventory and demonstrated gaps

| Existing implementation | Reuse | Phase 9 gap |
|---|---|---|
| `PdfDocumentController` and five Sales routes in `routes/web.php` | Quotation, Invoice, Sales Return, Customer receipt and Customer Statement output; `format=print` | Consistent operator affordances; Purchasing and other meaningful private documents |
| `DocumentData`, `DocumentDataBuilder`, `DocumentRenderer`, `PrintRenderer` | Explicit whitelist DTO and shared presentation data | Source-specific private adapters; dual-currency receipt allocation presentation |
| `PdfRendererService`, locked mPDF and Endroid QR dependencies | Arabic/English A4 rendering and local server QR | QR routing/settings integration; page/media/resource bounds |
| `CompanyDocumentSettings` | Existing logo/QR/footer/terms/photo settings fields | Real settings UI and consistent renderer consumption; current settings cards are placeholders |
| `PublicShareService`, `PublicShare`, public controller/template | Hashed token lookup, encrypted token recovery, opt-in create/revoke, password/expiry and whitelist | Catalog/receipt eligibility, selected statement scope, safe QR, dedicated delivery UX and security convergence |
| `QuotationMedia` and Product media rules | Approved optional quotation photos | Catalog reuse; missing/deleted-image fallback and explicit historical-media policy |
| `ProductBarcode`, Invoice scanner lookup, Purchase search | Company-unique Product/unit barcode facts and existing exact lookup | Clearly distinguish Product barcodes from document QR; printable identifiers only if approved |
| Purchase/Purchase Return read models, `VendorFinancialRead` | Stored Vendor/Product identities and cost-redacted private reads | Private Purchase, Return, Vendor Payment and Statement document adapters |
| Phase 7 Expense/Payroll reads and historical Check party helper | Sensitive source boundaries and frozen identities | Private output adapters for meaningful operational documents |
| Phase 8 Registry/Presenter/PresentationPolicy and CSV snapshot/delivery | All-69 source capabilities, financial columns, frozen periods, exact rows/totals | Dedicated bounded print/PDF projection; no second report calculation engine |

Source inspection confirmed these incomplete paths:

1. The normal PDF controller does not supply an existing authorized share URL to QR-capable rendering. The public template accepts QR data but its service does not populate it.
2. Receipt line presentation uses Invoice `allocated_amount` under the receipt's Payment currency. The accepted dual-currency facts must be presented as two explicit legs; no allocation math changes.
3. Customer Statement shares store a Customer subject and re-query the full live account; they do not preserve the selected statement range/cutoff.
4. There are no public `Catalog`/`CatalogItem` business models or routes. Existing services named Product/Customer/Vendor Catalog are master-data search services, not shareable catalogs.
5. There is no application document-email/WhatsApp sharing flow. Contact fields are not delivery.
6. Existing PDFs do not imply that current logo/style settings or all historical image bytes are frozen. Do not make that claim retroactively.

## 3. Output/security contract to freeze before coding

Proposed source matrix:

| Source | Private view/print/PDF | Public eligibility | Required source boundary |
|---|---|---|---|
| Quotation | Draft preview marked Draft; eligible issued history | Existing non-draft eligibility | Quote view + PDF/share action capability |
| Sales Invoice / Sales Return | Draft preview or immutable posted/void history with truthful status | Existing posted eligibility; later void invalidates share as today | Corresponding Sales view + action capability |
| Customer receipt | Immutable posted/reversed payment, explicit allocation currencies | Add opt-in posted eligible receipt | `money.receipt.view` + source-specific PDF/share capability |
| Customer Statement | Exact selected Customer/range/cutoff/currencies | Add explicit selected-scope metadata | Existing Customer Statement boundary + action capability |
| Purchase / Purchase Return | Private commercial document; distinct authorized acquisition section | **Disabled** | Purchase/Return authority and existing Vendor/cost read rules |
| Vendor Payment / Vendor Statement | Private settlement/statement | **Disabled** | `VendorFinancialRead` and action capability |
| Expense | Private operating document; Landed Cost clearly labelled | **Disabled** | Expense view; Landed Cost additionally requires cost read |
| Employee Advance / Salary Entry / Salary Payment | Private only, archived Employee readback preserved | **Disabled** | Existing Phase 7 source financial-read boundary + action capability |
| Money Transfer / Check | Private operational voucher/status; frozen party/bank identities | **Disabled** | Existing Money direction/source visibility + action capability |
| Inventory adjustment / transfer | Private operational voucher; cost section separately authorized | **Disabled** | Existing inventory source authority; cost read for value |
| Phase 8 reports | All meaningful supported variants through explicit print/PDF adapters | **Disabled** | Registry + query source permissions + authorized columns |
| Product catalog | Authorized preview/print/PDF | Opt-in live selected catalog | Proposed catalog capabilities; explicit price/media whitelist |

Implementation must freeze exact permission names against the existing catalog in package P0; this table is not authority to make private sources public. Purchase/Vendor finance sharing remains disabled under ADR 0005. General document visibility must not imply payroll/cost access or permission to share.

Recommended new catalog permissions: `catalogs.view`, `catalogs.manage`, `catalogs.share`. For missing private export capabilities, prefer narrowly named source-group PDF permissions rather than one broad bypass. Reuse `sales.document.pdf/share` and report source capabilities where they already fit. New permissions are provisioned through accepted idempotent infrastructure: Owner gets missing grants; every customized non-owner grant stays identical; no fake Company/User/document and no counter consumption.

Each action validates fresh actor, active Company membership, source Company and underlying financial authority before retrieving restricted values. Revalidate on every request/download/revoke; a route/public ID/hidden button is not authorization. Build private and public DTO projections separately where their authorized fields differ. Never serialize an Eloquent source graph into the client/public template.

## 4. Historical identity and exact economic presentation

### Immutable commercial documents

- Use posted company/party/line/tax/conversion/currency/rate snapshots. Current Customer, Vendor, Product or Employee rename/retirement must not rewrite historical labels.
- Use Check `party_snapshot` and frozen settlement Bank rules already accepted.
- Show canonical business dates, document numbers, explicit posted/void/reversed/returned states and linkage where authorized.
- Draft preview uses current editable data and is conspicuously Draft; it cannot acquire public financial eligibility.
- Commercial Purchase values stay in transaction currency. Landed Cost/acquisition values stay in stored base currency and are separately labelled/authorized; never represent them as Vendor price.
- Use exact decimal strings/BigDecimal formatting with currency precision, including permanent JOD 0.001 and negative reversals. Preserve numeric/code LTR isolation inside Arabic text.
- Transaction-unit lines show their frozen transaction unit. Aggregated base quantities use authoritative base units or an honest unavailable fallback.
- Customer/Vendor receipt/payment allocation lines show payment consumption amount/currency, document relief amount/currency and settlement-base amount/currency when applicable. Reuse immutable allocation/application history; never recalculate from current FX.
- Rendering has zero writes to economic tables, snapshots or numbering.

### Statements/reports

Reuse accepted historical Customer/Vendor/Payroll/query services. Preserve canonical void/reversal business dates, exact selected range/cutoff and currency legs. Opening, activity, closing and aging must share one historical universe.

Freeze validated filters, Company timezone and currency labels before output preparation. Reuse permission-based columns for empty/out-of-range results. Money per-movement running values remain unavailable when hidden sensitive rows make truthful disclosure unsafe.

The known Customer/Vendor full-history hydration limit remains documented. Printing/PDF does not justify removing resource limits or claiming bounded SQL hydration that is absent.

### Style/media policy recommended for approval

Financial identity/economics remain historical. Layout/theme is a current renderer version, explicitly not a legal byte-identical reissue guarantee. Use stored Company logo/media provenance where available; never overwrite old posted snapshots to adopt current branding. Missing historical media gets a truthful placeholder. Accepted quotation photos may resolve currently approved Product images as ADR 0004 allows. Freezing image bytes or template versions requires a separate approved forward contract, not silent backfill.

## 5. Shared links, QR and public safety

Extend the existing share engine; do not create a second token store or polymorphic serializer.

- Preserve actual schema names (`encrypted_token`, `is_active`) and accepted hash/encryption/expiry/revocation.
- Validate both sharing capability and source read authority for creation/recovery/revocation. Same-company subject mapping is explicit.
- Public resolution requires valid token, active share, expiry/password checks and still-eligible source; no ambient Company fallback.
- A QR encodes only an explicitly authorized secure share URL. No Customer/Employee information, totals, internal ID or raw token in an unrelated QR payload. PDF generation alone never creates a share.
- Add receipt shares through a specific safe DTO/source adapter; no private attachment automatically joins the share.
- Selected statements store an immutable validated range/cutoff/currency scope. Recommended V1: **fixed selected business-date scope with clearly labelled live recomputation within that scope**, reflecting subsequently entered valid backdated events. This is not a frozen byte-level statement snapshot. Existing full-account links remain truthfully labelled legacy live statements; do not silently narrow/rewrite them.
- If the architect requires a frozen issued statement instead, a separate private generated artifact/version/provenance contract is necessary before coding.
- Password submission uses POST, not a URL query password; bounded attempts/throttle, CSRF/session handling and no plaintext password/token logging.
- Deliver private/no-store financial responses and explicit referrer policy; avoid third-party resources/analytics on token pages. URLs are bearer capabilities, including when password-protected.
- Revoked/expired/invalid/password-required states work in AR/EN and mobile without disclosing restricted source data.
- Existing share counters are nonfinancial metadata; public view should not execute financial actions. Source eligibility failures must fail closed.
- Email/WhatsApp sharing of a link is explicit; public exposure is never inferred from a click to preview/print.

Proposed forward schema work after acceptance: catalogs/catalog_items, and narrowly scoped immutable public-share statement filter metadata if required. No change to applied financial migrations or historical financial rows.

## 6. Live catalog contract

Use Blueprint §57 fields as a starting contract, reconciling names with current model conventions in P0.

- Company-owned ULID public identity; localized name/description; active state; show_photos/descriptions/SKU flags; **show_prices defaults false**; nullable explicit currency.
- Ordered selected same-company Product items; optional localized display overrides; optional exact custom selling price. Company/catalog/Product uniqueness and deterministic ordering.
- Product/media selectors are bounded/searchable and preserve selected items/bookmarks beyond record 100. No complete master catalog hydration into Livewire.
- Public DTO includes selected Product identity, approved image, public description/SKU and explicitly enabled selling price only. No cost, margin, stock quantities, acquisition history, supplier/customer/employee details or internal notes.
- Recommended priced V1: every displayed price is an explicit catalog item selling-price value in one enabled currency; no live FX conversion and no inference from purchase cost. Missing price is “Price on request,” not zero.
- Currency retirement cannot silently relabel prices; hide/refuse priced publication until the catalog is coherently configured. Price-less publication remains possible.
- Inactive/deleted/foreign Products are removed from live public content or shown unavailable according to one frozen policy; recommended hide unavailable items, keep authorized administration history.
- One accepted live link/QR follows deliberate catalog updates until expiry/revocation/disable/password denial. Catalog edits must not consume financial sequences or touch Product economic history.
- Existing Product barcode values may be shown only under explicit catalog identifier options; QR/catalog share URLs and Product unit barcodes are separate concepts.

Catalog label printing and phone-camera scanning are not assumed Phase 9 deliverables; Master lists scanner/camera enhancements as future capabilities. Preserve existing scanner input and uniqueness tests.

## 7. Sharing UX and delivery

Recommended mandatory V1: copy secure link, download PDF, WhatsApp deep link and email composition with user-visible recipient/message preview. The user sends the message. Encode URL/text correctly; never put a password in the generated link/message by default.

Optional application SMTP send requires an explicit architect/Owner decision:
- separately configured transport and authenticated, source-authorized Send action;
- validated recipient, bounded message/attachment, preview and clear confirmation;
- same-domain/private token handling, no hidden CC or automatic contact harvesting;
- clear failure/retry state, delivery audit without secrets, no duplicate share/economic event;
- synchronous bounded delivery or existing database queue with Hostinger cron; no required permanent worker, Redis, Node server or WhatsApp API.

No agent is authorized to send a real message during implementation/QA. Use mail fakes/disposable fixtures.

View/Print/PDF/Share controls must be consistent across Sales/Purchasing/private operational details. Hidden unavailable actions are backed by server denial. Downloads have safe sanitized filenames and correct MIME/disposition; avoid filename/header injection.

## 8. Render resource and Hostinger contract

Reuse the locked mPDF/Endroid dependencies and local fonts; do not add another PDF/browser rendering service.

Dedicated PDF-safe CSS, explicit A4 margins, repeat table headings, page-break controls, logo sizing, long-line wrapping and page numbers. Browser Tailwind layouts are not a PDF CSS engine. Test mixed Arabic/English/numbers and long names, notes, addresses, units, totals and image-free/image-enabled output.

Private render/temp files stay outside public root, unguessable and cleaned on success/failure. Never allow arbitrary URL/file paths to mPDF: approved local media only; block SSRF, filesystem escape, SVG/script/HTML payloads, oversized image dimensions and unbounded input. Output metadata and error messages contain no secrets.

Proposed initial synchronous PDF budgets for architect approval:
- commercial/operational documents: 500 lines, 50 pages, 15 MiB output, 15 seconds;
- reports/statements: 1,000 output rows, 50 pages, 15 MiB, 15 seconds;
- catalogs: 250 items, 50 pages, 15 MiB, 15 seconds.

These are engineering proposals, not currently implemented guarantees. Measure realistic mPDF fixtures before freezing them. Enforce preflight bounds and preparation deadlines; explicit refusal or user-narrowed selection is preferable to silent truncation. Do not hold database locks during HTTP download; prepare authorized data under bounded consistent-read semantics and deliver the private result after releasing the transaction.

Preserve Phase 8 CSV bounds **50,000 rows / 50 MiB / 30 seconds** and authorization before each **64 KiB** chunk. Streaming cannot retract bytes; PDF/spool delivery needs the same honest pre-delivery/best-effort subsequent-chunk policy. No background export system is required.

## 9. Bounded execution packages and dependency order

All future work occurs on one `phase/9-documents-catalog-qr-sharing` branch after exact-baseline verification. Worker worktrees are isolated; Codex controls integration and independently reads source/tests.

| Package | Owner | Depends on | Deliverable and hard acceptance gate |
|---|---|---|---|
| P0 Contract freeze | Codex | Architect approval | Exact source/status/permission/DTO/public matrix, style and statement policy, schema names, render budgets and scope; no speculative financial changes |
| P1 Private source adapters | AGY, scoped by source family | P0 | Extend existing DTO/render pipeline; Purchasing/Vendor, operational/private Phase 7, dual-currency receipt correctness; rename/retirement/currency/redaction/zero-write tests |
| P2 Templates/settings | AGY | P0 + adapter contracts | Wire existing settings and consistent print/PDF UI; dedicated AR/EN A4 preview, multi-page raster verification and no restricted serialization |
| P3 Sharing/QR boundary | Codex | P0, P1/P2 DTO contracts | Reuse share engine; source-specific authorization, receipts and selected statements, password/revoke/expiry/QR; complete adversarial whitelist tests |
| P4 Catalog schema/admin/public | AGY | P0 + P3 integration contract | Forward catalog schema, Owner-only idempotent catalog permission upgrade, bounded selection, selected-only public DTO, optional exact prices and live-link lifecycle |
| P5 Delivery UX | AGY | P2/P3/P4 | Copy-link/WhatsApp/email composition with preview; no automatic messages; configured SMTP only if separately approved |
| P6 Report print/PDF | AGY templates, Codex query/security review | P0/P2 | Explicit all-applicable Registry projections, truthful filters/currencies/units, empty/populated restricted columns, resource limits and consistent output preparation |
| P7 Final convergence | Codex | P1–P6 complete | Independent diff/source review, one final focused QA gate, coherent docs/PR/candidate and architect handback |

Parallelize P1/P2 after DTO contracts are frozen; P4 may prepare isolated schema/admin work after public DTO/share interface approval. P5 cannot invent token/source contracts. P6 cannot invent report math. Integrate schema/security changes sequentially before dependent workers proceed.

Every AGY package brief must include:
- exact input SHA/worktree and allowed file inventory;
- locked source/permission/data contracts and explicit exclusions;
- required permanent tests and focused commands;
- no commit/push/merge/deploy/production access unless separately instructed;
- preserve Owner files and other workers' files;
- return diff, actual test evidence, blockers and known limitations.
Worker reports are evidence, not acceptance. Codex reviews each diff plus mature analogous flows, reproduces important failure cases and owns one converged candidate. No per-package Owner approval cycle.

## 10. Permanent regression and final acceptance matrix

### Data correctness and immutable history

- Customer/Vendor/Employee/Product/Company rename/retirement does not change historical identity.
- Posted invoice/return/receipt/Purchase/return/payment/Expense/Payroll/Check documents use exact stored facts and honest states.
- One carton of 12 plus five pieces remains truthful where base aggregate quantities appear.
- ILS/USD/JOD 0.001; changed historical FX; dual-currency application/reversal; explicit payment/document/base legs without duplicate totals.
- Historical statement cutoff before/after canonical void/return/reversal; selected scope, opening/closing/aging coherence.
- Purchase acquisition costs separate from Vendor commercial amounts; payroll sensitivity never falls back to identity permissions.
- Cryptographic financial-table/counter fingerprints prove output has no economic mutations.

### Security and sharing

- Cross-Company IDs/media/settings/share subjects fail; fresh membership/role revocation denies next request.
- Restricted cost/profit/payroll fields absent from DTO, Livewire, HTML, PDF extracted text and CSV/print headers, including empty pages.
- Token hash/encrypted recovery, password attempts/expiry/revoke/disabled source, no query passwords/secret logging and no unsolicited public link.
- Catalog selected-only products and prices-off default; missing/disabled currency/media/source states.
- MIME/path/SSRF/file escape, long-name/header injection and unapproved attachment exposure.
- User-initiated send only; mail fakes; no live recipients/test records.

### Rendering and capacity

- Real PDF signature, readable AR/EN text, page counts and raster inspection for representative Sales/Purchase/private operational/report/catalog output.
- RTL/LTR, desktop/mobile preview, A4 printing, long/multi-page lines, repeated headers, fallback fonts/images and exact currency/unit labels.
- Bounded report/catalog selectors with at least 125 entities; selected/bookmarked value retention.
- Over-budget refusal, private temp cleanup, renderer failure, interrupted download and revocation-before-delivery tests.
- Practical PDF timing/memory evidence on safe representative fixtures; no performance claim from empty pages.

### Lean final gate

During packages, run only focused files. At whole-phase convergence run once:
1. complete Phase 9 suite;
2. directly changed inherited Sales document/share, Purchasing/Vendor, Money/Check/Phase 7 identity and Reporting projection/security/snapshot tests;
3. Pint, Larastan level 6, Blade compilation and production frontend build;
4. migration preservation/forward-backward-forward on a **guarded disposable MariaDB** only if catalogs/share metadata migrations are added;
5. relevant reconciliations once only if query/economic boundaries actually changed;
6. small representative AR/EN desktop/mobile/private/public/password/revoked/PDF/print browser matrix and visual PDF inspection.

Do not mechanically run whole-repository PHPUnit, old concurrency matrices or broad repeated browser QA. No CI consumption or production test data by default. Preserve previously accepted 69-report/CSV invariants.

Final candidate needs one PR, exact source SHA, changed files/schema/permissions, actual executed test counts/assertions, private-file cleanup/migration preservation evidence, real visual artifacts, fresh source authorization matrix, bounded timing evidence, known limits, main unchanged and clean tracked tree/index. Stop for independent architect review. No merge/deployment without later acceptance and explicit Owner authorization.

## 11. Proposed decisions for architect review

Recommended coherent V1:
1. Complete meaningful private document output across existing domains; keep Vendor/Purchase/Payroll/Expense/operational financial shares disabled.
2. Extend public sharing only to receipts, explicitly scoped Customer Statements and live Product catalogs.
3. Fixed selected statement business-date scope with clearly labelled live recomputation; no invented frozen statement evidence.
4. Current renderer style with immutable economic/party facts; no retroactive byte-identical logo/template guarantee.
5. Explicit catalog item selling prices in one enabled currency; prices off by default.
6. User-controlled WhatsApp/email composition mandatory; application SMTP send optional pending an explicit decision.
7. Approve resource budgets after fixture measurement; refuse oversized output without incomplete documents.
8. Barcode identity/scanner reuse only; camera scanning and label-printing enhancements remain outside this V1 unless explicitly added.

This closes planning, not implementation. After architect review, Codex should translate the approved proposal into exact worker contracts and one integrated Phase 9 execution plan.
