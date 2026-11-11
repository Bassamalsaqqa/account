# PR88 correction verification

Source baseline: `22f0f60d7e69ce6b1d92a9925a823d7c93156497` (PR86 corrected base `614b1da44ef8715d64a2477e635246f760a3f84c`). Resulting commit/tree are reported in the implementation handoff. No production or persistent database access.

## Actual gates

- Focused mobile, Phase11 API security, profile MFA, secret handling and unit contract suites: **96 tests, 725 assertions**, passed. Includes migration down/up and eight independent processes covering same/different recovery codes across mobile/mobile and mobile/browser consumers, plus rollback on injected issuance failure.
- Browser auth directory + Phase11 WebCommandParityTest: **54 tests, 329 assertions**, passed.
- Final AuthLoginTest + SecurityCorrectionsTest (including new interleaved failure/success, inactive-company and actor/company throttle checks): **15 tests, 92 assertions**, passed. These executions overlap earlier suites; do not add them as unique tests.
- Pint dirty-files check passed; PHPStan whole configured scope passed with no errors; production build passed; composer audit found no advisories.
- Python frozen-contract validator passed **120 checks**, including all **38 manifest files** unchanged. Actual login response also validated with Python Draft202012Validator + FormatChecker in AuthLoginTest.
- Each disposable runner reported primary-schema cleanup; race tests restore/drop their independently owned fixture schema. No fixture transfer, browser session/CSRF bypass, financial activation, six financial reconciliations, merge or deployment. Six reconciliations remain for the later financial slice. Full repository suite was not run.

## Reproduce

Install locked dependencies with `composer install --no-interaction --prefer-dist --optimize-autoloader` and `npm ci`; then `npm run build` (needed for browser view tests). Use a provisioned test-only MariaDB; this run used numeric loopback **3342**, test_runner with deliberately empty password and accounting_p8_tmp_% schema grants. Never use persistent 3353.

All three database invocations used this prefix (the all-zero application key is public, synthetic test setup, never a runtime credential):

```sh
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1 PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342 PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1 php tests/Support/run-phase8-disposable.php -- <targets>
```

Targets, respectively:

```text
tests/Feature/Mobile tests/Feature/Phase11/ApiSecurityTest.php tests/Feature/Auth/ProfileTwoFactorAuthenticationTest.php tests/Feature/RefinementA2/TwoFactorSecretHandlingTest.php tests/Unit/Mobile
tests/Feature/Phase11/WebCommandParityTest.php tests/Feature/Auth
tests/Feature/Mobile/AuthLoginTest.php tests/Feature/Mobile/SecurityCorrectionsTest.php
```

Other commands: `vendor/bin/pint --test --dirty`, `vendor/bin/phpstan analyse --no-progress`, `python tools/validate-mobile-contract.py`, `composer audit --format=plain`, `git diff --check`. Initial failing runs were corrected and are not claimed as passes.

## Retained task artifacts

Final logs are deliberately retained outside the checkout in `/tmp/pr88-security-evidence-25496455/`; they contain only synthetic test context. Intermediate task scripts/logs were removed. Installed ignored vendor, node_modules and public/build are reproducible local runtime artifacts, not source dependencies or tracked changes.

| Log | SHA-256 |
|---|---|
| `mobile-verified-tests.log` | `9435e6977ee1d88287b93da51b635bad3e9ceb47127385281786c139f816d3ea` |
| `mobile-browser-final.log` | `5f1f18ff577848c1331a4e70666a0d2c17297b077e1f097693c8d6de32bbe6e8` |
| `mobile-extra-tests.log` | `2f63402ca83a6f0383efa561f960c1c2cd075758b70393740111c7adac8fa452` |
| `mobile-static-verified.log` | `908573a3f773fc4a9498251bb27289bb17bc5c774b6826695e96258f38fc1e68` |
| `mobile-style-verified.log` | `cd1a94fc2cf6a965b86e1a4809d6c7fb9148b1ee374e1010ed2ac96ff4876ec2` |
| `mobile-build.log` | `b394d93fdd6335f7ff0d353b2d16f46a478584f8bde0cd109c33a573da6a84de` |
| `mobile-contract.log` | `606e199f8443957e850a69175267f707ce59836001f333b2196d12c3cf6dcc84` |
| `mobile-audit.log` | `dac04dda7966b04d2eb83ba22b729e4025f820bba102192effa983b1fa377af8` |
