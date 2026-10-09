# ACCOUNTING — PHASE 9 ENGINEERING SPECIFICATION, EXECUTION ROADMAP & FUTURE AI/MOBILE STRATEGY

**Document type:** Product + engineering source-of-truth proposal and autonomous AI-agent playbook  
**Version:** 1.0 — 2026-10-09  
**Project:** Small Trader Accounting / Inventory / Business Management  
**Repository:** `https://github.com/Bassamalsaqqa/account`  
**Production:** `https://account.palsync.net`  
**Proposed Phase 9:** Documents, Print/PDF, Secure Sharing, QR, Product Catalogs & Labels  
**Status:** **PLANNED — NOT YET AUTHORIZED FOR IMPLEMENTATION**  
**Authority:** The product owner must approve scope and eventual merge/deployment. The existing Master Specification, Engineering Blueprint, implemented code, ADRs and accepted Git history remain authoritative when a conflict is discovered.

> **Reading instruction for Codex / AGY:** This plan is a detailed target and execution specification, **not a claim that every named feature already exists**. Evidence is tagged **[VERIFIED SOURCE]**, **[WORKER-REPORTED]**, **[PLANNED]**, or **[DECISION GATE]**. Revalidate exact source and production status before implementing. Do not use this file to authorize Phase 9 coding, merging, production access or deployment without the owner's explicit subsequent instruction.

---

## 0. Executive decision

We should **complete the core small-business document and sharing experience in Phase 9**, then run **Phase 10 production hardening**, create an **authenticated command/API and mobile-readiness foundation in Phase 11**, develop an **optional AI-assisted text/voice workflow in Phase 12**, and consider a **native mobile client in Phase 13 only if product evidence justifies it**.

A full chatbot is **not** necessary for the initial voice feature. The recommended UX is a **contextual microphone button** on Sales first, optionally supplemented by a small universal assistant launcher later. The app should transcribe a spoken request, resolve the intended entities, prepare a visible **draft**, show all accounting/stock consequences, and **require an explicit authenticated confirmation** before any posting. The LLM is an interpreter; it is never the accounting authority. Phase 9 does **not** require AI API keys or audio code.

### Product principles

1. **Arabic first**, with polished English and independent document-language choice. Native RTL/LTR, mixed-script product descriptions and numbers, phone/WhatsApp-friendly layouts.
2. **Small-trader-first UX**: simple labels, low click count, easy sharing, accurate business totals; don't invent a heavyweight ERP workflow.
3. **Exact financial history**: posted docs display stored transaction snapshots and historical money/FX/quantity facts. Changing a current Product/Customer/Vendor does not rewrite a posted document.
4. **Private by default**: nothing financial is public until specifically shared by an authorized actor. No invisible data leaks in HTML, PDF, QR, previews or social meta tags.
5. **Reuse proven code**: do not replace the accepted posting services, report queries, PDF engine, stock movements or share-token foundation merely to produce new output.
6. **Autonomous but gated delivery**: Codex leads and verifies bounded AGY packages; ChatGPT independently reviews one integrated phase/correction; owner authorizes merges and production changes.
7. **Hostinger shared-host compatibility**: PHP 8.4, Laravel 13, Livewire 4, MariaDB, local/private storage, prebuilt Vite assets. No mandatory Redis, Docker, WebSockets, long-running Node service or Supervisor.

---

## 1. Verified baseline, current checkpoint and evidence policy

### 1.1 Current Git/source identities — independently verified on GitHub

| Record | Exact identity / status |
|---|---|
| Repository | `Bassamalsaqqa/account` |
| Final documentation-only `main` | `28c2a53f84a8a0525ade6e1be7cee31ac17388ba` |
| Phase 7 accepted source | `e8a5dee856bc06da48b1313f8776d723af309b5e` |
| Phase 7 merge PR #15 | `c07559c92816f838532e4b9d1c1537065f50ad5c` |
| Phase 8 accepted source | `e8e3e28fb040875d3b55ad403bea54a037e71437` |
| Phase 8 accepted/merged source tree | `a71f567ba54b16b30d4d1d4643a14a960e0ff98f` |
| Phase 8 merge PR #16 | `96c310f30a07e97ab8e04d5afbf0b2bb805f4317` |
| Final accepted Phase 8 independent review | PR #16 review `5471206252` |
| PR state | #15 and #16 merged |
| Docs-only finalization relative to Phase 8 merge | Four documentation files; runtime source unchanged |
| Phase 9 | Unstarted at time of this plan |

The GitHub history is independently confirmed. **[WORKER-REPORTED — NOT YET INDEPENDENT PRODUCTION-ACCEPTED]** AGY deployed Phases 7 and 8 sequentially to Hostinger on 2026-10-09. AGY reports: prior Phase 6 runtime `51adf805...`, Phase 7 runtime `c07559c...`, Phase 8 runtime `96c310f...`, two private backups, 3 additive Phase 7 migrations, `phase7:bootstrap --all`, `reporting:bootstrap --all`, 69 report variants, six healthy reconciliations, live bilingual smoke, and no economic data mutations. Codex's **independent read-only production verification is in progress**. Do not claim production acceptance before that handback is reviewed.

If the verified live checkout is the Phase 8 merge (`96c310f...`) rather than the docs-only final `main`, that is expected **provided the runtime tree is exactly `a71f567...`**. Documentation reconciliation should then explicitly mark both Phases 7 and 8 as *deployed / independently production-verified* without manufacturing production evidence.

### 1.2 Application architecture

- PHP ^8.4; Laravel ^13; Livewire ^4/Volt; Tailwind/Vite; MariaDB/MySQL; Fortify authentication + 2FA capabilities; Spatie team-scoped RBAC; Brick Math; mPDF; Endroid QR; Intervention Image; PHPUnit/Pint/Larastan. See `composer.json` and `AGENTS.md`.
- Today: one active company, but **all data and access must be company-scoped** for future SaaS/multiple-company use. Supported currencies: ILS, USD and JOD; base currency per company; original currency and immutable base values retained.
- Canonical writers: `AccountingPostingService`, canonical Sales/Purchasing/Money/Payroll/Inventory actions and domain services. No output/print/catalog/share code may perform implicit posting or mutate posted economics.
- Arabic/English interfaces and per-document locale are distinct; responsive mobile/tablet/desktop experience is expected.
- Accepted reports: 69 variants, secure CSV with a private repeatable-read spool; **50,000 rows / 50 MiB / 30 s**, with best-effort authority checks between streaming chunks. Customer/Vendor statement full-history hydration remains a known performance limitation.

### 1.3 Product/customer baseline

Target: a small wholesaler, retailer, distributor or trader who deals in products, vendors, customers, warehouses, unit conversions, expiry lots, purchases, sales, credit balances, cash/bank/checks and basic salary/expenses. Operators are not necessarily accountants. A frequent use case is **sharing a quotation, invoice, receipt or a selected product catalog via WhatsApp**. Catalogs must be useful **without displaying prices** by default.

### 1.4 Authority hierarchy and drift protocol

1. Deployed canonical behavior and latest accepted code; 2. `docs/SMALL_TRADER_ACCOUNTING_MASTER_SPEC_v1.0.md`; 3. `docs/ENGINEERING_BLUEPRINT_v1.0.md`; 4. relevant ADRs; 5. this approved Phase 9 plan; 6. visual references (`references/ui/`) where relevant. If a material conflict occurs, Codex documents it and requests **one bounded architectural disposition**, not silent behavior changes.

Before implementation, compare `main`, docs, recent PRs and live acceptance; record any changes since `28c2a53...`. **Do not write to production while auditing.**

---

## 2. Implementation inventory — reuse vs missing functionality

This inventory is based on the repository source at `28c2a53...`. An item listed as existing is **not** an assertion of perfect functionality or production coverage; it is an entrypoint to audit.

| Capability | Source evidence | Phase 9 stance |
|---|---|---|
| Sales PDF/print controller | `app/Http/Controllers/PdfDocumentController.php` | **[VERIFIED SOURCE]** Quotation, Sales Invoice, Sales Return, Customer Payment and Customer Statement PDF/print endpoints exist; preserve and harden. |
| Common Sales document DTO/rendering | `app/Domain/Sales/Documents/DocumentData.php`, `app/Services/Sales/{DocumentDataBuilder,DocumentRenderer,PdfRendererService,PrintRenderer}.php` | Reuse the shared `source -> view-model -> HTML -> print/mPDF` design. Do **not** call mPDF from Livewire/Blade. |
| Sales PDF templates | `resources/views/pdf/{document,quotation,sales-invoice,sales-return,customer-payment,customer-statement}.blade.php` | Audit template coverage and consistency, Arabic glyph/layout accuracy, page breaks, print fidelity and business data. |
| Sales routes | `routes/web.php` includes `/pdf/quotation`, `/pdf/invoice`, `/pdf/return`, `/pdf/payment`, `/pdf/statement`; `?format=print` exists | Continue authenticated per-document authorization; add only missing document types. |
| Existing secure-share foundation | `app/Services/Sales/PublicShareService.php`, `app/Models/PublicShare.php`, `app/Http/Controllers/PublicShareController.php`, `resources/views/sales/public-share.blade.php`, `routes/web.php` | **[VERIFIED SOURCE]** Has random opaque tokens, hashed lookup, encrypted token recovery, optional password/expiry/revocation, public allowlist and read DTO. Extend deliberately; avoid second token system. |
| Existing share types | Quotation, Sales Invoice, Sales Return, Customer Statement | Not equivalent to complete public catalog, Vendor or every Money document share. Extend with stricter subject-specific authorization and payload whitelists. |
| QR generator | `PdfRendererService::generateQrDataUri()` using `endroid/qr-code` | Already available; build authorized URL generation/use/size tests; avoid encoded raw invoice data. |
| Document settings | `app/Models/CompanyDocumentSettings.php`, settings migration | **Partially present**: default locale, show logo, QR default, quotation images, bilingual invoice footer/quotation terms. Audit live editing/UI and setting application before adding fields. |
| Historical Sales identities | `DocumentDataBuilder`, Sales posting snapshots and ADR 0004 | Posted document builder reads stored company/customer/line snapshots; don't silently resolve current master identity. Draft preview rules differ explicitly. |
| Purchasing vertical | `app/Livewire/Pages/Purchasing/{PurchaseDetail,PurchaseReturnDetail,VendorDetail,...}.php`, mature Purchasing actions | Business posting/returns/statement history already exist. **Purchasing PDF/print endpoints/templates are not present in the audited routes**; priority addition. |
| Vendor Payments | `app/Models/VendorPayment.php` and `app/Livewire/Pages/Purchasing/VendorPayment...` | Mature financial lifecycle; printable private voucher/payment advice to be specified and implemented, not a new payment writer. |
| Stock/units/images/barcodes | `ProductBarcode`, `ProductImage`, ProductUnit, Product Catalog/Image services; `product_barcodes` / `product_images` migrations | Data foundation exists. QR/label printing, catalog selection/appearance and portable exports require new UX. Do not assign invalid commercial GTINs. |
| Reporting | Phase 8 query and presentation layers; protected CSV export | Add bounded, authorized print/PDF to selected report types only after visibility/redaction parity is demonstrated. Avoid a second reporting engine. |
| Catalog public pages | No first-class catalog model/routes visible in audited source | **[PLANNED]** Implement Catalog definition, selected Products, presentation settings, public token URL, QR and lifecycle. |
| Document public share management UX | Backend exists; UI completeness unverified | Audit and fill missing create/copy/password/expiry/revoke/list/status UX. Do not claim no UI until tested. |
| Barcode scanning via phone camera | Barcode records exist | Future phase or optional later package; Phase 9 prepares label/QR UX without mandatory scanning implementation. |
| AI assistant, speech parser, authenticated command API | No accepted Phase 9 implementation exists | **Explicit future phases**, not Phase 9. |
| Native mobile application | Not V1 requirement | Future decision following API hardening and usage evidence. |

**Specific audit hypotheses to test, not assumptions to silently change:** current shared statement may represent a *live* statement, and existing public-share presentation/counting behavior needs scrutiny for abuse, stale/void subjects, password throttling, document authorization and cache headers. The existing generic PDF template and document options may already solve some requested output requirements. Confirm before duplicating.

---

## 3. Phase 9 boundaries, deliverables and non-goals

### 3.1 Primary outcome

A user can generate professional bilingual, printable/downloadable business documents, issue a secure share link/QR for approved subjects, and compose a safe product catalog (normally without prices), all while preserving posted-history accuracy, company scoping and existing transaction workflows.

### 3.2 Required V1 outcomes

**Documents:** consistent PDF/print/view buttons across suitable Sales/Purchasing/Money/Expense/stock document screens, with an explicit supported-document matrix below. Source data is correct, culturally legible, and accessible.  
**Catalogs:** build selected-Product public catalogs, configure images/descriptions/SKU/prices, choose language, update/deactivate/revoke, stable URL and QR.  
**Sharing:** one cohesive authorized manager to create, recover, copy, expire, password-protect and revoke allowed links. Copy/share via WhatsApp/email/native share where feasible.  
**QR & barcodes:** QR opens only approved share URLs; barcode labels use stored real code and correct unit.  
**Quality:** Arabic PDF/RTL, English PDF/LTR, mobile touch UX, security, reporting redaction, traceability and constrained Hostinger performance.

### 3.3 Explicitly NOT Phase 9

- AI API keys, mic button, speech processing, chatbot, natural-language posting;
- Native iOS/Android app, public general-purpose mobile API, multi-company subscriptions;
- E-commerce checkout, online payment or consumer account portal;
- Sending WhatsApp messages automatically or implementing an external messaging gateway;
- Auto-email delivery before provider/recipient/deliverability/security decisions;
- Changing any posted ledger, sales invoice, purchase, stock movement, cost, FX, posting sequence or canonical document number;
- Full POS, purchase-order/sales-order modules, rich OCR/import engines, serial-number workflows;
- Rewriting Phase 8 report queries or statement arithmetic;
- Mandatory barcode camera scanning (allow a future isolated enhancement);
- Introducing permanent workers or infrastructure services incompatible with Hostinger shared hosting.

---

## 4. Phase 9 feature requirements — complete functional contract

### 4.1 Supported documents: intended coverage matrix

| Subject | Present baseline | Target output in P9 | Public sharing default | Special requirements |
|---|---|---|---|---|
| Quotation | PDF / print / existing share | Harden design, images, QR, document locale, share UI | Opt-in allowed for finalized quote | Drafts remain private; source intent frozen per canonical rules. |
| Sales Invoice | PDF / print / existing share | Consistent A4 PDF + preview + QR | Opt-in posted only | Original amounts, discounts, tax, balances and FX exactly as source; void visibly marked. |
| Sales Return / Credit Note | PDF / print / existing share | Consistent return identity and original invoice reference | Opt-in posted only | Negative/credit conventions unambiguous; return/void history unchanged. |
| Customer Receipt | PDF / print exists | Reviewed receipt print/PDF, allocations and FX labels | **Private** by default; public share only if explicitly reviewed | Never conflate Cash/Bank receipt with Cheque settlement. |
| Customer Statement | PDF / print / existing share | Controlled period/as-of print/PDF, opening/running/closing | Extra-sensitive opt-in | Share must have explicit fixed period/cutoff semantics; bounded and revocable. |
| Purchase | No audited PDF route | New authorized Vendor Purchase voucher / goods receipt as appropriate | **Private** | Vendor invoice reference distinct from internal PUR, currency, taxes, lots only where allowed; snapshot truth. |
| Purchase Return | No audited PDF route | New authorized return/debit document | **Private** | Correct returned quantities, original Purchase reference and valuation context only for permitted roles. |
| Vendor Payment | No audited PDF route | New private payment advice/voucher | **Private** | Allocations, currency/FX, Check vs Bank/Cash, reversal status. |
| Vendor Statement | No audited PDF route | New bounded authorized print/PDF | **Private** | Exact AP aging/statement source; no reconciling in view; historical period. |
| Expense | No audited PDF route | Private expense voucher with optional attachment reference | **Private** | Landed Cost vs operating correctly labeled; attachments never publicly exposed. |
| Checks / Transfers | No audited PDF route | Private printable check register or transaction confirmation when useful | **Private** | Distinguish issued/received, clearing, return, reversal, source history; avoid simulated bank checks. |
| Employee salary/advance | No audited PDF route | **Deferred/optional within 9B only by gate**: private payslip/advance voucher | Never public | HR/salary confidentiality, distinct capability; must not expand without reviewed access model. |
| Inventory Stock Transfer / Adjustment | No audited PDF route | Private stock movement slip (approved company workflows) | Private | Warehouses, quantities, unit/lot and reference; no cost for stock-only users. |
| Reports | Phase 8 screen/CSV | **Selected** authorized print/PDF with same filters and redaction | Never public by default | Preserve report period, scope, totals and permissions; 69 types are NOT a mandate for 69 bespoke PDFs. |

**Priority:** Sales regression protection and Purchasing documents first, then Customer/Vendor statements/payment vouchers, then Expense/Money/Inventory print where operationally useful. Payroll and broad report PDF work are scope/permission gates, not automatic expansions. UI must not offer unsupported output buttons.

### 4.2 Document rendering contract

1. Read canonical same-company subject through an explicit `DocumentSubjectResolver`/adapter; reject unsupported status or foreign `public_id`.
2. Enforce source `view` **and** specific `print/pdf/share` capabilities at the server, not solely in Blade.
3. Build a typed, safe `DocumentData` or versioned DTO; source-specific adapters translate existing immutable party/company/line/payment snapshots without changing underlying writers.
4. Use shared presentation data for printable HTML and mPDF; accept differences only in print-safe CSS; never recalculate taxes/FX or quantities in Blade.
5. Document locale selected **independently** from interface locale, with explicit fallback and no hidden language switching.
6. A4 baseline, predictable multi-page continuation, document identification and page number on subsequent pages; no clipped rows, broken RTL glyphs or orphaned totals.
7. Dynamic current company identity is allowed only in the explicitly designated *draft* preview case; posted identities and financial facts must use stored posting snapshots.
8. Rendering performs **zero financial writes**. It may record audited share/view metadata only where explicitly designed, and cannot consume a business document number.
9. Protect private routes: `Cache-Control: private, no-store`, correct `Content-Type`, safe filename/`Content-Disposition`, `X-Content-Type-Options: nosniff`, no public CDN paths to private PDFs.
10. PDF errors fail closed and are logged without leaking raw customer, salary or accounting data.

**Architectural direction:** Extend the existing `DocumentDataBuilder`, `DocumentRenderer`, `PdfRendererService`, `PrintRenderer` thoughtfully; introduce a small interface/adapter layer only to avoid broad Sales-specific conditionals. Avoid a giant polymorphic `if` chain and avoid creating a parallel PDF engine in Purchasing.

### 4.3 Company document settings & branding

Audit CompanyDocumentSettings and company profile UI first. Provide grouped bilingual controls where missing: logo, displayed company identity/address/contact, tax/registration identifiers where defined, default document language and per-output override, footer text and quote terms, show/hide QR, include product images on quote, PDF orientation/margins if supported, safe file upload/preview. Never make editable formatting affect historical economic facts. A historical company snapshot must not be overwritten by current branding; if a logo is a versioned presentational asset, the snapshot/use policy must be explicit.

**Security:** logo and stamps are sensitive assets. Only company settings managers upload/edit; validate MIME and file signature, dimensions and maximum size, re-encode on ingestion, use private storage where appropriate, and never trust SVG/XML or embedded remote URLs for PDF generation without a reviewed sanitizer. Use product image service conventions.

### 4.4 Secure document sharing and QR

**Current foundation:** `PublicShareService` supports hashed lookup, encrypted raw token, expiry, password, active/revoke, and a source allowlist. Reuse its core model or an extracted service with backward-compatible routes. Do not invalidate existing links in production.

**New management UX:** From an eligible detail page: **Share** → choose output/lang/expiry/password (where allowed) → preview redacted external view → Create Link → Copy / WhatsApp / Email draft / Show QR / Revoke. A **Manage Shares** view displays type, subject, created date, creator, status, expiry, last access (if safe), and revoke; permissions apply to viewing or recovering raw share URLs. No bulk unsolicited sharing.

**Public endpoint guarantees:**
- Deny unless token is an opaque high-entropy capability and subject remains eligible and same-company; never expose internal incremental IDs.
- Hash for indexed lookup; revocation/expiry checked on **every** request and PDF/preview resource fetch. Support safe password hashing and throttled attempts (token/IP-aware with privacy controls).
- Rate-limit invalid tokens, password attempts and large PDF rendering. Distinguish user-visible invalid/expired/revoked states without revealing whether a guessed customer exists.
- Whitelist every public field by share subject; do **not** leak COGS, vendor buy prices, cost layers, net profit, ledger allocations, payroll, private notes, internal IDs, unpublished catalog products or file paths.
- Never publicly expose purchasing, payroll, expense attachments, bank transactions or private reports merely because the internal PDF exists.
- No-store/noindex; avoid sensitive Open Graph/social thumbnails, third-party pixels, referrer leakage and caching; restrict `Referrer-Policy` and external image fetches.
- Public share creation, recovery, revocation and export are audited with minimal sensitive metadata. The initial create action is explicitly authorized; a guessed link cannot create another share.
- For historical invoices/returns, the shared representation uses **posted snapshots**. For statements, choose **fixed period/as-of share** by default and avoid an accidental forever-live financial window; if live statements are ever enabled, require an explicit policy and stronger expiry.
- QR code encodes only the approved HTTPS share URL; if link is revoked the QR ceases to grant access. QR appearance and scanning reliability tested in print and on another device.

### 4.5 Catalog creation and management

**Core workflow:** `Products` → `Create Catalog` → search/filter Product list → select items → reorder → select language & layout → choose photo/description/SKU flags → **Show Prices: OFF by default** → preview private → publish share token → show/print QR and copy/share link. Public visitors view responsive cards without logging in; no add-to-cart/checkout in V1.

**Catalog data:** company, internal/public identity, name AR/EN, description AR/EN, visibility/active status, configured language, explicit fields, optional allowed display-currency/price policy, selected Product IDs + stable order, created/updated/owner, publication timestamps, audit metadata. Each public request re-authorizes catalog status and the share capability. No stock quantities, wholesale costs, supplier identity, internal margins, credit limits or unrelated company data can leak.

**Live versus frozen policy:** The Master Spec calls for the **same stable link/QR to show updated content**. Implement a managed **live catalog**: authorized owner may edit selected items/text/photos in place without changing the link; mutations are audited. For price-visible catalogs, decide at publication whether displayed price is a specifically approved *current list price* or a frozen catalog quote; never ambiguously claim historical quote prices. No financial posting or Inventory valuation depends on catalogs. Optional later catalog version history should be small and explicit.

**Pricing safety:** `show_prices=false` is the default and must be enforced on the **server DTO**, not CSS. Enabling prices requires named permission, company locale/currency display choice, clear price basis (retail/catalog), tax-inclusion label and owner acknowledgement. Never show a purchasing cost as catalog price. Show/hide fields verified in public HTML, JSON payloads (if introduced), PDF and browser source.

**Product images:** reuse trusted product image thumbnails/WebP and alt text. Empty image state is polished and usable, not broken. Avoid referencing private paths from a public catalog.

**Catalog lifecycle:** unpublished → published → paused/expired/revoked. Revoking or disabling must immediately invalidate public access and QR. Publish and update have optimistic concurrency/idempotency protection where appropriate; no silent overwrites between staff.

**Search and catalog usability:** company-scoped indexed Product search, category filtering and safe multi-select beyond first 100 records; selected items retained during pagination/search; list/grid accessible and performant on a phone. Existing Phase 8 bounded selectors are a precedent, not automatically reusable for public catalogs.

### 4.6 Product barcode and QR label workflows

- Stored `ProductBarcode` already has Product and optional Unit identity. Print labels for explicitly selected products/units and quantities, using an existing barcode value, not generated false GTINs.
- Support a **bounded** batch with tested page/label sizing, text overflow, SKU/name fallback, Arabic/English captions and clear **piece vs carton** unit labels.
- If a GS1/EAN/UPC symbol is requested, validate digits and check digit; use Code 128 or an appropriate generic symbology for an internal code. Never imply external registration/uniqueness without evidence.
- Preserve barcode-to-unit mapping; a carton barcode must not silently pick the piece unit.
- Internal product QR, if added, points to an **authenticated** product detail or explicitly public catalog item; distinguish from public share QR.
- Camera scan/input and scanner-to-sale flow are **future enhancements**, not Phase 9 exit criteria unless owner explicitly promotes them.

### 4.7 Sharing integrations and notifications

- **Copy link** plus Web Share API on supported devices; WhatsApp share URL and email `mailto:` with a concise localized message. User explicitly launches the external app; never silently transmit documents or confidential information.
- A recipient may not have app credentials: the secure URL/token is the access capability, subject to password/expiry. Do not embed full invoice financial data in a WhatsApp prefill; share only a URL and nonsensitive invitation.
- Server-side SMTP, automatic reminders, campaign messaging and delivery/open tracking beyond minimal approved share metadata require separate decisions and are **not Phase 9 V1 defaults**.

### 4.8 UI / visual direction

Navigation should make **Documents & Sharing** discoverable without bloating the sidebar. Use context actions on Quote/Invoice/Purchase/etc. pages, with one shared action menu (`View`, `Print`, `Download PDF`, `Share` where permitted). An optional Catalogs navigation item belongs with Products. A small document settings subsection belongs in Settings.

Use the established Stitch/approved screen references under `references/ui/` and current production design tokens: do not paste reference HTML/visual images as runtime code. Preserve typography hierarchy, empty/loading/error states, touch targets, accessible labels, keyboard use, focus restoration, 320px phone responsiveness, tablet and desktop tables, Arabic RTL and English LTR. PDF must use its own print-safe CSS and appropriate bilingual font fallback; browser layout tests alone do not certify printed PDFs.

---

## 5. Architectural design and prospective schema

### 5.1 Service decomposition (adapt to code; do not force a new architecture)

```text
[Authorized Livewire / Controller Entry]
              |
        [SubjectResolver]  <-- company / status / permission
              |
      [DocumentData / Adapter] <-- immutable posted snapshots
             / \
      [Print HTML] [mPDF binary]
              |
        [Delivery headers]

[Share Management UI] -> [PublicShareService / subject policy]
                                 |
                         [Hashed opaque token]
                                 |
                [Public whitelist DTO & QR URL]

[Catalog Composer] -> [Catalog records / ordered items]
                            |
                   [Authorized publish]
                            |
                   [PublicShareService]
                            |
                   [Public catalog view]
```

Core business actions **must not** acquire dependency on PDF/QR/AI services. All new code depends *inward* on existing reads/snapshots; rendering failure cannot roll back a previously valid posting nor cause duplicate numbering.

### 5.2 Suggested interfaces / DTOs

`DocumentSubjectAdapter` (subject type, tenant, canonical identity, status, permission set, frozen view model).  
`DocumentPresentationOptions` (document locale, template version, show images/QR, footer selection).  
`DocumentData` (reuse existing shape where possible, with optional sections for vendor, tax, payments, allocations, stock).  
`ShareSubjectPolicy` (explicit subjects eligible for public share, data fields, default expiry/password, status validation).  
`PublicCatalogReadModel` (strict field whitelist; no costs, quantity or operational secrets).  
`QrLinkPresenter` (validated HTTPS URL only).  
`DocumentAuditEvent` (reuse accepted audit-event infrastructure if applicable).

Be careful with contract proliferation: implement a new interface only if at least two source types genuinely share it and migration from current Sales services is testable.

### 5.3 Proposed forward-only schema change (only if needed)

Prefer adding `catalogs` and `catalog_items` (or names consistent with current conventions):

```text
catalogs:
  id, public_id/ULID, company_id,
  name_ar, name_en, description_ar, description_en,
  default_locale, visibility/status,
  show_images, show_descriptions, show_sku,
  show_prices DEFAULT FALSE, price_policy nullable,
  display_currency_code nullable,
  created_by, updated_by, published_at,
  created_at, updated_at, deleted_at nullable

catalog_items:
  id, company_id, catalog_id, product_id,
  sort_order, optional caption/visibility overrides,
  timestamps

public_shares:
  reuse existing table and secure token model;
  add 'catalog' to subject-type allowlist only after policy and integration tests;
  add fields only if an actual new control requires them.
```

**Indexes/integrity:** company composite FKs/ownership validation, unique `(company_id, catalog_id, product_id)` where duplicate display is not intended, order index, stable public ID, exact-company lookups. No soft-delete semantics that leave public access enabled. Public share table is already present; do **not** rebuild it. Public catalog settings are not financial document snapshots.

Any migrations must be **additive and forward-only**, with production backup, reversible *code* release plan, preserved table row counts and migration tests. Do not edit already-applied migrations.

### 5.4 Permissions proposal — review against existing catalog

Reuse `sales.document.pdf`, `sales.document.share` and current source-view permissions. Add only clearly needed actions such as `documents.purchase.pdf`, `documents.purchase_return.pdf`, `documents.vendor_payment.pdf`, `documents.vendor_statement.pdf`, `documents.expense.pdf`, `catalog.view`, `catalog.create`, `catalog.update`, `catalog.publish`, `catalog.share`, `catalog.revoke`, `catalog.show_prices`, `products.barcode_labels.print` (names illustrative; align with Spatie convention and existing permission catalog before migration/bootstrap).

Enforce the **intersection** of output action + source `view` + sensitive cost/payroll entitlement. Share holders should never get capabilities merely because they can open the public URL. Default role grants must be audited; owner provisioning idempotent; existing non-owner permissions unchanged unless explicit owner action. Negative tests for non-owner, removed role, inactive membership, Company A/B, unauthenticated direct URL, and bookmarked endpoints.

### 5.5 Rendering/storage/cache controls

- Prefer generated-on-demand authorized PDF; don't place unencrypted customer invoices in `storage/app/public` or `public_html`.
- Private temporary files with restrictive permissions and `finally` cleanup, bounded size and time; reserve a safe shared-host memory budget established from actual Hostinger limits.
- Avoid remote asset fetching by PDF engine. Re-encoded local logos/images only, limit resolution/page count and prevent SSRF.
- No public Eloquent serialization of sensitive columns; safe DTO allowlist only.
- Strong separation between private document download and token-authorized public display.
- QR and barcode output must have deterministic tests and scan validation, with no access escalation.

---

## 6. Work packages and dependency order

**Execution model:** one Phase 9 parent branch/PR with bounded internal packages. Codex delegates independent worktrees to AGY, reviews, integrates and performs one final comprehensive phase gate. No unapproved merges to main or production. Exact baselines and returned SHA evidence are mandatory. Package IDs are proposed, not yet active.

| Package | Owner suggestion | Depends on | Outputs | Independent checkpoint |
|---|---|---|---|---|
| **P9-0 — Inventory & design freeze** | Codex | Production verification handback | Route/permission/render/share audit; evidence matrix; confirmed gaps; UI acceptance reference board; bounded migration plan | Architect accepts target and non-goals before code |
| **P9-A — Output core & settings** | Codex + AGY presentation | P9-0 | Harden reusable document adapters/DTOs, bilingual branding/print helpers, no new writers | Historical snapshot and auth regression |
| **P9-B — Missing operational docs** | AGY bounded domain tasks; Codex integration | P9-A | Purchase, Purchase Return, Vendor Payment/Statement, selected Expense/stock PDFs/print with real canonical fixtures | Financial truth + permission review |
| **P9-C — Share/QR security convergence** | Codex security lead | P9-A | Subject policies, share manager UI, hardened token/password/expiry/revoke, signed QR URL rendering, no regression to existing Sales shares | Direct public-link threat test |
| **P9-D — Catalog composer/public catalog** | AGY UI + Codex data/security | P9-C; existing Product images | Catalog CRUD/ordered items, price-off default, localized public view, stable link/QR and update/revoke | Public DTO whitelist and mobile UX review |
| **P9-E — Barcode labels/share actions** | AGY UI/print; Codex validation | P9-A/P9-D | Unit-correct Code128/EAN handling as applicable; WhatsApp/mailto/copy/Web Share | Scan check, unit mapping, safety |
| **P9-F — Consolidated UX, regression and release prep** | Codex | P9-A–E | Navigation/detail actions, Arabic/English responsive, print QA, permission matrix, full scoped Phase 9 suite, ADR/handoff | **One integrated independent PR review** |

### 6.1 Package P9-0: pre-implementation audit

Codex must produce an exact inventory: existing features tested, incomplete/buggy, intentionally excluded. Check `PdfDocumentController`, all document detail pages, `PublicShareService`, `PublicShareController`, share table, `DocumentDataBuilder`, Blade PDFs, product images/barcodes, existing `CompanyDocumentSettings` UI, active RBAC permissions, audit events, and actual role provisioning. Inspect one real Arabic and one English PDF with representative fixtures. Identify the smallest set of required migrations. Do not code until the target is clear.

**P9-0 exit:** explicit V1 document matrix (which private PDFs genuinely ship), safe public share subject allowlist, proposed catalog model, UX sketches, permission table and future migration safety notes. Resolve ambiguities through product goals: default private, price off, Arabic first, no AI in P9.

### 6.2 Package P9-A: document engine and localization

Add read-only adapters, settings UI where absent, CSS for A4/RTL/LTR and consistent output naming. Preserve Sales behavior. Test 0/1/10/100 lines, no logo/broken images, Arabic mixed English SKU/currency, large terms, discounts/taxes, zero/negative signed amounts, 2–5-page continuation, special characters and portrait layout. Finance source values must be identical between DTO, HTML and PDF.

**P9-A exit:** all existing Sales routes still work, P9-B adapters can be added without knowing PDF internals, no economic writes.

### 6.3 Package P9-B: Purchasing and operational documents

Implement highest-value private documents first. Use posted Purchase/Purchase Return/VendorPayment source snapshots, original and base-currency context only where appropriate, precise tax and unit labels, Company/Vendor historical identity, and explicit reversal status. Vendor Statement must use the accepted authoritative payable/statement computation—**never construct AP from today's mutable vendor totals**. For restricted roles, omit cost-specific sections entirely. Payroll must stay out of default scope unless the owner accepts a salary-specific threat model. Document download errors and direct URL tampering get 403/404 without PII.

### 6.4 Package P9-C: share/QR security

Extend existing token system without invalidating published links. Subject-specific allowed states; password/expiration/revocation; replay/parallel generation; same-company ownership. Required tests: logged-out access for valid token; invalid 40-character token; token for other company; expired/revoked; password brute-force throttle; posted source voided/archived; public PDF authorization; raw token not stored in plaintext; no indexed/public PDF files; no COGS/other-company details in HTML/JSON/QR; URL becomes invalid immediately after revocation.

### 6.5 Package P9-D: catalog UX and public rendering

Use real images, cards/grids for phone, preview/approval before publish, full empty/error/revoked screens and no price by default. Test 101st Product selection, Arabic/English, category search, unit/SKU, missing photos, large catalogs safely bounded, price toggle and tax label, cross-tenant selections, paused/expired catalogs and stable URLs after edits. No public count/price/warehouse leaks; catalog remains read-only.

### 6.6 Package P9-E: label printing and sharing actions

Ensure actual stored barcode-to-unit mapping and barcode check digits as appropriate. Provide tested A4 label sheets or simple configured label dimensions—not an unbounded printer-design suite. Verify printed barcodes scan with a commodity scanner/app in a manual QA matrix. Copy/share actions should not emit transaction PII to an external service automatically. No silent direct WhatsApp API use.

### 6.7 Package P9-F: final convergence

A unified UI system and documentation: consistent buttons, locale, filenames, PDFs and share states across all supported subjects. Validate production-compatible dependencies and build. Add ADR 0009 recording share/privacy decisions, immutable-vs-live presentation, catalog price policy, supported outputs and limitations. Then one integrated PR and independent acceptance review; corrections only for specific demonstrated blockers.

---

## 7. Acceptance criteria / test matrix

### 7.1 Must pass for Phase 9 source acceptance

**Financial truth**
- Same posted source -> same document numbers, dates, lines, quantities, units, tax, discounts and exact decimal totals regardless of locale/branding; no floats.
- No report, PDF, print, share creation or public-view request modifies accounting, inventory, purchase/sales posting, cheque lifecycle, allocations or sequence counters.
- Posted documents survive Customer/Vendor/Product/company name changes without historical identity drift; draft previews follow reviewed live-identity policy.
- Multi-currency invoices/payment vouchers label transaction, exchange and base values correctly; returns and reversals never masquerade as new sales.

**Authorization and tenant isolation**
- Every private document enforces the correct source and document permissions, including cost/HR redaction; Company A cannot download, preview, share or enumerate Company B subjects.
- Share tokens cannot grant additional capabilities, cannot reveal raw costs or private attachments, and fail after expiry/revoke/disable.
- Public catalog default payload contains **no price values/keys**, costs, inventory quantity or vendor data. Explicit price visibility is permissioned and audited.
- Password guessing throttled; tokens are nonsequential and securely stored; noindex/no-store for sensitive public shares; archives/private paths not browser accessible.

**PDF/print**
- Arabic/English representative PDFs are visually inspected: glyph shaping, RTL, LTR IDs, mixed script, proper currency/quantity, page breaks, blank/long lines, totals, footer/QR and physical print margins.
- Compare document-rendered monetary and quantity values to canonical read-model fixtures, not screenshots alone. Broken images and missing logos render gracefully.
- PDF limits prevent pathological input from exhausting memory/CPU; no remote fetch/network SSRF; correct content and filename headers.

**Catalog, QR, labels and sharing**
- Publish/update/revoke/expire works; the same link reflects approved catalog updates; invalid links never show fallback private data.
- 100+ Product selection works, stable order and no cross-company selections; product image fallback and responsive public page.
- QR scans to the correct authorized HTTPS link; revocation disables its usefulness. Barcode labels scan to exact stored Product+Unit without accidental generated GTINs.
- WhatsApp/mailto/copy/Web Share require deliberate user actions and contain no attached confidential payload by default.

**UX and runtime**
- Arabic RTL and English LTR at phone (320–390px), tablet and desktop; keyboard, contrast, focus, button feedback, print preview, loading/empty/error states.
- Hostinger PHP/mPDF/GD compatibility, prebuilt assets, no required persistent worker; safe file and directory permissions.
- Production migrations additive, backups/restore runbook reviewed, zero fake production business data.

### 7.2 Automated test layering

- Focused model/service integration: document DTO/adapters, subject/status/RBAC, snapshot freeze, quantity/tax/FX truth and no writes.
- Secure share tests: token/password/expiry/revoke/cross-company/sensitive-key whitelist, legacy URL preservation, rate limits, concurrency.
- Catalog tests: create/update/publish/selection/price suppression, tampered query, archived Product/image fallback, multiple locales.
- Render tests: compare HTML and parsed PDF text/semantics where viable; font/visual review is separate and must be performed on real generated PDFs.
- Browser smoke: AR/EN × mobile/desktop and public/private roles using **development** fixtures, not production fake users.
- Existing Sales/Purchasing/Money/Inventory/reporting regressions only where touched; one full Phase 9 final QA gate. Do not rerun unrelated 250+ Phase 8 tests repeatedly after every template change.
- Review migration on disposable MariaDB including referential integrity, no unauthorized old share mutation, and reverse-deploy/code compatibility.

### 7.3 Practical performance and release envelopes

Propose measured budgets at P9-0 based on Hostinger capabilities instead of asserting untested SLAs. Initial **targets** for representative A4 docs: PDF within a few seconds for ordinary 1–5 pages, prompt failure on oversized statements, no excessive memory spike; catalog public page loads without fetching all product images; no unbounded public links generating expensive PDFs. Test worst-case long Arabic text and 100-item catalog. Define explicit caps on catalog items, preview images, PDF lines/pages and label sheets **after benchmark**; record values in ADR 0009.

### 7.4 No merge/deploy if

- Snapshot identities or currency/quantity meanings are incorrect.
- Unauthorized subject/cost/salary data is exposed in browser, PDF, QR or public share.
- Public links bypass revoke/expiry or expose other tenants.
- Printing/QR is unscannable or obviously broken in Arabic.
- A renderer has financial side effects or source writer edits.
- Migration is destructive, alters existing public share tokens, or cannot safely deploy.
- Worker tests are claimed but not verifiable at the exact head.

Nonblocking after agreed scope: tiny icon adjustments, advanced template catalog, automated email/SMS delivery, native camera scanning, 69 report PDF parity, native apps or AI automation.

---

## 8. Git, delegation, review, release and production playbook

### 8.1 Agent roles

**Product Owner:** sets product priorities, approves scope changes, business policy, merge and Hostinger deployment. No day-to-day micro-management required.  
**ChatGPT (architect/independent auditor):** examines actual Git commit tree, provenance, DTO/privacy invariants, tests/QA evidence, code and UI; accepts/rejects each *major integrated phase gate*. Does not trust worker claims unverified.  
**Codex:** implementation lead, technical PM, task decomposition, AGY integration and primary worker review, exact-head QA, PR handback. May propose product defaults but cannot silently widen scopes or permissions.  
**AGY:** implements bounded delegated packages with independent worktrees; supplies exact changed files/test evidence; no autonomous main merge or production access unless explicitly delegated and owner-authorized.

### 8.2 Recommended branch strategy

After independently verified Phase 7/8 production acceptance and Owner's start authorization:

- Fast-forward local `main` from verified `origin/main` (do not overwrite owner files).
- Proposed branch `phase/9-documents-catalog-sharing` from the **then-current exact main SHA**; do not blindly use this document's October 9 SHA if `main` has advanced.
- Codex owns an integration worktree; AGY packages use isolated named worktrees and never mutate Codex's active checkout.
- Normal linear/fast-forward branch history; no `force push`, `rebase`, squash or amend of published commits.
- Prefer one PR for Phase 9 integrated source, with a small number of bounded reviewed checkpoints. Architect independently accepts one complete candidate and material corrections. No incessant automated re-audits.
- `main` remains untouched until explicit Owner merge authorization; Hostinger access is a **separate later action**.

### 8.3 QA economy

During each package, run scoped test files + Pint/Larastan as relevant. Integrate all packages, then run the **full Phase 9 suite once** plus impacted inherited tests, full static/build/Blade and a bilingual browser/print matrix. When a correction changes only narrow source, rerun targeted affected tests, not the whole historical suite. If common security/source contracts change broadly, a final comprehensive gate is justified.

### 8.4 Worker handback standard

Each package and correction reports: base/head SHA, parent, exact diff, changed files, permissions touched, migrations, shared service changes, tests with actual counts, financial zero-write evidence, no production changes, owner-file preservation, local/origin parity, clean tracked tree/index, ignored artifacts and unresolved risks. Codex must inspect worker code independently and not accept a self-reported green test without artifacts. Never invent CI results.

### 8.5 Deployment gate (after source acceptance and owner authorization)

Verify production baseline and independent Phase 7/8 acceptance first. Backup private DB/files; capture hashes and migration state; additive migrations only; idempotent catalog/share permission provisioning preserving non-owner grants; pin accepted merge tree; build/transfer Vite assets; run canonical deploy; private PDF/image storage permissions; smoke AR/EN authenticated PDFs and public catalogs; restore maintenance state; compare economic row digests and six reconciliations; verify zero posted transaction changes. Deploy is reversible by code version; never `migrate:fresh` or blind migration rollback. Stage test links/catalogs in disposable company fixtures, **not fabricated live business records**.

---

## 9. Future architecture and revised roadmap — AI voice + mobile deliberately staged

The newly requested ideas are **[OWNER-STATED FUTURE PRODUCT INTENT]**: a person says (Arabic/English/possibly mixed), e.g., *“I sold X quantity of Product Y to Customer Z for price XX; add it”*, and the app creates the appropriate business transaction with minimal manual entry. There may also be a chatbot/instructions interface or a mic button. Native mobile may come later. This is **not just transcription**: it is a high-stakes accounting command orchestration problem and must use canonical business actions, identity resolution and explicit confirmation.

### 9.1 Proposed post-Phase-8 roadmap

| Phase | Goal | Development gate | Production posture |
|---|---|---|---|
| **9 — Documents, QR, Catalogs & Sharing** | Complete bilingual printable documents, controlled public catalog and share links | This document, P9-0 audit, bounded P9-A–F, independent source review | Owner-authorized deployment after Phase 7/8 production acceptance |
| **10 — Production hardening & real-client readiness** | Pen-test style RBAC/tenancy, recovery rehearsal, performance/load, Arabic/English UX/PDF, operational monitoring and backups | Evidence-backed security/restore/performance sign-off | Formal production readiness milestone |
| **11 — API/command architecture & mobile readiness** | Extract application command contracts and stable authenticated API where needed, device/session and versioning architecture | Server-side, tenant-safe non-LLM draft/preview/confirm APIs with parity tests | Backend remains web-first; no native client requirement |
| **12 — AI-assisted text/voice business actions** | Contextual mic plus text command entry, ASR/NLU, entity disambiguation, draft preview, explicit user confirmation and safe canonical action dispatch | Limited Sales beta behind feature flag with security/cost/error gates | Opt-in; no auto-post by model |
| **13 — Optional native mobile client** | Decide PWA vs Flutter/React Native/native after usage data; camera/barcode, notifications/offline-read as scoped | Separate mobile product/architecture decision, API auth/privacy/performance readiness | Not a commitment to ship a native app |

This phase numbering **extends** the current Master Specification roadmap; do not overwrite accepted Phase 10 scope or imply Phase 11–13 already exist in code. Phase 12 can be prototyped in a sandbox after Phase 11 command contracts are accepted, but **production AI action access waits for the Phase 10 security baseline**. Phase 13 mobile need not wait for broad AI adoption; it depends primarily on Phase 11 and a mobile product decision.

### 9.2 Preparations allowed inside Phase 9 (no AI feature creep)

- Keep PDF/catalog/share services callable through clear **authenticated application boundaries**, not directly from Livewire HTML internals.
- Use typed domain DTOs and server source resolvers with explicit Company/actor context; this will later support a mobile client and AI draft renderer.
- Use stable `public_id`/ULID for URL subjects and safe internal IDs through authenticated resolvers; no raw numeric-ID public sharing.
- Version public/print view models when historical display semantics change.
- Preserve idempotency, posting provenance and canonical action reuse; do not add AI-related database tables or provider SDKs.
- Document API-readiness opportunities as `future-interface` notes; no production API tokens or `/api/ai` route in Phase 9.

### 9.3 Phase 11 — API/command/mobile readiness specification

**Goal:** a trustworthy, provider-independent command surface that can be invoked by Livewire, future mobile app, and *later* AI intent handlers **without diverging from canonical financial logic**.

**Candidate contracts:**

```text
AuthenticatedCommandContext:
  actor_id, active_company_id, tenant_role_context,
  locale, company_timezone, request_id / idempotency_key

SalesDraftIntent:
  customer_id, warehouse_id, document_date, currency,
  lines[{product_id, unit_id, exact_quantity, exact_unit_price,
         requested_discount/tax if authorized}], optional_note

PreviewResult:
  resolved names, sources, exact totals, discounts/tax,
  exchange-rate source/validity, stock availability/FEFO,
  validation blockers, warnings, expiration/revision token

ConfirmedAction:
  server-calculated reviewed payload + confirmation_token,
  actor current permissions, active company,
  idempotency key, canonical action dispatch
```

Important: `DraftIntent` values can be strings/value objects for decimals, never binary PHP floats; *current default price* is a suggestion, never a final historical price. Validate product/unit conversion and positive quantities through canonical services; number assignment and immutable posting only through current approved actions.

**API security:** authenticate a user/device using an appropriate reviewed Laravel mechanism (evaluate Sanctum/session/MFA and per-device revocation, do not select by habit); short-lived/revocable tokens, rate limiting, per-company active context, same permissions as web, CSRF for cookie-bound flows, TLS, versioned endpoints, strict JSON schema, idempotency and trace IDs. No implicit superuser API. Confirm that sensitive cost/profit redaction persists for mobile/AI reads and exported documents. No automatic publication of existing authenticated web endpoints to the Internet.

**Phase 11 acceptance:** web and API invoke the same canonical draft/confirm action; one receipt per idempotency key, no duplicate post on retry; denied company/role changes; currency/discount/stock/expiry validation; safe concurrency; backward-compatible route/version contract; browser and simulated mobile client parity. This is API-ready **without requiring** a native app or AI provider.

### 9.4 Phase 12 — AI voice/instructions assistant specification

#### Interaction-first recommendation

Start with a **mic button on the Sales create screen** and a small, optional **“Describe a sale”** text box for accessibility. Text is the same command pipeline and avoids ASR as a dependency. Only after the Sales flow is trusted should a discreet global assistant be considered for read-only queries and navigation; a chat window is optional, not a product prerequisite.

Example Arabic input (illustrative):

> “بعت خمس كراتين من سائل الجلي للعميل أحمد بسعر 80 شيكل للكرتونة، اعمل فاتورة.”

**Expected safe output:** “I found Customer Ahmed A., Product Dishwashing Liquid 3L, carton = 12 pieces, quantity five cartons, unit price 80 ILS per carton, total 400 ILS before applicable taxes/discounts. Warehouse X. Please review stock, date and totals. **Create draft** / Edit / Cancel.” Nothing is posted from the transcript alone.

#### Orchestration diagram

```text
User speaks/types
  -> opt-in microphone capture or text input
  -> transcription adapter (speech only, if audio)
  -> strict intent extraction to versioned schema
  -> deterministic entity resolution (Company + actor permissions)
  -> ambiguity/validation questions
  -> canonical Sales draft preview using domain services
  -> human-visible REVIEW: customer/product/unit/price/tax/FX/stock
  -> explicit authenticated confirmation
  -> existing canonical posting/draft action + idempotency
  -> receipt / audit trail / undo-via-canonical-return-or-void only
```

**Strict safety rule:** The model may propose a command but **cannot directly call `AccountingPostingService`, insert rows, execute raw SQL, change permissions, update stock balances, move money or post without a separately authenticated confirmation step**. Never interpret “do it” from ASR as confirmation for a previously unresolved risky action; confirmation must bind to the exact structured preview hash and expire. If the owner later wants one-step automation, that is a distinct explicitly reviewed policy with transaction caps, auditability and capability gating—not default behavior.

#### Intent extraction and resolution

- Intent classes in initial beta: `draft_sales_invoice`, `search_product`, `find_customer`, `show_my_draft` and optionally read-only stock availability. Start with **draft_sales_invoice only** as the transaction-writing intent; returns/purchases/payroll/money require separate future review.
- Strict schema with type validation; ask on missing/ambiguous Customer or Product; no fuzzy auto-match when two real entities could fit. Display source IDs only internally, human names for users.
- Arabic dialect/Levantine Arabic, numbers and currency code-switching (شيكل/دينار/دولار; ILS/JOD/USD), colloquial quantities (حبة/علبة/كرتونة), local dates/timezone, decimal precision and SKU/barcode. Handle corrected transcript visually and audibly only where explicitly enabled.
- Product units from authoritative ProductUnit; e.g. 5 cartons × 12 base units must not become five pieces. Unit price per named transaction unit, not per base unit unless instructed.
- Selling price defaults and exchange rate are **proposals**; server applies company price permissions, discount/tax rules, stock/expiry/FEFO, period restrictions, credit limits and conflicts at confirmation.
- “I sold…” may mean *draft* or *already completed physical sale*. Start by creating an **unposted draft**; do not silently post or consume the invoice number until the user confirms.
- Transcription quality threshold/ambiguity handling and adversarial voice tests; direct voice input is not authentication and prerecorded audio is not proof of user consent.

#### AI provider and API keys

Introduce a **provider adapter** rather than hard-coding one AI vendor: `SpeechTranscriptionProvider`, `StructuredIntentProvider`, optional `TextToSpeechProvider`. First evaluation can include OpenAI's supported speech-to-text and structured-output APIs, but API names/prices/availability must be verified against current documentation **at Phase 12 implementation time**; do not hardcode presumed 2026 endpoints or cost numbers now. A non-AI deterministic fallback for manual entry always remains.

- AI API keys are **server-side secrets**, never in JavaScript/mobile bundles, CSV/PDF, repository, logs or share links. Use appropriate production secret management/env permissions and rotation strategy.
- Budget per company/user: request, time, input duration/file size, token/audio cost limits, daily/monthly cost dashboards and administrative disable switch.
- Consent and privacy: clear “audio sent for transcription” disclosure; transmit only minimized necessary content; do not upload whole ledger/customer database to the model. Entity lookup stays in private server services. Recording/transcript retention opt-in, short TTL, delete controls, controlled audit trail and provider data-handling review.
- Do not expose client phone numbers, entire price histories, employee salaries, vendor costs or protected financial records to the model unless a narrowly authorized future operation truly requires it.
- Prompt-injection and adversarial input: the speech/transcript is **untrusted text**, not an instruction to bypass authorization. Avoid tool calls that accept arbitrary API routes/SQL. Whitelisted command methods and schemas only.
- Timeouts, outages, quotas: failure preserves manual Sales workflow; never retry a posting blindly; safe idempotent re-entry and visible error/retry states.
- Optional read-only AI explanations should distinguish calculated figures from generated prose and never fabricate balances.

#### AI acceptance tests

1. Arabic/English and code-switched sales prompts: exact customer/product/quantity/unit/price/currency; incorrect transcript editable.
2. Missing or duplicate names lead to questions, not arbitrary selection.
3. A foreign company name/Product is never considered; unauthorized cost/price changes fail server validation.
4. Unit conversion carton/piece, decimal quantity, product without stock, expired lot/FEFO, tax/discount permission and FX rate changes validated.
5. Confirmation token bound to **exact** preview; expiry or role revocation invalidates it. A changed product price/stock requires re-preview.
6. Ten repeated button presses/network retries yield at most one canonical intended draft/posting under reviewed idempotency contract.
7. No ledger/accounting movement occurs before explicitly accepted posting step; cancel/timeout cause zero economic mutations.
8. Provider outage, prompt injection, malicious transcription and cost-budget exhaustion fail safely.
9. Audit who initiated, what resolved command was approved, which canonical record resulted and provider usage **without storing raw audio by default**.
10. Mobile microphone/browser permissions and accessibility degrade gracefully to text/manual entry.

### 9.5 Phase 13 — native mobile client decision

**Do not equate “mobile-ready” with “must build a native app.”** The present app is responsive and may first receive a carefully scoped PWA improvement (installability/offline-safe static shell only; no offline financial posting) after Phase 10. Evaluate actual phone workflows before committing to Flutter/React Native/native.

A mobile-app decision should be based on (a) phone-heavy daily use, (b) camera barcode workflow needs, (c) push notification value, (d) offline read needs, (e) operating cost and App Store maintenance, and (f) API readiness/security. If a native app is approved, prefer shared canonical Laravel backend and common API contracts; don't rebuild ledger/FEFO/FX algorithms in mobile code.

**Mobile readiness gates:** secure device authentication/revocation, optional MFA, scoped API authorization, same-company routing, conflict/version semantics, retries/idempotency, media upload handling, privacy/lock-screen safeguards, locale and right-to-left UI, large data pagination, and production API budget/observability.

**Offline policy:** never allow an offline action to claim posted invoice/paid check/stock adjustment before the server canonically commits. Offline user-entered drafts may sync with explicit conflict/repricing/stock validation and a fresh confirmation. Future mobile QR scanning can use existing ProductBarcode/ProductUnit source. Voice AI may run in the app *after* Phase 12 if business value justifies it.

---

## 10. Phase 10 hardening — keep its original purpose, expand gates only where necessary

Phase 10 remains the original planned *production hardening* lane. It should independently check: tenant isolation/role revocation, finance/stock atomicity, invariant reconciliation, backup **restore rehearsal on a disposable environment**, retention policies, live operational logging without PII, PHP/mPDF/CSV performance, bilingual responsive and PDF accessibility, incident response, rate limiting, document/public-share threat model, dependency/CVE evaluation, Hostinger permissions and production monitoring. It does not require adding AI or native mobile; it creates the secure foundation needed for later command automation.

Suggested V1 sign-off: verified Phase 9 deployed baseline, recoverable backups, documented RPO/RTO targets, proven restore of a backup outside production, measured route/pdf/report performance bounds, no critical auth/tenant findings, acceptable UI/RTL, and real-client onboarding checklist. Any Phase 11 API exposure is blocked by unresolved critical security findings.

---

## 11. Decisions, risks, accepted limits and future backlog

### 11.1 Default decisions made by this plan (unless owner overrides)

| Decision | Recommended default | Why |
|---|---|---|
| Phase 9 order | Document/PDF core → secure sharing/QR → catalogs → label/share polish | Depend on trustworthy output and link policies |
| Public catalog prices | OFF, server-side | Common trader need; prevent accidental commercial disclosure |
| Catalog link | Stable, content updateable, immediately revocable | Explicit Master Specification request |
| Financial document exposure | Private unless authorized actor shares eligible subject | Sensitive data protection |
| Statement public share | Fixed period/cutoff and short expiry | Avoid silently changing disclosed financial window |
| PDF render engine | Reuse mPDF and existing data builders | Shared hosting and consistency |
| QR content | Opaque secure HTTPS link, not raw money | Capability isolation |
| Barcode data | Use stored valid codes and units | Avoid incorrect Product or GTIN identity |
| Phase 12 first interface | Contextual Sales mic + text fallback, not giant chatbot | Easier learning and control |
| AI action mode | Structured draft + explicit human approval | Canonical accounting safety |
| AI vendor | Adapter, choose provider after evaluation | Cost/privacy/reliability portability |
| Native app | Optional Phase 13, after API readiness/usage validation | Avoid double-maintaining business logic |

### 11.2 Remaining design decisions for P9-0 (not reasons to stall the whole roadmap)

- Which vendor-facing Purchase documents are appropriate to send externally vs internal-only purchase receipts?
- Which Money/Expense/stock docs are true V1 customer needs versus later backlog? Start with core Purchase/Vendor and Sales parity.
- Which visual document template(s) the owner accepts for AR/EN, and required page sizes beyond A4.
- Public share default expiry per subject, password policy, and fixed-statement cutoff semantics.
- Whether the product catalog should support an optional published price snapshot or only current list prices, with an explicit label and update behavior.
- A modest per-catalog item cap and PDF memory/page cap measured on Hostinger.
- Where barcode labels fit within immediate Phase 9 rollout versus a small optional follow-up; avoid delaying core public catalog.

Codex should propose concrete evidence-based defaults for these in P9-0 and escalate **only material product-policy contradictions**. No massive questionnaire needed.

### 11.3 Accepted limitations and deferred work

- Phase 8 Customer/Vendor statements may hydrate complete historical statements before pagination; preserve/report the limit and performance budget.
- CSV is bounded to 50,000 rows/50 MiB/30 s; streaming revocation cannot recall transmitted bytes.
- QR printed on revoked share still scans but must show revoked access, not data.
- Public shared links are bearer capabilities; password/expiry/revoke mitigate but do not prevent authorized recipients taking screenshots of already viewed data.
- PDF generated from a posted source is historically correct, but rendering itself is not a legally certified fiscal/e-invoice system; jurisdiction-specific tax invoicing/legal archival requires a separate reviewed compliance project.
- Catalog pages are public presentation, not ecommerce checkout or real-time stock promise.
- Phase 12 AI transcription will sometimes be wrong; confirmation, deterministic server validation and manual fallback are permanent design features, not temporary hacks.
- Mobile offline draft support, camera scanner, POS, OCR, automated document dispatch, full payroll, advanced SaaS subscriptions and AI read-only financial Q&A are optional future additions outside Phase 9.

---

## 12. Next immediate actions and milestones

**Now, while Codex independently verifies AGY's Phase 7/8 deployment:**
1. Treat this Phase 9 document as **planning**, not authorization to touch production or to start code.
2. Read the independent production acceptance handback when available; verify live source tree, backups, migrations, reporting bootstrap, reconciliations and no economic mutation.
3. Reconcile Phase 7/8 source documents with accepted production status **only after** evidence passes.
4. Authorize Codex for **P9-0 source/UX/security gap audit** and a small design/architecture proposal based on this specification.
5. Architect reviews P9-0 once, closes product-policy decisions and freezes a bounded Phase 9 acceptance checklist.
6. Product Owner authorizes Phase 9 implementation; Codex delegates P9-A–F; AGY reports to Codex; Codex integrates and opens PR.
7. Independent architect reviews final exact-head source and UI/PDF/security evidence; owner authorizes protected merge, then a separate backed-up deployment.
8. Complete Phase 10 hardening; prepare Phase 11 command/API readiness; separately decide AI voice and native mobile at their gates.

### Suggested development start criteria

- Phase 7/8 independent production verification **accepted**, not just AGY-reported.
- Current exact `main` fetched and all owner files preserved.
- P9-0 document gap/permissions/catalog schema confirmed.
- No unresolved production-critical bug competing for the same canonical source.
- Owner gives explicit **“Start Phase 9 implementation”** instruction after P9-0.

---

## 13. Standalone kickoff prompt to Codex for P9-0 (planning/audit only)

> **Codex — Accounting Phase 9 Planning/Audit Start**  
> Read `ACCOUNTING_PHASE_9_FULL_ENGINEERING_ROADMAP_AND_FUTURE_AI_MOBILE_V1.md` together with `AGENTS.md`, the relevant Master Specification/Blueprint sections, current ADRs, existing PDF/print/share/catalog code, route/permission definitions and `references/ui/`. Verify current GitHub `main` and the independently accepted Phase 7/8 production checkpoint.  
>  
> **Do a READ-ONLY Phase 9 P9-0 audit first. Do not implement Phase 9, migrate, merge, deploy, modify Hostinger or request another broad bot review.** Inventory what truly exists, what is partial/missing, exact security/privacy risks, reusable components, priority document matrix, Catalog schema proposal, minimal forward-only migrations, authorized sharing/QR policy, Arabic/English UI and PDF reference plan, bounded AGY work packages and acceptance criteria. Compare to the approved Master Specification and this roadmap; record disagreements with proposed decisions.  
>  
> Keep the future roadmap separate: Phase 10 hardening; Phase 11 API/command/mobile readiness; Phase 12 optional mic/text AI-assisted Sales drafts with explicit confirmation, secrets/private data controls; Phase 13 optional mobile app. **Do not build AI or mobile code during Phase 9.**  
>  
> Deliver a concise but complete `PHASE_9_P0_DISCOVERY_AND_EXECUTION_LOCK.md` in an owner-visible location, listing actual source paths, exact SHA, gaps, test/permission plan, proposed package owners, scope gates and remaining decisions. Stop for Product Owner/independent architect review.  
>  
> Stop marker: `PHASE 9 P9-0 SPECIFICATION AND GAP AUDIT READY FOR ARCHITECT REVIEW`.

---

## 14. Repository anchors for audit and continuity

Use actual source and tests, not stale handback notes alone:

- Product requirements: `docs/SMALL_TRADER_ACCOUNTING_MASTER_SPEC_v1.0.md` — §§7, 44–48, 77–80; broader language, sharing and security.
- Technical architecture: `docs/ENGINEERING_BLUEPRINT_v1.0.md` — PDF/mPDF, QR/Images, document settings, rendering architecture, RBAC, deployment, phase roadmap.
- Agent contract: `AGENTS.md`.
- Deployment guide: `docs/HOSTINGER_DEPLOYMENT_GUIDE.md`, `bin/deploy.sh`.
- Source acceptance: `docs/PHASE_8_SOURCE_ACCEPTANCE_HANDOFF.md`, ADRs 0004–0008.
- Existing Sales PDF: `app/Http/Controllers/PdfDocumentController.php`, `app/Services/Sales/DocumentDataBuilder.php`, `DocumentRenderer.php`, `PdfRendererService.php`, `PrintRenderer.php`, `app/Domain/Sales/Documents/DocumentData.php`, `resources/views/pdf/`.
- Share/security: `app/Http/Controllers/PublicShareController.php`, `app/Services/Sales/PublicShareService.php`, `app/Models/PublicShare.php`, `resources/views/sales/public-share.blade.php`, `tests/Feature/Phase4/PublicShareSecurityTest.php`.
- Product images/barcodes: `app/Models/ProductImage.php`, `ProductBarcode.php`, `app/Services/Inventory/ProductImageService.php`, `ProductCatalogService.php` and Product/Unit/Price models.
- Purchasing: `app/Livewire/Pages/Purchasing/`, `app/Services/Purchasing/`, Purchase/PurchaseReturn/VendorPayment/VendorStatement canonical read sources.
- Reports: `app/Application/Reporting/`, secure CSV/guard/presentation from Phase 8.
- Routes: `routes/web.php`.
- UI references: `references/ui/` (reference only; confirmed images/designs must be interpreted and adapted).

**End-state vision:** One accurate, approachable accounting and inventory tool that can later be operated via desktop, phone, barcode or natural language—all through the **same tenant-safe canonical business actions**, with professional bilingual documents and deliberate human control over financially consequential operations.
