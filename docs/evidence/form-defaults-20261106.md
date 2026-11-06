# Form defaults — 6 November 2026

Baseline: `90fe99944ce81e53147289eefb37700fff7f534a`.

- Customer/vendor payments prefer eligible base-currency accounts; invoice/purchase entry retains source currency and requires a matching account or an explicit user choice. Foreign settlement FX remains explicit rather than copying historical invoice rates.
- Base-currency rates are hidden and normalized to exactly one before validation across payments, expenses, payroll, checks and transfers. Existing canonical writers and authorization remain authoritative.
- Payment language/reference/notes are directly visible. Multi-field customer/vendor details share an accessible switch; edit forms retain full details. Removed an unmatched vendor form wrapper discovered by Livewire regression testing.
- New employee hire date and sales return date use today in the company timezone. Existing document dates already do so; stored edit dates, payment terms, expiry dates and payroll periods retain their domain meaning.
- No migrations or dependency lock changes.

Verification on disposable loopback MariaDB 11.8.8 (temporary schemas cleaned):

| Batch | Tests | Assertions |
| --- | ---: | ---: |
| FormDefaultsTest, account eligibility, HumanWorkflowRefinementTest | 26 | 219 |
| Phase7 UI/payment workflows, cross-currency customer/vendor payments, vendor payment UI | 28 | 181 |
| Final FormDefaultsTest including midnight timezone regression (reruns four earlier tests) | 5 | 37 |

151 JavaScript tests passed. Full Pint passed; final PHPStan passed; production Vite build and Blade cache compilation passed. npm audit: zero vulnerabilities. Composer audit: 10 existing advisories across five packages in unchanged lockfile; dependency remediation is outside this patch.

Browser verification: NOT VERIFIED. Shared preview failed because T3 runs as root and Chrome sandbox cannot start. Sandbox was not disabled.

Release state at preparation: no production source/assets/data changed, no merge or deployment performed. Hosting MCP authenticated read confirmed expected existing document root. Controlled release still requires SSH access, coherent verified off-host recovery, preservation fingerprints, reconciliations and authenticated browser qualification.
