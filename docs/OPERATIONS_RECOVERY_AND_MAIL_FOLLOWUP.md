# Separate recovery and email verification work

Status as of 2026-10-15: **not verified**. These items are separate from the daily business workflow development release. No production access, recovery execution, email transmission or new deployment is authorized by this source-development assignment. Retain the completed V2.3 release evidence privately; do not publish backup locations, keys, credentials, personal identifiers or financial contents.

| Tracking ID | Work | Completion evidence | Prerequisites and current status |
| --- | --- | --- | --- |
| OPS-RECOVERY-01 | Coherent independent off-host custody | Encrypted DB/application/private uploads/configuration/code/assets transferred to independent storage; integrity verified; application and backup keys recoverable through independent custody | Approved destination and key custody. Same-host rollback archives do not satisfy this requirement. NOT VERIFIED. |
| OPS-RESTORE-02 | Isolated restoration, both historical-upgrade and current-coherent lanes | Actual isolated restoration, application login/decryption, private attachments, matching assets, six canonical reconciliations, balances and statements, tenant/permission checks, measured RPO/RTO and cleanup receipt | Approved isolated environment; production credentials must never activate in restored code. SQL import, decryption or archive listing alone is insufficient. NOT VERIFIED. |
| OPS-MAIL-03 | Real password-reset email delivery and recovery | Approved controlled account requests reset, receives real email, follows expiring single-use link, changes password and logs in; token reuse/expiry verified without exposing tokens in reports | Approved recipient/account, outbound provider and real transmission authorization. Mock/array-mail tests do not prove delivery. NOT VERIFIED. |

Automatic reconciliation scheduling, actual Palestinian Arabic microphone quality, renewed live DeepSeek evaluation and historical public-documentation privacy governance also remain independently tracked concerns. This release does not resolve or obscure them.
