# Disposable V1 HTTPS pilot (local only)

Composition foundation: `f5d16b356ed9c4d895e4fd07dcb469d756936d9b`.
Its normal merge parents preserve exact PR88 `9f73ca8952da6bbfc0eabadbb01e51892ace8bc6`,
PR89 `cb97c9a7b864b3a40e5dc4fe03c9f168fd5c59e8`, PR91 `f3836ef7aa8a2437e95fedaec3166399a44158b2`,
PR92 `7b8531bfb932c73c26efee7f1ace6c5ae61fceaf`, PR94 `7bb6d779e52ca2f284045d66f8f3f6aee7b4e6fb`,
PR93 `1b8a696feda350bbc2df86a26d05b69fd615ba57`, PR87 `912aa71f557b314837e70138df22615902fc9d7b`.
The subsequent harness commit is the executable candidate pin; the handoff must match HEAD.
No source was pushed and no PR/main branch was merged remotely.

Use `php tests/Support/Pilot/pilot-runner.php start /absolute/new-owned-private-directory`.
Never reuse an existing state directory. Prerequisites are locked Composer dependencies,
PHP curl/PDO MySQL, Python 3, OpenSSL, Caddy, and the already provisioned restricted
loopback MariaDB test runner on port 3342. There is no fallback to a persistent DB.
Generated private files belong outside the checkout, credentials mode 0600, directory 0700.
The pilot uses production V1 routes and bindings, temporary synthetic staff and two tenants,
and the production financial deny adapter. V1 is enabled only in its process environment;
V2 remains off. Financial/offline activation booleans describe denied capabilities, not
invented Laravel configuration keys. Actual native flag values are probed from Laravel.

`python3 tests/Support/Pilot/verify-pilot.py /absolute/owned-directory` checks credential
permissions, expiry, production container denial for execute/replay/lookup, actual resolved
native flags, a valid HTTPS POST challenge, specific certificate verification failures,
and a separate default-OFF Laravel instance over HTTPS. It does not change the reader instance.
The TLS negative endpoints must fail certificate verification; connection refusal is a failure.
Only loopback interfaces are allowed. No system trust-store changes are made.

`php tests/Support/Pilot/pilot-runner.php test /absolute/owned-directory` executes the
read smoke scenarios. MFA recovery credentials are SINGLE USE: this runner currently uses
index 2 and deliberately fails on a second attempt after consumption. A newly seeded fixture
is necessary for a fully repeatable MFA run. Never reset an existing reader's fixtures.
The current paired fixture has recovery indices 0 and 2 consumed; index 1 remains for the
reader. No secrets or raw authentication response bodies should be logged.

The expiry process stops only matching PID/start-time identities at the recorded TTL;
it leaves the database and private artifacts intact pending the paired runner acknowledgment.
After that acknowledgment, use the guarded stop command with `--drop-schema` only for this
owned schema. Preserve private artifacts until the owner authorizes their retention cleanup.
Never kill by port, overwrite a pilot directory, or drop schemas selected merely by prefix.

Supervisor verification: corrected smoke executed 13 scenarios successfully, including real
HTTP challenge/login/fresh-challenge MFA/bootstrap/two-page products and customers/empty search,
missing and invalid token/revoked-device login/foreign tenant and restricted permission statuses.
The auth scenario does NOT prove password-epoch rotation or revocation of an already issued token.
The foreign-tenant scenario does NOT prove missing-tenant response indistinguishability.
Additional independent boundary script verified default-OFF HTTPS 404, SAN mismatch code 64,
untrusted-root code 18, and production deny binding. Pint and scoped harness PHPStan passed.
The writer reported currency 63/382, selection 37/339, pure contract 115/513, Python rev2/rev3
normal and optimized, frontend build and 24 Node tests; these are worker evidence, not fresh
supervisor executions. Application PHPStan does not establish receipt test type cleanliness.

Release holds remain: PR94 receipt lock inversion/scope messages/typed-result/context-binding
and six scoped test-analysis findings; no new receipt runtime qualification. Receipt plaintext
retention, Android plaintext outbox and backup/device-transfer exclusion, physical-device
Keystore/UI/instrumentation, financial grants, lost ACK/stock conflict/provisional receipt/
AUTH_BLOCKED business acceptance all remain gated. PR88 catalog version hashing is still O(n).
No financial capture, execution, sync, production deployment, or owner acceptance follows from
this read-only pilot.
