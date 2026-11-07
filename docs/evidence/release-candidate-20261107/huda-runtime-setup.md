# Huda user-space disposable MariaDB setup — 2026-11-07

Runtime: unprivileged uid 1001 (`t3dev`), x86_64, PHP 8.4.27, MariaDB 11.8.8 from Debian. Owned scratch directory in this run: `/tmp/huda-runtime-9dx5swDe`. No sudo, privilege changes, global installation, service registration, Docker, application `.env`, persistent port 3353, or production access.

Read repository AGENTS.md, SETUP_AND_OPERATIONS_GUIDE section 4, and previous huda-regression.md. HEAD at start: `06e4f6d71b8dc3202f0708dff91a21b32a5afd33`. Existing dirty files preserved.

## Reproduce provisioning

Run from a new owned temporary directory (record its path). Download official Debian packages over HTTPS, then extract, never install them globally:

```bash
runtime=$(mktemp -d /tmp/huda-runtime-XXXXXXXX)
cd "$runtime"
base=https://deb.debian.org/debian/pool/main/m/mariadb
curl -fLsS "$base/mariadb-server-core_11.8.8-0+deb13u1_amd64.deb" -o server.deb
curl -fLsS "$base/mariadb-client-core_11.8.8-0+deb13u1_amd64.deb" -o client.deb
curl -fLsS "$base/mariadb-server_11.8.8-0+deb13u1_amd64.deb" -o server-extra.deb
sha256sum server.deb client.deb server-extra.deb
dpkg-deb -x server.deb root
dpkg-deb -x client.deb root
dpkg-deb -x server-extra.deb root
ldd root/usr/sbin/mariadbd
root/usr/bin/mariadb-install-db --no-defaults --basedir="$runtime/root/usr" --datadir="$runtime/data" --auth-root-authentication-method=normal --skip-test-db
root/usr/sbin/mariadbd --no-defaults --basedir="$runtime/root/usr" --datadir="$runtime/data" --socket="$runtime/server.sock" --pid-file="$runtime/server.pid" --port=3342 --bind-address=127.0.0.1 --skip-name-resolve --log-error="$runtime/server.log" --innodb-buffer-pool-size=256M
```

Run server in a separate managed terminal/process, without escalation. All required shared libraries resolved in this runtime. Bootstrap reported host-name lookup warning; numeric loopback and `--skip-name-resolve` avoid hostname dependence. It successfully initialized system tables and accepted normal socket/TCP connections. No unsandboxed fallback was needed. Server uses only its owned data directory. Root has an intentionally empty password in this disposable instance; no application credentials are used. Restrict bootstrap accounts immediately through its private Unix socket:

```bash
"$runtime/root/usr/bin/mariadb" --no-defaults --socket="$runtime/server.sock" -u root <<'SQL'
DELETE FROM mysql.global_priv WHERE User = '' OR (User = 'root' AND Host NOT IN ('localhost','127.0.0.1','::1'));
CREATE USER 'test_runner'@'127.0.0.1' IDENTIFIED BY '';
GRANT ALL PRIVILEGES ON `accounting\_p8\_tmp\_%`.* TO 'test_runner'@'127.0.0.1';
FLUSH PRIVILEGES;
SELECT VERSION();
SHOW GRANTS FOR 'test_runner'@'127.0.0.1';
SQL
```

Privileges are limited to escaped literal prefix `accounting_p8_tmp_` followed by a wildcard; no global administrative grant. Packages SHA256:

- server.deb: `30b7bbcab4fc4e99e74d934d3d4d25e2a54d0e6f7cd48108a532c87c2ff428b1`
- client.deb: `33aaef5e8d1b09070474a5f1f651c499fe12f9d5aa53395d71bbbd5dd2c37a61`
- server-extra.deb: `7f495ce61075de37f5a14bdd224e40048f1dabbad45ac1d4684621d0fc3fb78d`

## Reproduce tests

From the account repository, set explicit test-only environment for each invocation:

```bash
export APP_ENV=testing PHASE8_ALLOW_DISPOSABLE_DB=1
export PHASE8_TEST_DB_HOST=127.0.0.1 PHASE8_TEST_DB_PORT=3342
export PHASE8_TEST_DB_USERNAME=test_runner PHASE8_TEST_DB_EMPTY_PASSWORD=1
export PHASE8_TEST_TIMEOUT=14400
php tests/Support/run-phase8-disposable.php -- tests/Feature/Purchasing/VoidPurchaseTest.php tests/Feature/Purchasing/VoidPurchaseReturnTest.php tests/Feature/Purchasing/PurchaseCorrectionTest.php tests/Feature/Phase5E/VendorPaymentReconciliationAndAuditTest.php
php tests/Support/run-phase8-disposable.php -- tests/Feature/Purchasing/ReleaseReconciliationTest.php
php tests/Support/run-phase8-disposable.php -- tests
```

Each runner creates its own randomly named schema, migrates it, supplies a private ownership proof to PHPUnit/children, and drops only that owned schema in finally. No ownership proof, secret, private application record, or app credential is included in evidence. Schema names are disposable metadata. ANSI color codes may be removed from logs without changing content.

## Preserved runtime handoff and eventual teardown

The parent requested that this isolated server remain running for the subsequent Symfony 8.1.11 dependency qualification. Do not remove its directory or stop it at this handoff. Live runtime path: `/tmp/huda-runtime-9dx5swDe`; port 3342; Unix socket `server.sock`; PID file `server.pid`; error log `server.log`. The server is independent of PHP/vendor. Verify the PID command line refers to this directory before sending a signal. If it has exited, restart using the exact mariadbd command above, substituting this preserved path; do not reinitialize the existing data directory. No more PHP test processes will be started by Huda after the in-flight full suite.


After all runners finish, inspect schemas on the owned server only and record whether runner cleanup completed. Do not adopt/drop another worker's schema. Stop this exact owned server with `kill -TERM "$(cat "$runtime/server.pid")"`, wait for its managed process to exit, then remove only the recorded owned scratch directory. Do not kill by process name or port and do not touch persistent database data. Final teardown outcome is recorded in huda-regression-final.md.
