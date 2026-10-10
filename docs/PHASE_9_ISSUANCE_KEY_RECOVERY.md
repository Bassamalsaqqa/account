# Phase 9 encrypted issuance — key retention and recovery

This is an operational procedure for a future explicitly authorized release or
recovery. It was not executed against production during Phase 9 engineering.

Financial issuance payloads and recoverable share tokens use Laravel's existing
authenticated encryption. `content_version=1` describes the allowlisted canonical
JSON; `content_hash` is SHA-256 of the exact canonical plaintext. The hash is an
integrity/provenance check, not an encryption key. Stored `MEDIUMTEXT` payloads are
private database content; never place them in public storage or application logs.

Before rotating `APP_KEY`, preserve the current key in secure, separately controlled
key escrow and the next deployment's `APP_PREVIOUS_KEYS`. Previous keys must remain
available while any retained token, issuance, historical encrypted record or backup
requires them. Do not remove a key merely because a link expired: retained encrypted
history and backups still require a deliberate retention policy. Keep the exact
application version, encryption cipher and all required key versions with recovery
metadata, separately from encrypted database/file backups.

During an authorized rotation, configure the new `APP_KEY` and comma-separated
`APP_PREVIOUS_KEYS` through private server configuration. Refresh configuration
only within that authorized release. Confirm that a previously issued fixture
decrypts, verifies its canonical hash, and yields identical currency and receipt
scope; new issuance must use the new key. Never print keys or complete DTOs into
shell logs, CI artifacts, tickets, analytics or exception context.

Backups must be encrypted at rest, access restricted, and include the issuance
columns, grants, approved catalog revisions, audit provenance and required private
files. Preserve matching key escrow separately. A database backup without its
required keys cannot restore financial disclosure. A key without a valid retained
snapshot must not be used to invent historical issuance content.

For recovery, first restore into an owned isolated environment with no outbound
messaging or public access. Restore the matching application code and private
configuration/key history. Verify row counts and immutable hashes, decrypt and
validate representative exact multicurrency DTOs, verify source/Company/grant
validity, and check revoked/expired links remain denied. Restore only existing
issued ciphertext; never generate replacement snapshots from today's live ledger.
Grant activation is not a recovery step. Any production switch requires separate
Owner authorization and the later production restoration procedure.

If a key or content is missing, decryption/integrity fails, or schema/version is
unsupported, all issued financial formats remain unavailable. Obtain approved key
recovery or restore a verified matching backup; do not weaken the disclosure gate,
remove integrity validation or fall back to live Statements. If recovery cannot
restore original issuance, an authorized user can revoke and deliberately issue a
new grant with a fresh preview. Genuine legacy live Statements remain labelled
legacy; no retrospective immutable snapshot is fabricated for them.

Local tests cover prior-key rotation, missing/wrong key denial, stored-ciphertext
preservation and recovery. These scoped tests do not claim that a complete
production backup restoration rehearsal has occurred; that remains Phase 10.
