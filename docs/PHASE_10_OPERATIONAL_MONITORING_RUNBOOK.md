# Phase 10 operational monitoring and incident runbook

Source implementation guidance, not a claim that hosting controls are deployed. Accepted main: c74c9a4e0b4135055dbb8ed7d3e3715d2ddba24f. Production runtime remains 8d8428261cd2a10690ab77a5c7271e46b5ff5217. Hostinger access, real email, backup/key retrieval and all production changes require separate authorization.

## Readiness and monitoring evidence

The three P1 gates remain open: verified isolated historical/current restoration; approved working password/account recovery delivery; independent off-host ciphertext and key recovery. See [recovery runbook](PHASE_10_BACKUP_RESTORE_RUNBOOK.md). Source tests and fake mail do not close operational gates. Customer readiness NOT YET ESTABLISHED.

| Signal | Required observation and action |
|---|---|
| Backup freshness | Use captured_at of the latest COMPLETE independently recoverable off-host point; a fresh transfer cannot refresh old source data. Proposed >24h warning, >48h critical, subject to Owner adoption; these are not an accepted SLA. Missing point/custody/key recovery is NOT VERIFIED, not healthy. |
| Integrity | Compare ciphertext size/hash against independently trusted manifest. Failure blocks use; preserve original ciphertext and protected failure evidence, then authorize a new coherent backup. |
| Delivery | Fake/local mail proves code only. Approved environment/recipient, provider configuration and real reset expiry/replay proof remain BLOCKED EXTERNAL AUTHORIZATION. |
| Availability | /up is Laravel's lightweight bootstrap health route. It does not certify DB/storage, financial reconciliation, SMTP, resource capacity or customer readiness. Verify installed framework behavior before assuming maintenance/error status semantics. |
| Capacity | Measure actual PHP/runtime/provider version, memory/time limits, quota, process/concurrency and observed failures separately. Configured limits are not capacity guarantees. No production load testing authorized. |
| Application errors | Observe sanitized error rate and source-linked failure categories. Never attach raw credentials, reset/share tokens, document bodies, balances, salaries or private customer/vendor details to logs or monitoring alerts. |

The repository routes/console.php currently registers no scheduled tasks. Provider cron/automation state is NOT VERIFIED by repository inspection. Backup generation/transfer/monitoring schedules are proposals until separately implemented, configured and observed. No invented backup script or cron should be reported as deployed. Shared-hosting operation must not require Redis, Docker, permanent Node, Supervisor or WebSockets.

## Logging, permissions and custody

config/logging.php supports single/daily channels, configurable LOG_CHANNEL/LOG_STACK/LOG_LEVEL and daily retention; defaults do not prove actual production rotation or severity. Under authorized configuration review, require APP_DEBUG=false, suitable restricted log rotation/retention, sanitizer checks and controlled operator-only alert recipients. MAIL_MAILER=log can disclose password-reset links into logs: use fake/array mail locally and separately approved real transport operationally. Never log application keys, DB/mail credentials or backup decryption secrets.

Preserve the accepted deployment policy: application/public directories0755, files0644, never777. Restricted recovery scratch, temporary secrets and backup custody require exclusive0700/0600 or verified equivalent ACLs within their separate controlled boundary. Any tighter production-private storage policy needs deployment compatibility review and explicit operational authorization. Never blanket chmod storage recursively or expose private files in public root. No production permission changes are authorized by this document.

## Bounded incident response

| Incident | Authorized operator response; stop conditions |
|---|---|
| Backup/transfer failure or stale point | Inspect restricted sanitized job results, actual storage quota, lock/overlap and transfer integrity. Validate source capture and off-host custody independently. Preserve last good point; request authorization for rerun/provider changes. |
| PHP handler/source exposure | Restrict ingress using approved incident procedure; verify PHP8.4 handler, public-root separation and actual enabled extensions against deployment guide. Do not leave raw source publicly served. |
| Missing vendor/build assets | Compare exact release/lock/build digests. Use approved deployment/rollback procedure, maintenance and fail-closed provisioning. Do not claim composer install is atomic or rerun deploy without authorization. |
| Cached environment or permission error | Verify effective destination/configuration before Artisan. Inspect actual cache paths and permissions using trusted code; clear/rebuild only under approved maintenance scope. Do not activate an archived .env. |
| DB outage/connection exhaustion | Inspect sanitized service/process/lock observations under approved access. Do not terminate sessions indiscriminately or mutate economic data. Escalate unresolved locks/capacity with evidence. |
| Encryption-key loss/rotation | Stop affected operations and preserve encrypted records. Recover applicable key history under independent custody; never key:generate over existing encrypted business history. |
| Account recovery failure | Verify approved transport, DNS/provider delivery and local expiry/replay behavior. Use only an approved, identity-verified recovery procedure. This runbook does not invent an admin impersonation or arbitrary password-reset capability. |
| Public link misuse | Inspect bounded sanitized request/rate-limit evidence; identify actual issued grant. Authorized revocation through existing financial-sharing/catalog controls; verify subsequent denial. Cached/downloaded public marketing images cannot be recalled. No default permission widening. |
| Disaster recovery | Owner authorizes isolated target and custody; follow both recovery lanes as applicable, app verification and six reconciliations. DNS cutover/release is a separate authorization and independent acceptance gate. |

Record actual start/finish, source/recovery-point identity, observations, actions and residual risk. Avoid real-data fixtures, speculative vulnerability claims and unobserved PASS states. No operator action in this document has been executed by publishing it.
