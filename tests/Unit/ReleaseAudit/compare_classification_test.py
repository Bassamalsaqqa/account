"""Pure CLI regressions: no Laravel bootstrap, database or dependency installation."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[3]


class ClassificationTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix="audit-classification-")
        self.addCleanup(self.directory.cleanup)
        self.pin = subprocess.check_output([
            "php", "-r", "require $argv[1]; echo \\ReleaseAudit\\Guard::implementationDigest();",
            str(ROOT / "tools/release-audit/Guard.php"),
        ], text=True).strip()

    def compare(self, name, classification, *, after_classification=None, accept=False, missing=False, auto_increment=None, after_auto_increment=None):
        before = dict(rows=1, schema_sha256="a" * 64, normalized_schema_sha256="a" * 64,
                      rows_sha256="a" * 64, classification=classification)
        if auto_increment is not None:
            before["auto_increment"] = auto_increment
        after = dict(before, rows=2, rows_sha256="b" * 64,
                     classification=after_classification or classification)
        if after_auto_increment is not None:
            after["auto_increment"] = after_auto_increment
        if missing:
            del after["normalized_schema_sha256"]
        paths = []
        for filename, row in [("before.json", before), ("after.json", after)]:
            path = Path(self.directory.name) / filename
            path.write_text(json.dumps(dict(version=1, implementation_sha256=self.pin, tables={name: row})))
            os.chmod(path, 0o600)
            paths.append(str(path))
        args = ["php", str(ROOT / "tools/release-audit/compare.php"), *paths, self.pin]
        if accept:
            args.append("--accept-operational-volatility")
        return subprocess.run(args, capture_output=True, text=True, timeout=10)

    def test_forged_business_classification_cannot_waive_financial_change(self):
        result = self.compare("posting_lines", "operational_mutable", accept=True)
        self.assertEqual(1, result.returncode)
        self.assertEqual("AUDIT_REFUSED\n", result.stderr)

    def test_inconsistent_classification_is_refused(self):
        self.assertEqual(1, self.compare("sessions", "operational_mutable", after_classification="preservation").returncode)

    def test_business_changes_remain_differences_with_operational_flag(self):
        self.assertEqual(2, self.compare("posting_lines", "preservation", accept=True).returncode)

    def test_operational_changes_require_explicit_disposition(self):
        self.assertEqual(3, self.compare("sessions", "operational_mutable").returncode)

    def test_explicit_disposition_can_accept_only_operational_changes(self):
        result = self.compare("sessions", "operational_mutable", accept=True)
        self.assertEqual(0, result.returncode)
        self.assertIn("Operational churn accepted", result.stderr)
        self.assertIn("comparison disposition only; it is NOT a release approval", result.stderr)

    def test_missing_required_fingerprint_is_refused(self):
        self.assertEqual(1, self.compare("posting_lines", "preservation", missing=True).returncode)

    def test_large_counter_exact_string_above_php_int_max_accepted_and_compared(self):
        large_counter = "18446744073709551615"
        result = self.compare("sessions", "operational_mutable", auto_increment=large_counter, accept=True)
        self.assertEqual(0, result.returncode)

    def test_invalid_counter_type_is_refused(self):
        result = self.compare("sessions", "operational_mutable", auto_increment="invalid_counter_nan", accept=True)
        self.assertEqual(1, result.returncode)
        self.assertEqual("AUDIT_REFUSED\n", result.stderr)

    def test_ddl_normalization_preserves_comments_and_defaults_with_auto_increment(self):
        php_script = (
            "require $argv[1]; require $argv[2];\n"
            "$ddl = \"CREATE TABLE `ledger` (\\n\" .\n"
            "       \"  `id` bigint(20) unsigned NOT NULL,\\n\" .\n"
            "       \"  `desc` varchar(255) DEFAULT 'AUTO_INCREMENT=999' COMMENT 'AUTO_INCREMENT=100',\\n\" .\n"
            "       \"  PRIMARY KEY (`id`)\\n\" .\n"
            "       \") ENGINE=InnoDB AUTO_INCREMENT=18446744073709551615 DEFAULT CHARSET=utf8mb4 COMMENT='tbl with AUTO_INCREMENT=42 inside';\";\n"
            "$info = \\ReleaseAudit\\Fingerprint::normalizeDdl($ddl);\n"
            "echo json_encode($info);\n"
        )
        proc = subprocess.run([
            "php", "-r", php_script,
            str(ROOT / "tools/release-audit/Guard.php"),
            str(ROOT / "tools/release-audit/Fingerprint.php"),
        ], capture_output=True, text=True, check=True)
        data = json.loads(proc.stdout)
        self.assertEqual("18446744073709551615", data["auto_increment"])
        self.assertIsInstance(data["normalized_schema_sha256"], str)
        self.assertEqual(64, len(data["normalized_schema_sha256"]))

    def test_ddl_normalization_with_nested_parentheses_and_escaped_quotes_in_comments(self):
        php_script = (
            "require $argv[1]; require $argv[2];\n"
            "$ddl = \"CREATE TABLE `complex_tbl` (\\n\" .\n"
            "       \"  `id` bigint(20) unsigned NOT NULL,\\n\" .\n"
            "       \"  `formula` varchar(255) DEFAULT '(a + b) * (c + d)' COMMENT 'nested (parens) with \\'quoted\\' text',\\n\" .\n"
            "       \"  PRIMARY KEY (`id`)\\n\" .\n"
            "       \") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='table (comment) with \\'escaped quotes\\' inside' AUTO_INCREMENT=9876543210123456789;\";\n"
            "$info = \\ReleaseAudit\\Fingerprint::normalizeDdl($ddl);\n"
            "echo json_encode($info);\n"
        )
        proc = subprocess.run([
            "php", "-r", php_script,
            str(ROOT / "tools/release-audit/Guard.php"),
            str(ROOT / "tools/release-audit/Fingerprint.php"),
        ], capture_output=True, text=True, check=True)
        data = json.loads(proc.stdout)
        self.assertEqual("9876543210123456789", data["auto_increment"])
        self.assertIsInstance(data["normalized_schema_sha256"], str)
        self.assertEqual(64, len(data["normalized_schema_sha256"]))

    def test_budget_cumulative_tracking_and_refusal_across_phases(self):
        php_script = (
            "require $argv[1]; require $argv[2];\n"
            "$budget = new \\ReleaseAudit\\Budget(['max_total_rows' => 500, 'max_execution_seconds' => 60]);\n"
            "for ($i = 0; $i < 300; $i++) { $budget->recordRow(10); }\n"
            "assert($budget->totalRows() === 300);\n"
            "$budget->assertWithinLimits();\n"
            "try {\n"
            "    for ($i = 0; $i < 300; $i++) { $budget->recordRow(10); }\n"
            "    echo 'FAILED';\n"
            "} catch (\\RuntimeException $e) {\n"
            "    echo $e->getMessage();\n"
            "}\n"
        )
        proc = subprocess.run([
            "php", "-r", php_script,
            str(ROOT / "tools/release-audit/Guard.php"),
            str(ROOT / "tools/release-audit/Fingerprint.php"),
        ], capture_output=True, text=True, check=True)
        self.assertEqual("AUDIT_REFUSED", proc.stdout)

    def test_statement_timeout_unsupported_refusal(self):
        php_script = (
            "require $argv[1]; require $argv[2];\n"
            "$pdo = new PDO('sqlite::memory:');\n"
            "try {\n"
            "    \\ReleaseAudit\\Fingerprint::configureStatementTimeout($pdo, 30);\n"
            "    echo 'FAILED';\n"
            "} catch (\\RuntimeException $e) {\n"
            "    echo $e->getMessage();\n"
            "}\n"
        )
        proc = subprocess.run([
            "php", "-r", php_script,
            str(ROOT / "tools/release-audit/Guard.php"),
            str(ROOT / "tools/release-audit/Fingerprint.php"),
        ], capture_output=True, text=True, check=True)
        self.assertEqual("AUDIT_REFUSED", proc.stdout)

    def test_expired_budget_deadline_refuses_immediately(self):
        php_script = (
            "require $argv[1]; require $argv[2];\n"
            "$budget = new \\ReleaseAudit\\Budget(['max_execution_seconds' => 1]);\n"
            "usleep(1100000);\n"
            "try {\n"
            "    $budget->remainingExecutionSeconds();\n"
            "    echo 'FAILED';\n"
            "} catch (\\RuntimeException $e) {\n"
            "    echo $e->getMessage();\n"
            "}\n"
        )
        proc = subprocess.run([
            "php", "-r", php_script,
            str(ROOT / "tools/release-audit/Guard.php"),
            str(ROOT / "tools/release-audit/Fingerprint.php"),
        ], capture_output=True, text=True, check=True)
        self.assertEqual("AUDIT_REFUSED", proc.stdout)

    def test_cell_byte_limit_refuses_oversized_numeric_check_before_payload_query(self):
        php_script = (
            "require $argv[1]; require $argv[2];\n"
            "$budget = new \\ReleaseAudit\\Budget(['max_memory_bytes' => 67108864]);\n"
            "try {\n"
            "    $budget->checkCellBytes('20000000');\n"
            "    echo 'FAILED';\n"
            "} catch (\\RuntimeException $e) {\n"
            "    echo $e->getMessage();\n"
            "}\n"
        )
        proc = subprocess.run([
            "php", "-r", php_script,
            str(ROOT / "tools/release-audit/Guard.php"),
            str(ROOT / "tools/release-audit/Fingerprint.php"),
        ], capture_output=True, text=True, check=True)
        self.assertEqual("AUDIT_REFUSED", proc.stdout)

    def test_multiquery_service_deadline_decrements_and_refuses_on_expiration(self):
        php_script = (
            "require $argv[1]; require $argv[2];\n"
            "$budget = new \\ReleaseAudit\\Budget(['max_execution_seconds' => 2]);\n"
            "$d1 = $budget->remainingExecutionSeconds();\n"
            "assert($d1 <= 2 && $d1 >= 1);\n"
            "usleep(500000);\n"
            "$budget->assertWithinLimits();\n"
            "usleep(1600000);\n"
            "try {\n"
            "    $budget->remainingExecutionSeconds();\n"
            "    echo 'FAILED';\n"
            "} catch (\\RuntimeException $e) {\n"
            "    echo $e->getMessage();\n"
            "}\n"
        )
        proc = subprocess.run([
            "php", "-r", php_script,
            str(ROOT / "tools/release-audit/Guard.php"),
            str(ROOT / "tools/release-audit/Fingerprint.php"),
        ], capture_output=True, text=True, check=True)
        self.assertEqual("AUDIT_REFUSED", proc.stdout)


if __name__ == "__main__":
    unittest.main()
