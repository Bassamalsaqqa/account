# Phase 9 P9-0 discovery and execution lock

Date: 2026-10-09. Status: **PLANNING CANDIDATE — AWAITING ONE INDEPENDENT ARCHITECT REVIEW**.

Phase 9 runtime implementation is **UNSTARTED**. This contract authorizes no feature work, migration, merge, deployment, production access, message sending, or Phase 10–13 implementation. Architect acceptance and the Product Owner's explicit implementation instruction are required before P9-A starts. The requested stop marker appears at the end.

## 1. Exact baseline, authority and preservation

| Identity | Independently checked result |
|---|---|
| Repository | `https://github.com/Bassamalsaqqa/account` |
| Active planning branch | `docs/phase7-8-production-verification-phase9-plan` |
| Starting local HEAD | `098b17bf0559a8b8da2308f12b73cee52ea78cd9` |
| Its sole parent; local main; origin/main; live remote main | `28c2a53f84a8a0525ade6e1be7cee31ac17388ba` |
| Prior production runtime, carried evidence | `96c310f30a07e97ab8e04d5afbf0b2bb805f4317` |
| Prior accepted runtime tree | `a71f567ba54b16b30d4d1d4643a14a960e0ff98f` |
| Phase 7 merge, retained ancestor | `c07559c92816f838532e4b9d1c1537065f50ad5c` |

`098b17bf…` was initially unpublished. Its seven changed paths are all under `docs/`: Master, Blueprint, ADRs 0007/0008, Phase 8 handoff, Phase 7–8 production acceptance and Phase 9 proposal. No runtime delta exists against accepted main or the Phase 8 merge. Current remote main equals its parent, so there is no divergent-base conflict. Publication must recheck this exact identity and stop on drift. Preserve the commit as an ancestor; never reset, amend, rebase or duplicate it.

Authority: `AGENTS.md`; accepted source/ADRs; Master §§44–47, 65–67, 70, 80; Blueprint §§56–62, 67, 99–100, 106. The complete 708-line owner roadmap was read. Its byte-identical repository copy is [the supplied roadmap](ACCOUNTING_PHASE_9_FULL_ENGINEERING_ROADMAP_AND_FUTURE_AI_MOBILE_V1.md). That copy preserves historical wording, including its earlier production-verification-in-progress claim. The [production acceptance](PHASE_7_8_PRODUCTION_ACCEPTANCE.md) supersedes that stale checkpoint; this execution lock resolves planning choices. The [earlier proposal](PHASE_9_ENGINEERING_PROPOSAL.md) becomes a continuity/index document, not a competing roadmap. No planning language overrides canonical financial behavior.

The nine previously protected files are three `.playwright-mcp/` artifacts (`page-2026-10-07T18-09-21-141Z.yml`, `phase7-employee-ar-mobile.png`, `phase7-employee-en-mobile.png`) and six root handoff/spec files (`ACCOUNTING_AUTONOMOUS_AI_AGENT_MASTER_HANDOFF_AND_ROADMAP.md`, `CODEX_PHASE_7_AUTONOMOUS_EXECUTION_PROMPT.md`, `CODEX_PHASE_7_PRODUCTION_DEPLOYMENT_HANDOFF.md`, `CODEX_PHASE_8_AUTONOMOUS_EXECUTION_PROMPT.md`, `PHASE_7_EXPENSES_PAYROLL_LANDED_COST_FULL_SPEC.md`, `PHASE_8_REPORTING_DASHBOARD_FULL_SPEC.md`). Hash all nine plus the new root roadmap before/after. Do not stage the originals or the screenshot directory. Ignored discovery evidence belongs in `.ai/delegations/20261009-p9-0/`.

Evidence labels below: **source** means independently inspected implementation; **executed** means this turn's local discovery; **carried** means preserved prior evidence; **planned** means an unimplemented contract. A passing observation probe that reproduces a gap is not a security acceptance pass.

## 2. Production acceptance carried forward

**PHASE 7 AND 8 PRODUCTION ACCEPTED** remains the prior independent decision. Retain its exact limitations. No Hostinger access or live verification is performed for P9-0.

Carried evidence: 80 tables, 61 applied migrations, none pending; six healthy reconciliations; 69 reports and 87 Owner catalog permissions; unchanged seven non-owner grant sets; all 79 non-session table fingerprints unchanged after bounded verification; private backup hashes/integrity/access controls; 34/34 bilingual server-render checks; 23 desktop and five mobile Arabic browser observations; an authorized Arabic CSV download. Production had no economic history, so nonempty lifecycle assurance comes from accepted local source suites, not empty production reconciliations. Backup modes 0700/0600 are the recorded private-backup exception; application directories/files retain 0755/0644, never 777.

| Limitation | Evidence-based classification | Follow-up |
|---|---|---|
| English responsive browser coverage incomplete | Non-blocking coverage gap at prior acceptance; server rendering passed | P9-F covers changed interfaces in both locales; Phase 10 completes the application-wide matrix |
| Restricted-role production tests omitted deliberately | Non-blocking verification boundary; isolated source negatives retained | P9-F isolated role matrix; Phase 10 safely agreed live operational verification without fabricated users |
| No backup restoration rehearsal | Phase 10 recovery gate; integrity is not restoration proof | Restore to a disposable environment, establish RPO/RTO and validate schema/data |
| Three message-channel browser errors | Unattributed non-blocking observation; no matching Laravel log failures | Phase 10 reproduce in a clean browser and identify application/extension origin; escalate only if correlated impact appears |

The source findings below are separate from these observations. They block Phase 9 acceptance where relevant; they do not claim a newly investigated production incident.

## 3. Verified capability inventory

| Capability | Baseline status and evidence | Reuse / required change |
|---|---|---|
| Five authenticated Sales outputs | **Source, implemented:** `routes/web.php:249–253`, `PdfDocumentController`; quotation, invoice, return, receipt, statement; `format=print` | Retain routes and permissions; consistent actions, safe headers/options, bounded output |
| Shared Sales DTO/HTML/PDF/print | **Source, implemented:** `Domain/Sales/Documents/DocumentData`, `Services/Sales/{DocumentDataBuilder,DocumentRenderer,PdfRendererService,PrintRenderer}` | Extend through source-specific private adapters; no replacement engine |
| Arabic/English A4 mPDF | **Executed, partial quality:** four real PDFs; shaping/units/JOD correct in samples | Continuation identity, page numbers, terms flow, status, branding and controls |
| Historical commercial identity | **Source/executed, implemented within stored fields:** Sales builder snapshots, `PostSalesInvoiceAction:99–100`; Purchase posting and `PurchaseIdentitySnapshot` | Never reconstruct missing historical logo/vendor code/account name from current masters |
| QR generator | **Source, implemented helper:** `PdfRendererService::generateQrDataUri()` | Controller supplies no share URL; public template's QR slot is unpopulated; validate HTTPS URL and wire intentionally |
| Document settings data | **Source, partial:** `CompanyDocumentSettings` holds locale/logo/QR/images/footer/terms flags | Real authorized editor; generic template does not consume branding/footer/QR settings comprehensively |
| Settings navigation | **Source, placeholder:** `resources/views/livewire/pages/settings-index.blade.php:596` and following Sharing card | Apply approved settings reference only here; make implemented controls truthful |
| Sales share controls | **Source, partial UX:** Invoice/Quotation/Return details and CustomerStatementView create/recover/revoke, expiry/password | Preserve working controls; add consistent preview/copy/QR/status/management and delivery composition |
| Token storage/lifecycle | **Source + inherited executed tests:** `PublicShareService`, `PublicShare`, existing migration | Reuse 40-character random tokens, unique SHA-256 lookup, encrypted recovery, password hashes, expiry/revocation |
| Public allowlist | **Source, implemented narrowly:** quotation, sales_invoice, sales_return, customer_statement | New receipts/catalog only through explicit policies; Purchase/Vendor/Payroll remain private |
| Purchasing reads/history | **Source, implemented:** `PurchaseReadModel`, `PurchaseReturnReadModel`, `VendorFinancialRead`, `VendorStatementQuery` | Four private outputs absent; adapt accepted reads/snapshots, do not create new AP math |
| Product catalog data | **Source, implemented master data:** Product/Image/Category/Unit/Barcode and `ProductCatalogService` | That service is master search, not a published catalog; no `Catalog`/`CatalogItem` model/public composer found |
| Product media | **Source, implemented:** `ProductImageService`, `QuotationMedia` allowlists public WebP paths and embeds bytes for PDF | Reuse trusted thumbnails and missing-image fallback; distinguish current quotation media from frozen economics |
| Barcode facts/scanner input | **Source, implemented:** `ProductBarcode` binds `unit_id` to configured ProductUnit; Sales scanner lookup | Bounded labels absent; preserve stored values and piece/carton meaning |
| Public catalogs / catalog management | **Source, absent** | New same-company schema/admin/public DTO; prices OFF server-side |
| Messaging UX | **Source, absent as integrated output flow** | User-triggered copy/Web Share/WhatsApp/mailto; no automatic sending |
| Report outputs | **Source, 69 accepted screen/CSV variants** | Preserve; dedicated statement outputs in scope, blanket report PDF parity deferred |

Fully implemented here describes a capability's presence, not certification against every Phase 9 criterion. Passing old token tests does not prove rate limits, current authority or cache safety.

### Duplicated paths and concrete risks

The active renderer always uses `pdf.document`; source-specific `pdf/{quotation,sales-invoice,sales-return,customer-payment,customer-statement}` templates remain alongside it. Some tests inspect the older invoice template directly (`RemainingSalesIntegrityTest:243`). Scan all call sites before retiring any template; replace misleading coverage with assertions on actual routes, not duplicated implementation tests.

1. **Receipt currency ambiguity:** `DocumentDataBuilder:96–100` places `allocated_amount` under receipt currency. ADR 0006 states this is document principal; `payment_currency_amount` is consumed receipt currency and `settlement_base_value` is the base leg. Present all applicable immutable legs explicitly. Initial allocations and later application events remain distinct; never derive missing facts from today's FX.
2. **Statements are live:** `CustomerStatementQuery::executeForShare()` calls unfiltered `read($customer)`; `DocumentDataBuilder::statement()` uses current Company/Customer identity and preferred locale. Range/cutoff, issuance identity and values are not frozen by a share. New statements need the contract in §8.
3. **Generic sharing permission:** create/recover/revoke guard `sales.document.share`, but do not intersect each subject's source-view authority. Private output controller does intersect PDF/source permissions, but fresh source-service checks must apply on every stale request and delivery.
4. **Password/cache/privacy:** form is POST with CSRF, but `PublicShareController::show()` uses `Request::input()` on both GET and POST, accepting query passwords. Share routes have no specific throttle. No explicit share no-store/noindex/referrer controls were found; actual infrastructure headers remain untested. The page loads Google Fonts. Remove third-party resources from sensitive token pages.
5. **Company and lifecycle:** public resolution checks share and source eligibility, with explicit company-constrained lookups, but does not explicitly require an active Company. Source void/draft fails closed. Quotation return-to-draft/resend can change the same subject; bind new grants to a disclosed revision.
6. **Public metadata race:** both resolvers exist; view counters use read-plus-save, with one path counting before subject build. Consolidate policy without losing nonfinancial semantics; count atomically only after success. Recheck authorization immediately before response delivery.
7. **Missing snapshot fields:** Sales company snapshots do not store logo bytes; Purchase snapshots omit logo and registration number; VendorPayment has company/vendor snapshots but no proven money-account-name snapshot. Existing operational reads may display live warehouse/account names. Omit or clearly label unavailable/current context, never present it as historical identity.
8. **Resource/asset boundaries:** mPDF temp path is private, but no business-level row/page/output/deadline limits exist. Quotation print builds a public image URL while PDF embeds bytes. Audit every adapter's local asset path and cleanup; do not feed arbitrary URLs/HTML to mPDF.
9. **Lifecycle display:** generic template only highlights Draft. Posted/void/reversed/credit status and original invoice/return/payment-method references are not comprehensively presented. The public item table omits unit labels. Correct the representation, not the source lifecycle.
10. **Audit:** no explicit share lifecycle audit calls appear in the service. Add minimal create/recover/revoke/publish/settings events through accepted infrastructure; never log tokens/passwords or full financial payloads.

## 4. Complete document and permissions matrix

All entries require authenticated fresh actor, active matching Company/membership, same-company subject and server-side projection before serialization. Existing permissions are exact names; additions are **planned**, not provisioned by P9-0. `+` means intersection; `OR` applies only where stated. Private preview/print/PDF uses the same authority.

| Subject / format | Baseline | Frozen Phase 9 coverage | Required existing authority | Planned output capability | Public policy |
|---|---|---|---|---|---|
| Quotation, A4 HTML/PDF | Exists | Preserve draft watermark and non-draft issued output; optional photos/QR | `sales.quote.view` | Existing `sales.document.pdf`; share additionally `sales.document.share` | Opt-in non-draft, revision-bound; truthful accepted/converted/closed state |
| Sales Invoice, A4 HTML/PDF | Exists | Draft preview; posted/void history, original economics | `sales.invoice.view` | Existing `sales.document.pdf` / `sales.document.share` | Opt-in posted only; void invalidates access |
| Sales Return, A4 HTML/PDF | Exists | Draft/posted/void status; original invoice, credit convention | `sales.return.view` | Existing `sales.document.pdf` / `sales.document.share` | Opt-in posted only |
| Customer receipt, A4 HTML/PDF | Exists | Exact payment/document/base allocation legs and reversal/payment-method labels | `money.receipt.view` | Existing `sales.document.pdf`; new `money.receipt.share` + existing `sales.document.share` for sharing | Private default; opt-in unreversed canonical posted receipt only |
| Customer Statement, A4 HTML/PDF | Exists | Validated range/as-of/currencies; opening/activity/closing; issued share snapshot | `sales.statement.view` + `customers.statement.view` (reconcile current route vs domain catalog alias explicitly) | Existing `sales.document.pdf` / `sales.document.share` | Sensitive opt-in; fixed issuance DTO, required password/finite expiry |
| Purchase, A4 HTML/PDF | Absent | New private commercial voucher plus quantity-only projection | `purchasing.purchase.view`; costs only with `purchasing.cost.view` | New `purchasing.document.pdf` | Disabled under ADR 0005 |
| Purchase Return, A4 HTML/PDF | Absent | New private return/debit document; original Purchase reference | Same Purchase read authority; cost entitlement as above; viewing does not require mutation permission | New `purchasing.document.pdf` | Disabled |
| Vendor Payment, A4 HTML/PDF | Absent | New private payment advice; dual-currency legs, later applications/reversal, Check distinguished from clearance | Exact `VendorFinancialRead`: `purchasing.cost.view` + (`money.vendor_payment.create` OR `.allocate` OR `.reverse` OR `vendors.statement.view`) | New `purchasing.document.pdf`, no new alternate finance-read bypass | Disabled |
| Vendor Statement, A4 HTML/PDF | Absent | New private range/cutoff/native-currency statement from accepted query | `vendors.statement.view` + `purchasing.cost.view` | New `purchasing.document.pdf` | Disabled |
| Expense / landed-cost voucher | Absent | Evaluated, deferred: useful but separate snapshot/private attachment/classification work; no writer changes to satisfy print | Future `money.expense.view`; landed costs additionally `purchasing.cost.view` | No Phase 9 addition | Disabled |
| Money transfer / Check confirmation | Absent | Evaluated, deferred: existing operational views/registers serve current need; avoid ambiguous instrument/settlement receipt | Future `money.transfer.view` / `money.check.view` + source-specific cost/HR authority | No Phase 9 addition | Disabled |
| Stock transfer / adjustment slip | Absent | Evaluated, deferred: traceable source exists; no new delivery workflow or fictional immutable warehouse labels | Future `inventory.stock.view`; optional cost `inventory.cost.view` | No Phase 9 addition | Disabled |
| Payroll / advances / payslips | Absent | Deferred due confidentiality and separate salary threat model | Future `employees.view` + `payroll.salary.view` and specific source boundary | No Phase 9 addition | Never public |
| Other 69 report variants | Screens/CSV | Existing coverage preserved; blanket PDF and bespoke layouts deferred | Existing Registry/ReportingGuard/PresentationPolicy exact capability map (ADR 0008) | No broad export bypass | Disabled |
| Product catalog | Absent | Private preview, responsive public cards, bounded print/PDF | Product selection: `inventory.stock.view` OR `inventory.product.manage`; new `catalogs.view/manage/publish/share/revoke/show_prices` by action | `catalogs.view` for private output; publish/share explicit | Selected products only; prices OFF |
| Barcode labels | Absent | A4 sheet presets and bounded quantities; stored code/unit | `inventory.stock.view` OR `inventory.product.manage` | New `inventory.barcode_labels.print` | Private label output; authenticated QR or explicit catalog link only |

Settings editor uses new `settings.documents.manage` plus `settings.company.view`. Do not infer it from permission to print. Catalog `manage` covers create/edit/select/reorder; `publish` approves a preview; `share` creates/recovers a capability; `revoke` pauses/revokes access; `show_prices` permits exposing or changing price data. A priced publish requires `publish` + `show_prices`. New receipt sharing requires both its source-specific permission and existing Sales share permission.

Upgrade only Owner with new catalog grants using accepted idempotent provisioning; preserve every existing non-owner/custom grant exactly. New-company Owner/Administrator retain the current all-catalog default convention; no new default grants to other roles. No role-name bypass, fake Company/User, economic row or sequence increment. Source identifiers/media/configuration may never fall back across tenants. Private adapters require fresh checks independently of mounted UI state.

## 5. Reusable architecture and exact boundaries

Reuse `DocumentData` -> `DocumentRenderer` -> HTML/`PrintRenderer`/`PdfRendererService`; retain mPDF/Endroid/Brick and dedicated PDF-safe CSS. Add private Purchasing adapters beside existing domain reads, with one shared presentation-options contract only where two actual consumers justify it. Avoid a wholesale namespace migration or speculative framework. A private DTO may contain authorized acquisition data; public DTOs must exclude it before construction.

Purchasing anchors: `Services/Purchasing/{PurchaseReadModel,PurchaseReturnReadModel,PurchaseIdentitySnapshot,VendorFinancialRead,PurchaseAcquisitionValue,PurchasePayableAsOf}`; `Domain/Purchasing/Queries/VendorStatementQuery`; `Models/{VendorPayment,VendorPaymentAllocation}`; `Actions/Purchasing/PostPurchaseAction` refreshes party/company snapshots atomically. Sales anchors: `Domain/Sales/Queries/CustomerStatementQuery`, receipt allocation models and `Services/Money/{CustomerPaymentHistory,ReceivablePositionAsOf}`. Check presentation uses `Check::partyDisplayName()` and source-specific `CheckFinancialSourceResolver`; never assume generic Check view permits Vendor/Payroll values.

Source adapters read stored strings/BigDecimal only: commercial currency, stored base currency, historical FX/account/tax/conversion/quantity and cumulative allocations. Original purchasing unit cost may retain greater precision than displayed currency totals. No Blade arithmetic, posting, balance cache update, sequence allocation, source snapshot backfill or lifecycle transition. Rendering failure fails the output request only; a previously posted event stays committed.

Prepare data with a bounded consistent-read policy, release DB locks before mPDF/download, then reauthorize before delivery. No DB transaction remains open while a guest downloads. Shared outputs may write only explicitly approved share/audit/view metadata, never financial history. A render option cannot create a token automatically.

Historical identity/economics are immutable; layout is the current reviewed renderer version, not byte-identical archival certification. A historical logo requires frozen provenance. Optional current decorative Company branding is explicitly current presentation, never claimed as posting-time identity and never copied into old snapshots; missing/untrusted media is omitted. Current optional quotation media remains permitted by ADR 0004 and is not promised as frozen image bytes. A future posting-time logo/media snapshot policy requires a separate reviewed canonical posting change, outside this output-only scope. Source notes/terms stay stored facts; current style/footer options must not silently replace agreed commercial terms.

## 6. Minimal schema and migration safety plan

**Planned, not executed:** two additive forward migrations are likely sufficient; finalize exact DDL only after this contract is accepted.

1. `catalogs` and `catalog_items`: company IDs, stable public ULID, bilingual names/descriptions, default locale, active/published state, field flags, `show_prices DEFAULT FALSE`, nullable enabled currency/tax-basis label, audit actors/timestamps, monotonic draft/published revisions and a nullable versioned safe `published_payload` JSON projection. Editing draft fields/items cannot alter that approved public projection before publish. Ordered items store company/catalog/Product, optional bilingual display overrides, selected Unit when needed and nullable exact `custom_price DECIMAL(20,6)`. Price validates currency minor units. Composite ownership FKs/indexes, unique `(company_id,catalog_id,product_id)` and deterministic order. Existing parent keys may need additive composite unique indexes; no duplicate-key/schema assumptions. Reuse existing Product/image/unit records, no media copy table by default. Public reads revalidate current Product eligibility/media without substituting unpublished draft content.
2. Nullable additive share metadata: versioned subject profile, immutable validated statement scope and encrypted issuance DTO/hash/time; quotation revision fingerprint; management request key + economic-free payload fingerprint for retry convergence. Existing `encrypted_token`, `is_active`, token hashes, passwords and expiry retain names/values. No polymorphic generic serialization, duplicate token store or SQL enum rebuild. Share/catalog metadata schema only; no new ledger/stock/document-number tables.

Creation/publish must compare payload as well as idempotency key under Company-first locks. Concurrent exact retries return one result; changed intent conflicts. Catalog optimistic revision prevents lost updates; stale publish cannot authorize a different preview. Enforce tenant ownership at DB and service boundaries. No soft deletion may leave public access usable.

Test on freshly owned disposable MariaDB: forward/backward/forward, original rows and token/password ciphertext preservation, FK restrictions, cross-company inserts, role grants/counters, retry races and old-code/new-schema compatibility. Rollback must refuse to silently destroy published catalog/share snapshot data; operational rollback normally restores code while retaining additive schema. Backups and later deploy are separately authorized. Never edit an applied migration or reset persistent data. No migrations are created/applied as part of this documentation PR.

## 7. Public sharing security contract

Reuse `PublicShareService`, with one policy path used by create/recover/revoke/public HTML/public PDF/QR. Fail closed on unsupported subject, foreign subject/media, inactive Company, draft/void/reversed source, expired/revoked share or unauthorized management actor. Guest authority comes only from the specific share; no ambient Company or generic authenticated output route is reused as a bypass.

New financial defaults: quotation/invoice/return/receipt expire after 30 days, maximum 365, no perpetual new financial grants. New statements expire after seven days, maximum 30, password required. Password optional for other commercial documents but visible in preview. Catalogs default no expiry with optional password/expiry, since they are managed public marketing content; pause/revoke is immediate. Existing grants retain tokens and recorded expiry; expose truthful legacy status and encourage replacement, never invent historical expiry/scope.

Create/recover requires source-view + share authority. For company-owned grants, removal of the creator's role does not by itself create a new financial permission for recipients; managers can explicitly revoke the grant. New private requests reauthorize fresh membership/roles on every operation. Company disable always denies public access. Architect must explicitly accept this company-owned lifetime model; do not silently add or remove issuer-role coupling in implementation.

GET never consumes query passwords. POST-only verification with CSRF, bounded input, local session unlock bound to share/token revision, and successful unlock expires no later than share/session limits. Every subsequent read rechecks revocation/expiry/source. Proposed initial limiter: five attempts/minute per share+IP, broader per-IP ceiling, generic error/no subject details, capped valid token syntax before hashing; use available database/file cache, no Redis. Measure and tune legitimate-user behavior. Different invalid tokens must not evade the IP limiter.

Financial responses and denied states explicitly send `Cache-Control: private, no-store`, `X-Robots-Tag: noindex, nofollow`, `Referrer-Policy: no-referrer`; equivalent HTML metadata; no sensitive social previews, analytics, external fonts or assets. Catalogs are noindex/no-store in V1 so lifecycle cannot be bypassed by a stale CDN/browser cache. Do not publicly cache token/password responses or PDFs. TLS URLs only, application-controlled host, QR encodes approved URL only; block arbitrary URL schemes/hosts/paths and no raw financial QR payload.

On-demand public PDF, if exposed, reuses the same token/password/status policy and stricter guest resource limits. No invoice file in public storage; local approved raster assets only, private temporary output, safe filenames/MIME/disposition, `finally` cleanup on renderer error/interruption. Share-management HTML/Livewire contains only currently authorized fields. Never log bearer tokens, passwords or full DTOs. Counters increment atomically after successful subject build, with minimized audit metadata. Revocation stops future requests/chunks; already delivered bytes cannot be recalled.

Mandatory negatives: short/oversized/guessed tokens, password query/guessing, removed roles/membership, inactive Company, tenant/media tampering, unsafe source lifecycle, stale instances, password-unlock after revoke, simultaneous revoke/download, SSRF/path escape, cached response reuse, source-sensitive keys in HTML/PDF/JSON/meta/QR and failed render cleanup.

## 8. Statement and quotation issuance policy

Resolve the prior proposal's fixed-range/live-recomputation ambiguity by recommending **fixed issuance data**, not an undisclosed live account. New Customer Statement share stores validated `from`, `to/as_of`, currency scope, Company timezone, issue instant/locale and the safe canonical statement DTO, including issuance party/company identity and exact opening/rows/closing. Freeze it immutably at authorized creation under consistent read; encrypt the stored payload and hash its canonical form. Backdated postings/master renames later do not rewrite an already issued share. New private statement generation intentionally produces a new read as of the selected range. Neither path introduces a second statement calculator.

Legacy statement shares with null profile remain clearly labelled **live full-account statements**, using their actual historical behavior; no fabricated original snapshot or silent narrowing. Management must offer explicit revoke/reissue as a fixed statement. Changing password/expiry cannot silently replace financial payload; regenerate a new share for a changed range. A frozen issued statement may intentionally precede later reversals; show issue/as-of labels and check only share/Company validity, not today's open balance.

New quotation shares bind the issued revision's economic/identity fingerprint. Draft transition denies access; resend with changed payload requires explicit reissue, rather than letting the old link silently expose revised terms. Existing legacy quotation grants cannot be assigned their unknown original revision retroactively. Preserve token identity and label legacy semantics; management can explicitly revoke/reissue. Do not rewrite quotations or posting snapshots to implement this policy.

This is an architect policy checkpoint, not a claim that snapshots already exist. It adds read-output provenance only. Historical shared invoice/return/receipt values use canonical stored sources and explicit current validity (void/reversal denies); financial DTO construction never falls back to current masters.

## 9. Catalog data and price policy

Managed live catalog: an explicitly approved edit updates the same stable link/QR. Private drafts/edit revisions are not public until publish; published updates retain stable subject/token. Pause/expiry/revoke/Company disable must deny both HTML and QR destinations immediately. Each request revalidates status and selects only this catalog's same-company enabled Products. Hide inactive/deleted Products in public content; administration retains their selected history for correction.

Public whitelist: catalog title/description, approved Company contact fields, selected Product display name, opted-in description/SKU/image, safe unit label, and price only when explicitly enabled. No cost/profit/stock count/warehouse/supplier/customer/employee/internal notes, IDs, private file paths or historical purchasing data. Prices-off output must omit price **keys and values** from all DTOs, HTML, JSON, PDF and serialized state; CSS is insufficient. Images lazy-load trusted public thumbnails with graceful placeholders and alt text.

Priced V1 uses explicit catalog-item selling prices in one enabled currency and a configured transaction Unit; no automatic price changes, FX conversion, purchase-cost inference or historical-quote claim. Current suggested sale price can be an editor suggestion only. Require `catalogs.show_prices`, explicit preview acknowledgement and tax-inclusion/basis label before priced publish or price change. Missing price says “Price on request,” never zero. Disabled/incoherent currency or Unit blocks priced publication; price-less content can remain available after removing prices server-side with a truthful state. Catalog edits never update Product defaults or financial history.

Search/pagination preserves selection/order across filters and beyond record 100; server caps and indexed scoped search, not full master hydration. Proposed max 250 selected items; browse in bounded pages (24 items) and prepare catalog PDF only on an authorized explicit request. Public query parameter cannot toggle prices or reveal unselected items. No checkout/stock promise/e-commerce tracking.

## 10. Arabic/English visual direction and UX flows

Approved reference evidence is settings-only: `references/ui/small-trader-settings-v2.html` and its preview PNG were inspected. Use its white surfaces, blue action accent, restrained borders, clear grouped settings hierarchy and existing Laravel/Livewire/Tailwind tokens for the document settings screen. The reference's invented sample business values are not production data. No approved catalog/invoice reference exists; the following functional layouts are planning candidates. Review concrete AR/EN previews at P9-A/P9-D checkpoints, without a per-file owner approval cycle.

| Surface | Layout / workflow |
|---|---|
| Business detail | Existing status/header -> View / Print / Download PDF -> Share only when permitted; locale selector independent of UI; restricted actions backed by server denial |
| A4 document | Historical Company/party -> clear type/number/status/date/currency -> item table with exact quantities and units -> commercial totals/payment/reference -> terms/footer -> optional existing authorized QR; continuation number + page x/y |
| Share manager | Selected source/revision preview -> expiry/password -> explicit Create -> Copy/QR/WhatsApp/email composition -> active/expired/revoked status and Revoke; no auto-create on print |
| Catalog composer | Products -> search/select/paginate -> accessible reorder -> field flags, language, prices OFF -> private preview -> explicit publish -> stable-link management |
| Public catalog | Compact Company/title -> responsive selected-product cards -> unit/SKU/image fallback -> optional labelled prices; no internal navigation/accounting data |
| Label sheet | Selected Product + configured barcode Unit -> stored symbol/code and caption -> quantities/preset sheet preview -> print; no invented GTIN |

Arabic first, complete English; local fonts on sensitive pages. Isolate numbers/ISO codes/document IDs as LTR runs inside RTL; do not reverse code digits. Target 320/390px phones, 768px tablet, 1440px desktop; 44px touch controls, visible focus, keyboard menus/reorder fallback, labelled inputs, contrast and localized progress/error/empty/restricted states. Wide tables scroll within their container; public catalog avoids document-level overflow. Separate screen CSS from mPDF-safe CSS, repeat headings, protect totals from splitting and avoid mostly empty terms pages. PDF can use selected render locale without mutating posted locale/identity.

Label V1: bounded A4 presets plus one simple label dimension, no printer designer. Generic internal code defaults to Code 128; EAN/UPC only after digit/check-digit validation, never manufactured registration. `ProductBarcode.unit_id` is a Unit ID, not ProductUnit ID; resolve through the same configured ProductUnit, and null uses canonical base Unit explicitly. A carton barcode never becomes a piece label. Batch counts and total labels bounded; actual scanner/manual scan evidence is an acceptance gate.

## 11. Scope, exclusions and reconciliation decisions

Mandatory: existing Sales output regression protection/corrections; four Purchasing/Vendor private outputs; document settings/options/status/AR/EN; explicit new receipt sharing; issued Customer Statement scope; secure share management/QR; selected live catalogs with prices OFF; bounded barcode labels; user-triggered copy/Web Share/WhatsApp/mailto; integrated responsive/accessibility/security QA.

| Disagreement / earlier assumption | Disposition for architect review |
|---|---|
| Owner roadmap says production verification still in progress | Carried independent acceptance supersedes stale text; original bytes preserved |
| Earlier proposal P0–P7 vs kickoff P9-A–F | One package system: P9-0 then P9-A–F below |
| Earlier proposal excluded labels | Latest owner kickoff explicitly includes labels; include bounded P9-E |
| Earlier proposal broad operational/payroll/report outputs | Freeze four required Purchasing outputs; defer evaluated additions above; Master long-term coverage is not a mandate for all outputs now |
| Earlier statement proposal fixed filters but live recomputation | Fixed issuance DTO recommended; explicit legacy compatibility, no invented retrospective snapshots |
| Illustrative Blueprint token/schema names vs actual source | Reuse actual `encrypted_token`, `is_active`; do not rename existing columns |
| Catalog automatic current-list-price vs explicit item price | Explicit managed selling prices/Unit/currency; price off default; live catalog updates remain deliberate |
| Future AI/mobile | Phase 10 hardening -> 11 commands/API -> 12 optional mic/text -> 13 optional mobile; no Phase 9 AI code |

Excluded: financial writer changes, canonical snapshot backfills, mutable history, e-invoicing/legal certification, salary/public Vendor/Expense/stock sharing, all-report PDF parity, automated SMTP/WhatsApp/SMS, bulk campaigns, camera scanning/POS/OCR, delivery-note business module, native app/PWA/offline posting, API/AI/provider/audio/microphone implementation, mandatory Redis/Docker/Node server/WebSockets/Supervisor, production fixtures/access/deploy.

## 12. Detailed AGY delegation plan and dependencies

P9-0 delegation completed automatically through the installed AGY relay: `status=completed`, exit 0, `readOnlyViolation=false`, conversation `83b00241-f8bb-46f7-a84a-49dfdc11650e`. Codex reads the source independently and owns policy, synthesis, tests and acceptance. The worker had no commit/push/merge/deployment authority and ran no tests. Automatic transport failure would lead to a self-contained manual brief; never bypass AGY permissions automatically. This planning turn creates no runtime branch/worktree.

Independent worker review corrected its Purchase Return view-permission claim (`purchasing.purchase.view`, not mutation `purchasing.return.manage`) and Vendor Payment claim (the exact `VendorFinancialRead` intersection, not `money.expense.view`). Cashier has no default PDF grant in current `seedCompanyRoles`; do not inherit the worker's role table. A Blade wrapper calling a read builder is not itself a forbidden financial writer. Excluding later receipt applications can be intentional initial-issuance history: preserve initial allocations and label any later-application appendix distinctly. The suggested VendorPayment money-account snapshot migration would require canonical posting changes, so it is deferred; omit unknown historical account identity instead. Worker schema names and zero-test claims were not accepted blindly. The lead read the complete roadmap even though the worker's listed command read its first 100 lines.

After architect acceptance and explicit implementation authorization, use one integration branch `phase/9-documents-catalog-sharing` from the then-verified exact main. Isolated AGY linked worktrees use CleanHead/controller workflow. Each worker brief pins full input SHA, allowed/excluded paths, DTO/schema/permissions/status contracts, exact tests, expected artifacts and handback; no commits/pushes/PR/bot/deploy/production actions. Codex independently Collects/reviews/Accepts before integration. Commit/Push transactions require session authority; merge/deploy always separate explicit owner actions.

| Package | Sequential tasks / deliverables | Ownership and dependency | Gate |
|---|---|---|---|
| P9-A | A1 lock source/options/DTO compatibility; A2 correct receipt legs/status/locale; A3 settings editor and print-safe branding; A4 continuation/terms/limits/asset/headers | Codex owns DTO/authority/resource contract; AGY owns bounded templates/settings after A1. Depends on accepted P9-0 | Existing five routes preserved; rename/units/FX/status/redaction/zero-write tests and AR/EN PDF rasters |
| P9-B | B1 Purchase/Return private adapters; B2 Vendor Payment exact allocations/method/reversal; B3 Vendor Statement range/aging/native currency; B4 detail actions and permissions | AGY one source-family package at a time; Codex reviews Purchasing economics. Depends on A1/A2 and accepted permission names | Four required outputs with exact canonical comparisons; restricted/foreign/stale/missing snapshot cases |
| P9-C | C1 source-share policies/fresh authority; C2 additive issuance/retry metadata; C3 passwords/throttles/cache/Company/state; C4 manager/QR/public-PDF consistency and legacy handling | Codex owns policy/service/schema; AGY only approved manager/template files. Depends on A1/A2; B can proceed independently | Complete adversarial subject/legacy/issued-statement/revocation/race/secret tests |
| P9-D | D1 additive catalog schema/integrity/grants; D2 bounded composer/revision preview; D3 price-less/priced public whitelist; D4 live-link pause/edit/revoke/image/pagination UX | Codex owns schema/security contract; AGY isolated catalog files. Depends on C policy/schema integration and A renderer | 125+ selectable Products; same-company FKs; price keys absent by default; stale publish/races; mobile AR/EN |
| P9-E | E1 stored barcode/Unit resolution and bounded presets; E2 scan-tested labels; E3 deliberate copy/native-share/WhatsApp/mailto composition | AGY labels/actions; Codex validates Unit identity/privacy. Depends on A and C; catalog actions after D | Stored codes scan to exact Product+Unit; deliberate external-app launch only, no auto-send |
| P9-F | F1 integrated route/RBAC/asset review; F2 scoped regression/static/build/migration QA; F3 AR/EN phone/tablet/desktop and real PDF/print/scan evidence; F4 ADR 0009 and exact-head handoff/PR | Codex owns integration/final acceptance. Depends on A–E accepted work | One independently reviewed exact candidate; no critical snapshot/authority/public/resource failures |

Parallelism only after contracts freeze: AGY presentation and source-adapter tasks can run independently; schema/security integration is sequential. Never share canonical file ownership between Codex and AGY. Changes to `DocumentDataBuilder`, `PublicShareService`, permission catalog, routes or migrations need explicit exclusive ownership. Final worker handback includes base/head, actual diff/allowed files, tests/assertions/logs, permissions/schema, no economic writes, limitations, preserved files and no production access. Worker claims never substitute for Codex review.

Engineering dependencies: installed locked PHP 8.4/Laravel 13/Livewire 4/mPDF/Endroid/Brick; GD/mbstring/intl; MariaDB for decimals/FKs/locking; local trusted fonts/assets; prebuilt Vite; file/database caches; private storage and cleanup. Installed mPDF includes `src/Barcode/Code128.php` and `EanUpc.php`; prefer that existing symbol support rather than a new barcode dependency, subject to actual layout/scan/Hostinger verification. Verify security advisories at implementation/release time; P9-0 makes no fresh dependency-audit claim.

## 13. Executed discovery QA and rendering evidence

Local runtime used explicitly: PHP **8.4.25** at the established WinGet path; shell default PHP 8.2.12 was rejected for project execution. Existing `tests/Support/run-phase8-disposable.php` creates a uniquely owned `accounting_p8_tmp_*` schema, forward-migrates it and cleans it after execution. No persistent development/production schema reset. Discovery PHP/Python/PDF files are ignored evidence, not shipping runtime or permanent acceptance tests.

| Run | Actual result / meaning |
|---|---|
| `PublicShareSecurityTest.php` through disposable runner | **7 tests / 29 assertions passed**; inherited token/hash/password/expiry/revoke/whitelist coverage only |
| Ignored `DiscoveryPdfProbeTest.php`, filtered discovery method | **1 test / 17 assertions passed**; existing `SalesInvoicePostingAndFefoTest` stock/unit fixtures adapted through canonical actions, four posted invoices, customer/company/Product renamed afterward; no render DB writes observed |
| Four PDF samples and extracted/rastered pages | AR/EN one-line: one page each; AR 60-line: five pages; EN 60-line: six pages. JOD 3.123/6.246 and exact 236.582 total retained; piece unit visible; renamed masters absent |
| Ignored `DiscoveryShareProbeTest.php`, filtered discovery method | **1 test / 5 assertions passed as observations**: share-only actor can create Invoice share without invoice-view permission; GET query password unlocks; inactive Company share still resolves. All three are reproduced gaps, not intended acceptance passes |

Current renderer timing on this local process: Arabic first render 3.376s, Arabic 60-line 0.522s, English one-line 0.195s, English 60-line 0.496s; 42,622–75,155 bytes; process-wide peak approximately 94 MiB including fixture/test runtime. Warm samples are not independent cold benchmarks; no Hostinger SLA/memory claim. Arabic shaping and mixed script are readable in inspected samples, with repeated table headings. No continuation identity/page numbers; English long terms split into a mostly empty sixth page. UI/reference and PDF inspection are distinct from browser responsiveness/physical-print/scanner certification.

Poppler was unavailable; an isolated ignored PyMuPDF installation rasterized/extracted these discovery PDFs. No Composer/npm dependency change. Samples exercise complex text, stored stock Unit and JOD, but not full Purchase/receipt/tax/image/0/100-line matrices. Those are explicit implementation acceptance gates.

Proposed preflight envelopes for implementation, **targets requiring measurement/enforcement, not current guarantees**: commercial docs 500 lines; statements 1,000 source-history entries before full-history hydration; catalog 250 items; labels 500 total. All PDF output: 50 pages, 15 MiB, 15s preparation deadline; public financial render uses an additional bounded per-share/IP limiter. Over-budget refuses before any data delivery, never truncates. No reliable hard CPU cancellation is implied by a timer around mPDF; cap text/image/input upfront and demonstrate pathological-case behavior before accepting synchronous deadlines. Reduce caps if measurements cannot fit shared-host limits. Preserve Phase 8 CSV 50,000 rows/50 MiB/30s and 64KiB authority checks.

## 14. Implementation testing and independent acceptance matrix

| Area | Required evidence before source acceptance |
|---|---|
| Economic truth | Fixture-based exact DTO/HTML/PDF comparisons, ILS/USD/JOD, tax/discount/return/void, selected Unit/conversion, stored FX and separate allocation legs; no floats or source recalculation |
| History/provenance | Rename/inactivate/delete masters, change current configuration/FX/timezone; same posted identities/economics; unavailable unsnapshotted context honest; issued statement/quote revision immutable |
| No side effects | Read-output requests leave economic row hashes/counts, stock/GL, lifecycle, allocations and numbering unchanged; only authorized share/audit metadata differs |
| RBAC/tenant | Owner + restricted finance/warehouse/viewer roles; source and output separately removed; fresh role/membership after mount; foreign IDs/media/settings and inactive Company fail; restricted keys absent even empty outputs |
| Public security | Threat cases in §7, stable legacy tokens, new receipt allowlist, frozen statement vs later backdated/reversed events, per-request passwords/revoke/expiry, no query secrets/cache/third-party leaks |
| Schema | Disposable MariaDB forward/backward/forward; DB same-company FKs, old token/ciphertext preservation, production-data-preserving upgrade and code rollback; role grants/counters preserved |
| Retry/concurrency | Exact request key+payload convergence, conflicting payload denial, stale revisions, concurrent publish/create/revoke and source lifecycle transition; no orphaned granted output |
| Catalog/labels | Prices OFF with absent keys; explicit price/Unit/currency/tax label; selected-only 125+ fixture pagination; live changes/pause/revoke; stored valid barcode scan and correct carton/piece mapping |
| Rendering/UX | AR/EN 0/1/10/100-line cases, mixed scripts/long terms/images/missing logo, two–five-page continuation, 320/390/768/1440px, focus/keyboard/touch/error/restricted states, physical print and scan evidence |
| Runtime/resources | PHP 8.4/Laravel 13 locked compatibility; Pint/Larastan/Blade and production frontend build; safe local media, input bounds, deadline refusal, failure/interruption cleanup, private storage |

During packages run focused new tests and directly affected inherited tests. Relevant anchors: Phase4 `PublicShareSecurityTest`, `RemainingSalesIntegrityTest` actual route/locale tests, `Phase4AcceptanceProbeTest` posted public identity, Quotation provenance/lifecycle tests; Phase5C posting/snapshot, Phase5D security/immutability, Phase5E Payment/Statement, Phase6 cross-currency and historical-void tests; Phase8 guard/presentation/source tests only when touched. Permanent new tests should assert business/security outcomes, not mirror template code.

At integrated convergence run one complete Phase 9 suite plus the justified inherited subset, `composer qa` under a guarded disposable configuration (its test step is broad, so do it once), production frontend build and Blade compilation. Add migration round-trip only for actual new schema. Reconciliations are required if economic/query boundaries change; otherwise run a representative zero-write reconciliation gate once if needed for integration evidence, without repeated whole-domain fanout. Report actual test/assertion counts and exact candidate SHA; no inherited counts presented as rerun results. P9-0 docs-only work does not require composer QA/build/migration round-trip or production acceptance rerun.

Independent architect checks canonical source around the diff in AGENTS order: business/decimals, atomicity/bypass, tenancy/redaction, retries/concurrency, snapshots, migrations, runtime, UI. Reject candidate for any incorrect economic/Unit meaning, private value leak, revocation bypass, alternate writer, destructive migration, unscannable labels/QR or unverifiable worker gate. Review actual raster/browser artifacts, not worker prose alone. Minor icon/advanced printer/report parity/SMTP/AI/mobile improvements are outside this frozen acceptance.

## 15. Future roadmap — preserve ordering

**Phase 10:** production hardening, complete AR/EN responsive/RBAC coverage, performance/threat remediation, backup restoration on disposable environment, RPO/RTO, operational logging/incident readiness and real-client onboarding. Existing known observations stay evidence-qualified. Phase 9 share flaws in changed scope are fixed before Phase 9 acceptance, not deferred wholesale to hardening.

**Phase 11:** authenticated, tenant-scoped, versioned draft/preview/confirm command interfaces for web/mobile/future AI, exact decimal schemas, expiry/revision-bound confirmations and idempotency. All clients call the same canonical actions; no alternate posting writer. API exposure waits for critical Phase 10 security gates.

**Phase 12:** optional contextual Sales microphone with equivalent text fallback. Transcription/interpretation -> deterministic Company-scoped entity/Unit/currency/stock/permission resolution -> exact server-built proposal -> comprehensible preview -> explicit authenticated confirmation -> canonical action -> secure audit. LLM never calculates/posts financial truth. Keys server-side/securely configured or encrypted; provider abstraction, consent/data minimization, retention, budgets, outages/manual fallback, replay protection and no voice-only implied confirmation. Provider APIs/prices evaluated when that phase is authorized.

**Phase 13:** optional PWA/native decision based on actual phone usage, scanner/device/offline needs, cost and maintenance; depends on Phase 11 and product approval, not mandatory broad AI adoption. One Laravel accounting backend; offline draft sync requires fresh validation/confirmation and never claims server-posted history prematurely.

## 16. Decisions for the one architect checkpoint

Recommended defaults are fixed here for review, avoiding a minor-decision questionnaire: four new private Purchasing outputs; operational/payroll/report expansions deferred; bounded labels included; fixed issuance statement DTO with explicit legacy live compatibility; revision-bound new quotation sharing; company-owned grant lifetime with Company-disable denial; private financial defaults/finite new expiry; price-less managed live catalog with explicit item prices when authorized; current template layout without invented historical branding; A4 baseline; no automatic messages.

Material unresolved acceptance items: architect disposition of fixed issuance vs live recomputation, legacy quotation/statement disclosure labels, company-owned grant lifetime, exact statement permission intersection and proposed resource caps. These are review choices, not authorization to improvise policy in code. No contradiction requires stopping independent discovery. Final DDL/index names, renderer limits and concrete visual previews are engineering tasks within accepted contracts; changed financial writers/history or additional public subjects require a new bounded decision.

The documentation PR retains `098b17bf…` as ancestry, includes the unchanged supplied roadmap copy and this lock, and updates continuity/status references. Record the final exact commit/PR and verification in the handoff, leaving main and production untouched. Final checks include all nine prior owner hashes plus the root roadmap, byte-identical roadmap copy including its staged Git blob, local document links and 16 required contract sections. `git diff --check` passes for authored changes; the original roadmap's Markdown hard-break/trailing spaces are deliberately retained rather than changing supplied bytes. Independently queried local MariaDB 10.4.32 confirms all three owned discovery schemas are absent after cleanup. An initial cleanup-verifier path typo was corrected before that query; it was verifier code, not application behavior. No composer QA/static/build/dependency audit or economic reconciliation was rerun for this documentation-only change. Deliberate new ignored artifacts: AGY brief/report/log/result, discovery probes/logs, four PDF/HTML samples and page rasters/text/manifest, isolated raster tooling and hash/link/schema verification under `.ai/delegations/20261009-p9-0/`. Existing untracked owner originals/screenshots remain untouched. Do not create the runtime feature branch until documentation baseline/scope are accepted and implementation explicitly authorized.

**PHASE 9 P9-0 DISCOVERY, DOCUMENTATION AND EXECUTION PLAN READY FOR ARCHITECT REVIEW**
