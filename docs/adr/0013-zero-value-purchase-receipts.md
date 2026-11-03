# Zero-value purchase receipts

Owner decision, 2 November 2026: zero cost is valid, including an entirely free supplier delivery. Positive quantities, units, lots, company authority and immutable history remain mandatory.

`PostPurchaseAction` remains the sole business boundary. It locks the company/document, validates the complete draft, numbers it, records canonical stock receipts, completes immutable line/lot provenance, finalizes the document and records its audit atomically. The opaque posting scope binds actor, transaction, document and allocated number. Incomplete scopes roll back.

The posting builder returns no financial command only when commercial totals in both currencies, inventory acquisition value, taxes and landed value are all exactly zero. No payable, fictitious cent, zero journal line or posting batch is created. A free delivery with capitalized freight still uses the normal nonzero financial command. A nonzero foreign-currency component that rounds to zero base remains invalid rather than losing currency metadata.

Posted validation rebuilds stock/value provenance and requires a null batch only for this exact zero-effect case. Reporting, price history and stock/accounting reconciliation recognize the numbered, audited receipt. Returns reuse the existing zero-value return workflow; later average-cost changes can still require real valuation-adjustment accounting. Posted records remain immutable.

No schema migration or historical rewrite is required. Before source rollback, retain the forward-compatible release if new zero-value receipts exist; older code assumes all purchases have a financial batch. Never fabricate journals, delete receipts or restore a stale database to make a downgrade fit.

Disposable MariaDB regressions cover canonical receipt/retry, zero-payable GL absence, price/report visibility, return, six-domain reconciliation, late-audit rollback, forged-capability refusal and nonzero-history tampering.
