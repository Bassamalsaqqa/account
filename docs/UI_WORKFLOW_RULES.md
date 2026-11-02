# Everyday workflow and interface rules

These Owner decisions apply to tenant and platform interfaces in Arabic and English. Reuse the Laravel/Livewire/Alpine/Tailwind components; use shadcn interaction and layout patterns without adding a second application framework.

## Persistent rules

- Write for a small Palestinian business owner, not an accountant. Arabic should be natural, concise and familiar locally; English must be equally clear. Name the user's action and result. Avoid internal terms such as posting, FEFO allocation, confirmation tokens or allocating credit in routine screens. Explain a real consequence only when it helps a decision; do not repeat instructions beneath obvious fields.
- Sign-in plus an active, explicit platform-operator grant permits control-plane browsing without repeated password confirmation. Sensitive mutations retain their specific confirmation and authorization checks. No compulsory MFA; no expansion of operator grants.
- Default document/activity lists to most recently created first with a stable descending ID tie-breaker, so a newly entered backdated draft remains easy to find. Lookup/select options may use alphabetical ordering. Default table page size is 50 with 100/200 options. Never change economic processing order, payment-allocation order or FEFO to implement display sorting.
- Keep ordinary desktop table/item rows on one line with properly bounded fields and reserved action space. Long text may truncate visually but remains accessible. Validation errors may occupy another line. Mobile uses usable responsive cards rather than unreadably compressed controls.
- Linked record rows open their primary detail page when their noninteractive area is clicked. Buttons, links, selection, modifier keys and editable cells retain their own behavior. Keep real keyboard-accessible links.
- Expose common permitted actions directly: open, edit, confirm eligible drafts, receive/pay money and PDF. Use clear consistent icons (banknote for payment; PDF-labelled document for PDF), accessible names and tooltips. Never make an immutable financial operation an unguarded click or permit posted-history deletion.
- Use compact desktop buttons and at least 44px touch targets. Put related actions inside/beside their field: camera in barcode input, full balance in amount input, new customer/supplier beside selector, new product beside product search. Increase/remove-line symbols must remain legible. Use restrained green plus/red minus with readable contrast.
- Create forms start with essential fields. Edit forms show all existing customer/supplier details by default. Optional create details use an accessible toggle. Use aligned responsive grids and available page width, avoiding unnecessary nested cards and wasted space.
- Show the compact warehouse/currency/due-date row directly. Hide exchange rate when the transaction uses company base currency; keep its canonical value exactly one and revalidate on the server. Show rate controls only for real currency conversion.
- New document language inherits the current interface language, subject to company-enabled languages. Printing defaults to that interface language. Editing existing drafts must preserve stored intent unless the user changes it. Product-image inclusion is an accessible main-form toggle.
- Non-destructive quick-create dialogs close with outside/backdrop click, Escape or Close; return focus to their trigger and preserve the parent draft. Internal clicks never dismiss. Prevent dismissal during an ambiguous in-flight save. Destructive/irreversible operations keep appropriate explicit confirmation.
- Money is displayed and entered with two decimal places. Unit-count quantities use whole numbers. Do not silently round existing fractional stock, exact valuation, FX, unit conversion or immutable history: investigate any conflict with supported fractional units before changing those contracts. Storage/arithmetic remain exact decimal strings.
- Quantity buttons react immediately in browser draft state and coalesce server synchronization; do not perform a database write per tap. Flush pending edits before save, validation or confirm; keep retry/conflict/offline protection. Browser-calculated totals are previews; server authorization, exact arithmetic and canonical posting remain authoritative.
- A payment launched from an invoice starts with that invoice and sensible account/currency defaults. The user enters received amount; remaining balance/allocation totals update clearly. Hide irrelevant conversion/advanced controls. Explain customer credit as money already received and not yet used, rather than accounting jargon.
- Treat slow navigation as a defect to measure. Separate HTTP/PHP/bootstrap, SQL, Livewire round trips and browser rendering. Compare repeated equivalent reads at low concurrency. Do not weaken tenant/security/commit checks or invent caches/indexes without evidence.
- Keep public Hostinger files manifest-only and source-map-free, replace builds atomically, preserve the configured root and verified off-host recovery. Remove only verified disposable staging. Preserve private files, translations, unique source and financial history.

## Correction map — 2 November 2026

| Order | Work package | Completion evidence |
|---|---|---|
| 1 | Keep production available and preserve the deferred UI release | Previous verified b36a834 reopened after exact file/data/security and six-domain checks; no database restore |
| 2 | Diagnose navigation and quantity latency | Source-pinned local HTTP/SQL/request counts; reproduce slow purchase navigation; bounded before/after measurements |
| 3 | Shared interaction primitives | Outside-click/Escape/focus, inline actions, numeric inputs, quantity stepper, icons and grids across analogous forms |
| 4 | Simplify documents and master-data forms | Invoice/quotation/purchase/returns parity, edit details visible, interface-language defaults, base-currency rate hidden |
| 5 | Simplify money collection/payment | Invoice-first amount entry, automatic remaining/allocation preview, full balance inside amount field, clear credit labels |
| 6 | Control-plane read authorization | Active grant remains mandatory; no repeated password for browsing; sensitive writes retain fresh confirmation |
| 7 | Consolidated verification and release | Frozen source, focused regression/QA package, AR/EN responsive checks, independent diff review, clean audits/build, controlled backup/deployment and both-company preservation |

Do not report a package complete from a mockup, worker report or copied historical test totals. Record actual evidence and any unresolved policy/data conflict.

## Initial measured findings

The synthetic Windows preview server had OPcache unloaded/disabled. Authenticated invoice/purchase navigation measured about 0.50–0.58 seconds per warm request; SQL occupied approximately 21–74 milliseconds. A temporary comparison using the same source and synthetic database with the installed OPcache enabled measured 0.10–0.17 seconds for the same routes, with similar query counts. This is local runtime evidence, not a claimed production speedup. Timestamp validation remained enabled, so source edits remained visible. The comparison server was stopped and the temporary profiler removed; the original preview and database were preserved.

The current payment form also does not automatically allocate a newly entered amount. Simplifying its fields must therefore include an explicit, permission-checked invoice-first allocation preview, rather than merely hiding the existing allocation controls. Canonical money services and exact allocation limits remain unchanged.
