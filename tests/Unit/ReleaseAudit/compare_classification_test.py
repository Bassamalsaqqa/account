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

    def compare(self, name, classification, *, after_classification=None, accept=False, missing=False):
        before = dict(rows=1, schema_sha256="a" * 64, normalized_schema_sha256="a" * 64,
                      rows_sha256="a" * 64, classification=classification)
        after = dict(before, rows=2, rows_sha256="b" * 64,
                     classification=after_classification or classification)
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
        self.assertEqual(0, self.compare("sessions", "operational_mutable", accept=True).returncode)

    def test_missing_required_fingerprint_is_refused(self):
        self.assertEqual(1, self.compare("posting_lines", "preservation", missing=True).returncode)


if __name__ == "__main__":
    unittest.main()
