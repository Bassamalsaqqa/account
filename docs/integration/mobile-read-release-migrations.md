# Mobile read API migration boundary

This release installs only four new mobile tables through `2026_11_17_000001_create_mobile_read_api_tables.php`; it never alters `products` or canonical barcodes/tax storage. Offline authority remains disabled and limits unset.

The two November 11 pilot migrations remain byte-for-byte archived in `database/legacy/mobile-v1/`, outside automatic migration discovery. They were not deployed on the accepted web baseline. Do not rerun them or overwrite historical migration records. An environment with existing mobile tables must stop and undergo explicit data/schema reconciliation; the new migration deliberately refuses partial or legacy installations before creating any table.

Production qualification must prove the four tables are absent before installation, preserve every existing business table/row, and apply only this exact new migration. Rollback testing runs only on nonce-owned disposable MariaDB. Never roll back production business data to test a release.
