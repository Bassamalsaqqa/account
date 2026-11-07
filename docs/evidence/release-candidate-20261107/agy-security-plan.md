# Release Candidate Dependency Security Resolution Plan

**Plan Date:** 2026-11-07
**Auditor / Agent:** Research Sub-Agent (`agy-security`)
**Repository:** `/home/t3dev/.t3/projects/small-trader-platform/account`
**Base Commit (HEAD):** `06e4f6d71b8dc3202f0708dff91a21b32a5afd33`
**Working Tree State:** Pristine `composer.json` and `composer.lock` strictly preserved (0 diffs). Zero vendor modifications performed.
**Mandate:** Investigate the 10 Composer security advisories identified in [agy-build-composer-audit.log](file:///home/t3dev/.t3/projects/small-trader-platform/account/docs/evidence/release-candidate-20261107/agy-build-composer-audit.log), evaluate architectural exposure, establish the minimal safe patch resolution, and provide the exact non-breaking execution command for parent coordination without mutating files while Huda database testing is active.

---

## 1. Executive Summary & Resolution Findings

During the release-candidate build audit on 2026-11-07, all build, static analysis, styling, and test gates passed with zero errors (`PHPStan` Level 6 clean, `Pint` clean, `view:cache` clean, `npm audit` clean with 0 vulnerabilities, JavaScript suite 56/56 passing). The sole open gate was `composer audit`, which reported **10 security advisories** affecting **5 transitive Symfony packages**, originating upstream on 2026-11-04.

### Key Investigation Outcomes
1. **Root Cause & Package Scope:** All 10 advisories affect 5 specific Symfony components: `symfony/http-foundation`, `symfony/http-kernel`, `symfony/routing`, `symfony/serializer`, and `symfony/yaml`.
2. **Current vs Fixed Versions:** The installed packages are on the `v8.1.x` minor line (specifically `v8.1.6`, `v8.1.7`, and `v8.1.8`). Symfony released patch version **`v8.1.11`** on 2026-11-04, which completely addresses all 10 advisories.
3. **Constraint Compatibility:** Every parent constraint (`laravel/framework ^13.0`, `laravel/ai ^1.2`, and `web-auth/webauthn-lib 5.3.x`) permits `v8.1.11` without modifying `composer.json`.
4. **Architectural Exposure:** Static inspection of `app/`, `config/`, `routes/`, and `bootstrap/app.php` indicates that real-world exploitability in this application is **low/non-exploitable** (no untrusted YAML parsing, no direct Symfony serializer normalizers, no host-placeholder routing, and standard reverse-proxy/middleware isolation). However, per the mandate, **available supported security patches are chosen over waivers**.
5. **Minimal Patch Resolution:** A targeted update of only the 5 affected packages resolves 100% of the advisories with **0 new packages installed, 5 patch updates, 0 package removals**, and zero side effects on the remaining 28 Symfony dependencies.
6. **Zero Mutation Compliance:** All evaluations were performed using read-only inspections and dry-run operations (`--dry-run --no-scripts --no-install`). `composer.json`, `composer.lock`, and `vendor/` remain untouched.

---

## 2. Inventory of the 10 Advisories & Affected Packages

| # | Package | CVE / Advisory ID | Severity / Title | Affected Range | Fixed in 8.1.x | Locked Version |
|---|---|---|---|---|---|---|
| 1 | `symfony/http-foundation` | CVE-2026-118514 | HEAD requests bypass methods option of security.access_control rules & firewalls | `>=8.1.0,<8.1.11` | `8.1.11` | `v8.1.7` |
| 2 | `symfony/http-foundation` | PKSA-pr6q-2w5s-kzzf | Path rules enforced in front of PHP can be bypassed by appending path to script name | `>=8.1.0,<8.1.11` | `8.1.11` | `v8.1.7` |
| 3 | `symfony/http-foundation` | PKSA-nxjs-s6hn-vb6m | "private_ranges" trusted proxies shortcut trusts IPv4-mapped, 6to4, Teredo, NAT64 | `>=8.1.0,<8.1.11` | `8.1.11` | `v8.1.7` |
| 4 | `symfony/http-foundation` | PKSA-b2gg-vk1g-6d5q | Generated URLs can point to a host chosen by the client | `>=8.1.0,<8.1.11` | `8.1.11` | `v8.1.7` |
| 5 | `symfony/http-kernel` | PKSA-3gxv-7xhq-mgmz | Inline fragments let untrusted forwarded headers set host/scheme/port of URLs | `>=8.1.0,<8.1.11` | `8.1.11` | `v8.1.7` |
| 6 | `symfony/routing` | CVE-2026-118516 | Host placeholders can match start of path in compiled URL matchers | `>=8.1.0,<8.1.11` | `8.1.11` | `v8.1.6` |
| 7 | `symfony/serializer` | CVE-2026-118515 | Serializer builds UploadedFile objects from request data | `>=8.1.0,<8.1.11` | `8.1.11` | `v8.1.7` |
| 8 | `symfony/serializer` | PKSA-nyd8-k74r-fsf2 | DataUriNormalizer opens data URIs with non-lowercase scheme as local files | `>=8.1.0,<8.1.11` | `8.1.11` | `v8.1.7` |
| 9 | `symfony/serializer` | PKSA-y18k-qjc6-knrv | ObjectNormalizer dotted-key property-path traversal bypasses #[Ignore] | `>=8.1.0,<8.1.11` | `8.1.11` | `v8.1.7` |
| 10 | `symfony/yaml` | PKSA-nktx-f1n1-b4yd | Exponential and quadratic parse times in the YAML parser | `>=8.1.0,<8.1.11` | `8.1.11` | `v8.1.8` |

---

## 3. Exposure & Source Usage Assessment

A comprehensive static audit was performed across `app/`, `config/`, `routes/`, and `bootstrap/app.php` to establish actual runtime exposure:

### 3.1 `symfony/serializer` (CVE-2026-118515, PKSA-nyd8-k74r-fsf2, PKSA-y18k-qjc6-knrv)
- **Dependency Provenance:** `composer.json` -> `laravel/fortify (^1.40)` -> `laravel/passkeys (^0.2.0)` -> `web-auth/webauthn-lib (5.3.x)` -> `symfony/serializer (^6.4|^7.0|^8.0)`.
- **Application Source Usage:** **0 occurrences** in `app/`, `config/`, or `routes/`.
- **Exposure Analysis:** The application never instantiates or configures Symfony normalizers (`DataUriNormalizer`, `ObjectNormalizer`) on user-facing endpoints. Uploaded file processing in this application is handled strictly through Laravel's `Illuminate\Http\UploadedFile` and Livewire's temporary upload mechanism. WebAuthn serialization is strictly scoped to WebAuthn credential DTOs.
- **Verdict:** Unexposed to end-user exploitation; applying patch `v8.1.11` completely removes library vulnerability.

### 3.2 `symfony/yaml` (PKSA-nktx-f1n1-b4yd)
- **Dependency Provenance:** `composer.json` -> `laravel/ai (^1.2)` -> `symfony/yaml (^7.2|^8.0)`.
- **Application Source Usage:** **0 occurrences** in `app/`, `config/`, or `routes/`.
- **Exposure Analysis:** The application exposes zero endpoints accepting arbitrary YAML documents from clients. `laravel/ai` utilizes YAML internally for developer-authored prompts/schemas. There is no vector for untrusted ReDoS or algorithmic complexity exhaustion via client-supplied YAML.
- **Verdict:** Unexposed; upgrading to `v8.1.11` eliminates the parsing flaw safely.

### 3.3 `symfony/http-foundation` (CVE-2026-118514, PKSA-pr6q-2w5s-kzzf, PKSA-nxjs-s6hn-vb6m, PKSA-b2gg-vk1g-6d5q)
- **Dependency Provenance:** `laravel/framework (^7.4.0 || ^8.0.0)`, `fruitcake/php-cors (^5.4|^6.4|^7.3|^8)`.
- **Exposure Analysis:**
  - *HEAD Bypass (CVE-2026-118514):* Affects Symfony Security bundle firewalls and `security.access_control` method rules. This application uses Laravel routing middleware (`EnsureCompanyContext`, `Phase11ApiBoundary`, `EnsurePlatformAccess`), Livewire lifecycle hooks, and Spatie permission gates. In Laravel, HEAD requests map to GET route declarations and execute identical middleware pipelines regardless of method.
  - *Path Rules in front of PHP (PKSA-pr6q-2w5s-kzzf):* The Apache/LiteSpeed `.htaccess` rewrites all non-file requests to the front controller `index.php [L]`. No front-of-PHP path rules exist that could be bypassed by `/index.php/path`.
  - *Trusted Proxies Shortcut (PKSA-nxjs-s6hn-vb6m):* [bootstrap/app.php](file:///home/t3dev/.t3/projects/small-trader-platform/account/bootstrap/app.php) does not invoke `$middleware->trustProxies(at: 'private_ranges')` or enable the vulnerable shortcut.
  - *Host Header URL Generation (PKSA-b2gg-vk1g-6d5q):* Canonical URLs in emails, exports, and public share links are anchored to `config('app.url')`.
- **Verdict:** Low operational risk; resolved cleanly by upgrading to `v8.1.11`.

### 3.4 `symfony/http-kernel` (PKSA-3gxv-7xhq-mgmz)
- **Dependency Provenance:** `laravel/framework (^7.4.0 || ^8.0.0)`, `livewire/livewire (^6.2|^7.0|^8.0)`.
- **Exposure Analysis:** This advisory specifically exploits Symfony HttpKernel's inline fragment renderer (`FragmentListener`, ESI/HInclude sub-requests). Laravel does not use Symfony inline fragment rendering; all templates and components are rendered directly via Blade and Livewire in-process.
- **Verdict:** Unexposed; resolved by `v8.1.11`.

### 3.5 `symfony/routing` (CVE-2026-118516)
- **Dependency Provenance:** `laravel/framework (^7.4.0 || ^8.0.0)`.
- **Exposure Analysis:** The vulnerability requires compiled route matching with host placeholders (e.g. `Route::domain('{subdomain}.example.com')`). An exhaustive grep across `routes/` confirms **zero domain routes or host placeholders**. Multi-tenancy in this application is resolved via session state and company context headers, not subdomain routing.
- **Verdict:** Unexposed; resolved by `v8.1.11`.

---

## 4. Minimal Patched Dependency Resolution

Rather than executing a wide update across all 33 installed Symfony packages (which would unnecessarily perturb unvetted packages like `symfony/console`, `symfony/string`, `symfony/mailer`, etc.), we evaluated the minimal targeted patch update.

### 4.1 Dry-Run Execution Command
```bash
composer update symfony/http-foundation symfony/http-kernel symfony/routing symfony/serializer symfony/yaml --dry-run --no-scripts --no-install -v
```

### 4.2 Dry-Run Result & Package Old/New Diff
The dry run completed in 0.001 seconds with exit code 0. Full log captured in [agy-security-composer-update-dry-run.log](file:///home/t3dev/.t3/projects/small-trader-platform/account/docs/evidence/release-candidate-20261107/agy-security-composer-update-dry-run.log):

```text
Lock file operations: 0 installs, 5 updates, 0 removals
Updates: symfony/http-foundation:v8.1.11, symfony/routing:v8.1.11, symfony/http-kernel:v8.1.11, symfony/yaml:v8.1.11, symfony/serializer:v8.1.11
  - Upgrading symfony/http-foundation (v8.1.7 => v8.1.11)
  - Upgrading symfony/http-kernel (v8.1.7 => v8.1.11)
  - Upgrading symfony/routing (v8.1.6 => v8.1.11)
  - Upgrading symfony/serializer (v8.1.7 => v8.1.11)
  - Upgrading symfony/yaml (v8.1.8 => v8.1.11)
No security vulnerability advisories found.
```

### 4.3 Package Comparison & Diff Table

| Package Name | Old Version | New Version | Release Type | Parent Constraint | Compatibility Status |
|---|---|---|---|---|---|
| `symfony/http-foundation` | `v8.1.7` (`d8fdc670ed`) | `v8.1.11` (`30fa3efe6e`) | Security Patch | `laravel/framework: ^7.4.0 \|\| ^8.0.0` | **PASS (Clean)** |
| `symfony/http-kernel` | `v8.1.7` (`ec7a3a5832`) | `v8.1.11` (`460658504e`) | Security Patch | `laravel/framework: ^7.4.0 \|\| ^8.0.0` | **PASS (Clean)** |
| `symfony/routing` | `v8.1.6` (`3c188091b6`) | `v8.1.11` (`f3fefc6cad`) | Security Patch | `laravel/framework: ^7.4.0 \|\| ^8.0.0` | **PASS (Clean)** |
| `symfony/serializer` | `v8.1.7` (`9a88015b4b`) | `v8.1.11` (`05624c9450`) | Security Patch | `web-auth/webauthn-lib: ^6.4\|^7.0\|^8.0` | **PASS (Clean)** |
| `symfony/yaml` | `v8.1.8` (`030e96eed0`) | `v8.1.11` (`e8266f5516`) | Security Patch | `laravel/ai: ^7.2\|^8.0` | **PASS (Clean)** |

- **Total Packages Altered:** Exactly 5
- **Transitive Knock-on Updates:** 0
- **Removals:** 0
- **Advisories Remaining After Patch:** **0 (Zero)**

---

## 5. Coordination & Execution Plan for Parent & Huda

Because Huda's disposable MariaDB database runtime is active or completing regression suites in parallel, `composer.lock` and `vendor/` must not be mutated during Huda's execution.

### Execution Procedure for Parent:
1. **Pre-condition:** Verify Huda database tests have finished and disposable schema/server teardown is complete (`huda-regression-final.md`).
2. **Execute Targeted Update:** Run the verified safe update command:
   ```bash
   composer update symfony/http-foundation symfony/http-kernel symfony/routing symfony/serializer symfony/yaml --no-scripts
   ```
3. **Verify Zero Advisories:** Run Composer audit to confirm clean status:
   ```bash
   composer audit --format=plain
   ```
4. **Sanity Verification:** Execute static verification gates to re-confirm zero regressions:
   ```bash
   vendor/bin/pint --test
   php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress
   php artisan view:cache
   ```
5. **Lockfile Commit Parity:** Include the updated `composer.lock` with the candidate release diff.

---

## 6. Evidence Logs Reference

The following evidentiary logs are preserved in [docs/evidence/release-candidate-20261107/](file:///home/t3dev/.t3/projects/small-trader-platform/account/docs/evidence/release-candidate-20261107/):
- [agy-build-composer-audit.log](file:///home/t3dev/.t3/projects/small-trader-platform/account/docs/evidence/release-candidate-20261107/agy-build-composer-audit.log): Baseline `composer audit` capturing the 10 upstream advisories.
- [agy-security-composer-update-dry-run.log](file:///home/t3dev/.t3/projects/small-trader-platform/account/docs/evidence/release-candidate-20261107/agy-security-composer-update-dry-run.log): Verbose dry-run resolution confirming 5 clean updates and zero remaining advisories.
- [agy-security-package-diff.log](file:///home/t3dev/.t3/projects/small-trader-platform/account/docs/evidence/release-candidate-20261107/agy-security-package-diff.log): Detailed breakdown of package versions, git references, constraints, and mapped CVEs.
- [agy-build-audit.md](file:///home/t3dev/.t3/projects/small-trader-platform/account/docs/evidence/release-candidate-20261107/agy-build-audit.md): Main release candidate build and static readiness audit report.
