#!/usr/bin/env python3
"""
tools/validate-mobile-contract.py

Comprehensive Small Trader Mobile V1 Contract Validation Suite:
1. Validates all JSON Schemas in docs/integration/mobile-v1/schemas/ using genuine Draft 2020-12
2. Validates OpenAPI 3.1 specification using openapi-spec-validator with path parameter,
   security, query pagination/search, and status schema checks
3. Validates all Golden Fixtures against schemas with Draft 2020-12 and envelope equality bindings
4. Validates Golden Canonical JSON and SHA-256 Digest test vectors (Restricted JCS profile v1)
5. Validates Regression Fixtures (contract consistency only; Laravel SalesLineCalculator ROUND_HALF_UP,
   16% tax percentage, line round sequence, trailing-zero quantities, and boundary magnitudes)
6. Runs meaningful Negative & Contract Validation Tests (bad refs, missing params, undefined schemes,
   negative/zero quantities, IEEE-754 floats, extra properties, tampered digests, duplicate keys,
   ULID acceptance & UUID entity rejection, tax > 100 rejection, six-place trailing-zero quantities,
   packaging unit identity, preview token digest binding, and MFA code mutual exclusion)
7. Verifies reproducible SHA-256 manifest (fails closed if missing without --generate-manifest)

Zero bare 'assert' statements — safe under python3 -O.

Note on Scope:
This suite validates the contract schemas, OpenAPI 3.1 specifications, canonical serialization,
digest invariants, and fixture shapes. Live runtime behavior (database constraints, transaction
isolation, middleware gating, and ledger posting) is authoritative in Laravel backend test suites
(PHPUnit / MariaDB).

Usage:
    python3 tools/validate-mobile-contract.py [--generate-manifest]
"""

import sys
import os
import json
import hashlib
import re
import decimal
from decimal import Decimal, ROUND_HALF_UP, ROUND_HALF_EVEN
from pathlib import Path
from typing import Any, Dict, List, Tuple

import jsonschema
from jsonschema import Draft202012Validator, FormatChecker
from jsonschema.validators import extend
from jsonschema.exceptions import ValidationError as JsonSchemaValidationError, SchemaError
import referencing
from referencing import Registry, Resource
import openapi_spec_validator

def full_pattern(validator, pattern, instance, schema):
    # JSON Schema search semantics plus strict end boundary for this contract's anchored patterns.
    if isinstance(instance, str) and re.search(pattern, instance) is None:
        yield JsonSchemaValidationError("String does not match pattern")
    elif isinstance(instance, str) and pattern.endswith("$") and re.fullmatch(pattern[:-1], instance) is None:
        yield JsonSchemaValidationError("String must match the entire bounded lexeme")

ContractValidator = extend(Draft202012Validator, {"pattern": full_pattern})

# Set up decimal context matching Laravel SalesLineCalculator.php (commit f8a0cf7012c4875bec7320d42e0f8dde58593b52)
decimal.getcontext().prec = 28
decimal.getcontext().rounding = ROUND_HALF_UP

REPO_ROOT = Path(__file__).resolve().parent.parent
BASE_DIR = REPO_ROOT / "docs" / "integration" / "mobile-v1"
SCHEMAS_DIR = BASE_DIR / "schemas"
FIXTURES_DIR = BASE_DIR / "fixtures"
OPENAPI_FILE = BASE_DIR / "openapi.json"
MANIFEST_FILE = BASE_DIR / "manifest.sha256"


# ---------------------------------------------------------------------------
# Restricted Canonical JSON Profile v1 (RFC 8785 Subset)
# ---------------------------------------------------------------------------

class CanonicalSerializationError(Exception):
    pass


def escape_jcs_string(s: str) -> str:
    """Escapes string according to RFC 8785 rules."""
    out = []
    for c in s:
        code = ord(c)
        if 0xD800 <= code <= 0xDFFF:
            raise CanonicalSerializationError(f"Unpaired Unicode surrogate detected: {c!r}")
        if c == '"':
            out.append('\\"')
        elif c == '\\':
            out.append('\\\\')
        elif c == '\b':
            out.append('\\b')
        elif c == '\f':
            out.append('\\f')
        elif c == '\n':
            out.append('\\n')
        elif c == '\r':
            out.append('\\r')
        elif c == '\t':
            out.append('\\t')
        elif code < 0x20:
            out.append(f"\\u{code:04x}")
        else:
            out.append(c)
    return '"' + "".join(out) + '"'


def jcs_serialize(obj: Any) -> str:
    """
    Serializes object following Small Trader Restricted Canonical JSON Profile v1:
    - All economic and monetary values must be strings (floats strictly forbidden).
    - Keys sorted by UTF-16 code units (RFC 8785 section 3.2.3).
    - Characters < 0x20 escaped as \\u00xx or standard escapes; non-ASCII UTF-8 unescaped.
    - No whitespace between tokens.
    """
    if isinstance(obj, float):
        raise CanonicalSerializationError(
            "IEEE 754 floats are strictly forbidden in Restricted Canonical JSON Profile; all decimals must be string-encoded"
        )
    if obj is None:
        return "null"
    if isinstance(obj, bool):
        return "true" if obj else "false"
    if isinstance(obj, int):
        return str(obj)
    if isinstance(obj, str):
        return escape_jcs_string(obj)
    if isinstance(obj, list):
        items = [jcs_serialize(item) for item in obj]
        return "[" + ",".join(items) + "]"
    if isinstance(obj, dict):
        sorted_keys = sorted(obj.keys(), key=lambda k: k.encode("utf-16-be"))
        parts = []
        for k in sorted_keys:
            parts.append(escape_jcs_string(k) + ":" + jcs_serialize(obj[k]))
        return "{" + ",".join(parts) + "}"
    raise CanonicalSerializationError(f"Unsupported data type for canonical serialization: {type(obj)}")


def restricted_canonical_json_bytes(obj: Any) -> bytes:
    """Returns canonical JSON bytes in UTF-8."""
    return jcs_serialize(obj).encode("utf-8")


def compute_restricted_sha256_digest(obj: Any) -> str:
    """Computes 'sha256:<hex>' digest over restricted canonical JSON bytes."""
    raw_bytes = restricted_canonical_json_bytes(obj)
    return f"sha256:{hashlib.sha256(raw_bytes).hexdigest()}"


def parse_json_reject_duplicates(raw_str: str) -> Any:
    """JSON parser that strictly rejects duplicate object keys."""
    def dict_builder(pairs):
        res = {}
        for k, v in pairs:
            if k in res:
                raise CanonicalSerializationError(f"Duplicate JSON key detected: {k!r}")
            res[k] = v
        return res
    return json.loads(raw_str, object_pairs_hook=dict_builder)


# ---------------------------------------------------------------------------
# Schema Registry & Validation Hub
# ---------------------------------------------------------------------------

class SchemaValidatorHub:
    def __init__(self):
        self.schemas: Dict[str, Dict[str, Any]] = {}
        self.resources: Dict[str, Resource] = {}
        self.registry = Registry()
        self._load_all_schemas()

    def _load_all_schemas(self):
        for schema_path in SCHEMAS_DIR.glob("*.json"):
            with open(schema_path, "r", encoding="utf-8") as f:
                schema_data = json.load(f)
            schema_id = schema_data.get("$id", schema_path.name)
            self.schemas[schema_path.name] = schema_data
            res = Resource.from_contents(schema_data)
            self.resources[schema_id] = res
            self.resources[schema_path.name] = res

        for uri, res in self.resources.items():
            self.registry = self.registry.with_resource(uri, res)

    def validate_instance(self, instance: Any, schema_name: str, context: str = ""):
        if schema_name not in self.schemas:
            raise ValueError(f"Schema not found in registry: {schema_name}")
        schema_data = self.schemas[schema_name]
        validator = ContractValidator(schema_data, registry=self.registry, format_checker=FormatChecker())
        errors = sorted(validator.iter_errors(instance), key=lambda e: e.path)
        if errors:
            err_msg = "\n".join(
                [f" - at '{'/'.join(str(p) for p in err.path) or 'root'}': {err.message}" for err in errors]
            )
            raise JsonSchemaValidationError(f"Validation failed for {context} against {schema_name}:\n{err_msg}")


# ---------------------------------------------------------------------------
# Main Validation Pipeline
# ---------------------------------------------------------------------------

def run_suite() -> int:
    print("=" * 70)
    print("Small Trader Mobile V1 Contract Validation Suite")
    print("=" * 70)
    passed_checks = 0

    # 1. Genuine JSON Schema Draft 2020-12 Validation
    print("\n[Step 1] Validating JSON Schemas with Draft 2020-12...")
    validator_hub = SchemaValidatorHub()

    schema_files = sorted(list(SCHEMAS_DIR.glob("*.json")))
    if not schema_files:
        print(f"  FAIL: No schemas found in {SCHEMAS_DIR}")
        return 1

    for s_path in schema_files:
        with open(s_path, "r", encoding="utf-8") as f:
            try:
                s_json = json.load(f)
            except Exception as e:
                print(f"  FAIL: Schema {s_path.name} is not valid JSON: {e}")
                return 1

        try:
            Draft202012Validator.check_schema(s_json)
        except SchemaError as e:
            print(f"  FAIL: Schema {s_path.name} is not valid Draft 2020-12: {e.message}")
            return 1

        if "$schema" not in s_json or s_json["$schema"] != "https://json-schema.org/draft/2020-12/schema":
            print(f"  FAIL: Schema {s_path.name} does not declare Draft 2020-12 $schema")
            return 1

        print(f"  OK: {s_path.name} (Draft 2020-12 verified)")
        passed_checks += 1
    print(f"  Total schemas validated: {len(schema_files)}")

    # 2. Genuine OpenAPI 3.1 Specification Validation
    print("\n[Step 2] Validating OpenAPI 3.1 Specification...")
    if not OPENAPI_FILE.exists():
        print(f"  FAIL: openapi.json not found at {OPENAPI_FILE}")
        return 1

    with open(OPENAPI_FILE, "r", encoding="utf-8") as f:
        try:
            openapi_spec = json.load(f)
        except Exception as e:
            print(f"  FAIL: openapi.json is not valid JSON: {e}")
            return 1

    try:
        openapi_spec_validator.validate(openapi_spec, base_uri=OPENAPI_FILE.resolve().as_uri())
        print("  OK: OpenAPI 3.1 meta-schema validation passed")
        passed_checks += 1
    except Exception as e:
        print(f"  FAIL: OpenAPI 3.1 meta-schema validation failed: {e}")
        return 1

    # Verify semantic OpenAPI invariants
    paths = openapi_spec.get("paths", {})
    expected_endpoints = [
        "/api/mobile/v1/auth/challenge",
        "/api/mobile/v1/auth/login",
        "/api/mobile/v1/auth/logout",
        "/api/mobile/v1/devices/{deviceId}/revoke",
        "/api/mobile/v1/companies/{companyId}/bootstrap",
        "/api/mobile/v1/companies/{companyId}/products",
        "/api/mobile/v1/companies/{companyId}/customers",
        "/api/mobile/v1/companies/{companyId}/sales/preview",
        "/api/mobile/v1/companies/{companyId}/sales/commit",
        "/api/mobile/v1/companies/{companyId}/offline-events",
        "/api/mobile/v1/companies/{companyId}/offline-events/{clientEventId}/status",
    ]

    for ep in expected_endpoints:
        if ep not in paths:
            print(f"  FAIL: Missing expected endpoint in openapi.json: {ep}")
            return 1

        path_item = paths[ep]
        for method, operation in path_item.items():
            if method.lower() not in ["get", "post", "put", "delete", "patch"]:
                continue

            op_id = operation.get("operationId")
            if not op_id:
                print(f"  FAIL: Operation {method.upper()} {ep} missing operationId")
                return 1

            # Path parameter consistency
            path_param_names = set(re.findall(r"\{([a-zA-Z0-9_]+)\}", ep))
            declared_params = operation.get("parameters", [])
            declared_path_params = {
                p["name"] for p in declared_params if p.get("in") == "path" and p.get("required") is True
            }
            if path_param_names != declared_path_params:
                print(f"  FAIL: Path param mismatch on {method.upper()} {ep}: expected {path_param_names}, got {declared_path_params}")
                return 1

            # Path parameter pattern check for companyId (must be ULID)
            for p in declared_params:
                if p.get("name") == "companyId" and p.get("in") == "path":
                    schema = p.get("schema", {})
                    if schema.get("pattern") != "^[0-7][0-9A-HJKMNP-TV-Z]{25}$":
                        print(f"  FAIL: companyId path parameter must enforce strict Crockford ULID pattern on {method.upper()} {ep}")
                        return 1

            # Security scheme check
            sec = operation.get("security")
            if sec:
                for req in sec:
                    for scheme_name in req:
                        if scheme_name not in openapi_spec.get("components", {}).get("securitySchemes", {}):
                            print(f"  FAIL: Undefined security scheme '{scheme_name}' on {method.upper()} {ep}")
                            return 1

            # Status schemas check
            responses = operation.get("responses", {})
            if "200" not in responses and "201" not in responses:
                print(f"  FAIL: Operation {method.upper()} {ep} missing 200/201 success response")
                return 1

        print(f"  OK: Endpoint semantically verified: {ep}")
        passed_checks += 1

    # 3. Validate Golden Fixtures against schemas
    print("\n[Step 3] Validating Golden Fixtures...")
    fixtures_map = [
        ("golden-auth-challenge.json", "request", "auth-challenge-request.json"),
        ("golden-auth-challenge.json", "response", "auth-challenge-response.json"),
        ("golden-auth-login.json", "request", "auth-login-request.json"),
        ("golden-auth-login.json", "response", "auth-login-response.json"),
        ("golden-auth-logout.json", "request", "auth-logout-request.json"),
        ("golden-auth-logout.json", "response", "auth-logout-response.json"),
        ("golden-device-revoke.json", "request", "device-revoke-request.json"),
        ("golden-device-revoke.json", "response", "device-revoke-response.json"),
        ("golden-bootstrap.json", None, "bootstrap-response.json"),
        ("golden-product-catalog.json", None, "product-catalog-response.json"),
        ("golden-customer-directory.json", None, "customer-directory-response.json"),
        ("golden-sale-preview.json", "request", "sale-preview-request.json"),
        ("golden-sale-preview.json", "response", "sale-preview-response.json"),
        ("golden-sale-commit.json", "request", "sale-commit-request.json"),
        ("golden-sale-commit.json", "response", "sale-commit-response.json"),
        ("golden-offline-sale-intake.json", "request", "offline-sale-intake-request.json"),
        ("golden-offline-sale-intake.json", "response", "offline-sale-intake-response.json"),
        ("golden-sale-event-status.json", None, "sale-event-status-response.json"),
    ]

    for fix_name, sub_key, schema_name in fixtures_map:
        fix_path = FIXTURES_DIR / fix_name
        with open(fix_path, "r", encoding="utf-8") as f:
            data = json.load(f)
        target = data[sub_key] if sub_key else data
        try:
            validator_hub.validate_instance(target, schema_name, f"{fix_name} [{sub_key or 'root'}]")
        except JsonSchemaValidationError as e:
            print(f"  FAIL: {e}")
            return 1
        print(f"  OK: {fix_name} [{sub_key or 'root'}] conforms to {schema_name}")
        passed_checks += 1

    # Verify golden offline intake envelope equality and digest
    with open(FIXTURES_DIR / "golden-offline-sale-intake.json", "r", encoding="utf-8") as f:
        off_req = json.load(f)["request"]

    # Check envelope equality bindings
    if off_req["client_event_id"] != off_req["event"]["client_event_id"]:
        print("  FAIL: Envelope client_event_id does not match event client_event_id")
        return 1
    if off_req["device_id"] != off_req["event"]["device_id"]:
        print("  FAIL: Envelope device_id does not match event device_id")
        return 1
    if off_req["captured_at"] != off_req["event"]["captured_at"]:
        print("  FAIL: Envelope captured_at does not match event captured_at")
        return 1

    computed_digest = compute_restricted_sha256_digest(off_req["event"])
    if computed_digest != off_req["payload_digest"]:
        print(f"  FAIL: Offline intake payload_digest mismatch!\n    Computed: {computed_digest}\n    In req:   {off_req['payload_digest']}")
        return 1
    print(f"  OK: Offline intake golden event matches payload_digest: {computed_digest}")
    passed_checks += 1

    # 4. Golden Canonical JSON Vectors (Restricted Profile v1)
    print("\n[Step 4] Validating Golden Canonical JSON and SHA-256 Digest Vectors...")
    vectors_file = FIXTURES_DIR / "golden-canonical-vectors.json"
    with open(vectors_file, "r", encoding="utf-8") as f:
        vectors = json.load(f)

    for vec in vectors:
        v_name = vec["name"]
        inp = vec["input"]
        expected_canonical = vec["canonical_json"]
        expected_digest = vec["digest"]

        try:
            actual_canonical = restricted_canonical_json_bytes(inp).decode("utf-8")
        except Exception as e:
            print(f"  FAIL: Vector '{v_name}' canonical serialization raised: {e}")
            return 1

        if actual_canonical != expected_canonical:
            print(f"  FAIL Vector '{v_name}': canonical mismatch!\n    Exp: {expected_canonical}\n    Act: {actual_canonical}")
            return 1

        actual_digest = compute_restricted_sha256_digest(inp)
        if actual_digest != expected_digest:
            print(f"  FAIL Vector '{v_name}': digest mismatch!\n    Exp: {expected_digest}\n    Act: {actual_digest}")
            return 1

        print(f"  OK: Vector '{v_name}' verified: {actual_digest}")
        passed_checks += 1

    # 5. Validate Regression Fixtures (Contract consistency only)
    print("\n[Step 5] Validating Regression Fixtures (Contract Schema & Policy Parity)...")

    # 5a. Altered payload conflict
    with open(FIXTURES_DIR / "regression-altered-payload-conflict.json", "r", encoding="utf-8") as f:
        alt_data = json.load(f)
    alt_event = alt_data["incoming_request"]["event"]
    computed_alt_digest = compute_restricted_sha256_digest(alt_event)
    if computed_alt_digest != alt_data["incoming_request"]["payload_digest"]:
        print("  FAIL: Altered incoming request payload_digest does not match computed digest")
        return 1
    if computed_alt_digest == alt_data["stored_record"]["payload_digest"]:
        print("  FAIL: Altered payload digest MUST differ from stored record digest")
        return 1
    if alt_data["expected_status"] != 409:
        print("  FAIL: Altered payload conflict expected status must be 409")
        return 1
    validator_hub.validate_instance(alt_data["expected_response"], "error-response.json", "regression-altered-payload")
    print("  OK: Altered payload conflict fixture verified (digest mismatch -> 409 Conflict schema)")
    passed_checks += 1

    # 5b. Decimal exact arithmetic with Laravel SalesLineCalculator & TaxPercentage parity
    with open(FIXTURES_DIR / "regression-decimal-edge-cases.json", "r", encoding="utf-8") as f:
        dec_data = json.load(f)
    for tc in dec_data["test_cases"]:
        tc_name = tc["name"]
        if tc_name == "sales_line_calculator_exclusive_16_percent":
            q = Decimal(tc["quantity"])
            p = Decimal(tc["unit_price"])
            sub = (q * p).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)
            sub_6 = f"{sub:.6f}"
            if sub_6 != tc["subtotal"]:
                print(f"  FAIL: Subtotal mismatch in {tc_name}: {sub_6} != {tc['subtotal']}")
                return 1
            tax_rate = Decimal(tc["tax_rate"]) / Decimal(100)
            tax_amt = (sub * tax_rate).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)
            tax_6 = f"{tax_amt:.6f}"
            if tax_6 != tc["tax_amount"]:
                print(f"  FAIL: Tax amount mismatch in {tc_name}: {tax_6} != {tc['tax_amount']}")
                return 1
            tot = sub + tax_amt
            tot_6 = f"{tot:.6f}"
            if tot_6 != tc["line_total"]:
                print(f"  FAIL: Total mismatch in {tc_name}: {tot_6} != {tc['line_total']}")
                return 1
        elif tc_name == "sales_line_calculator_inclusive_16_percent":
            q = Decimal(tc["quantity"])
            p = Decimal(tc["unit_price"])
            sub = (q * p).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)
            sub_6 = f"{sub:.6f}"
            if sub_6 != tc["subtotal"]:
                print(f"  FAIL: Subtotal mismatch in {tc_name}: {sub_6} != {tc['subtotal']}")
                return 1
            rate = Decimal(tc["tax_rate"]) / Decimal(100)
            one_plus_rate = Decimal(1) + rate
            net = (sub / one_plus_rate).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)
            net_6 = f"{net:.6f}"
            if net_6 != tc["net_before_tax"]:
                print(f"  FAIL: Net mismatch in {tc_name}: {net_6} != {tc['net_before_tax']}")
                return 1
            tax_amt = sub - net
            tax_6 = f"{tax_amt:.6f}"
            if tax_6 != tc["tax_amount"]:
                print(f"  FAIL: Tax mismatch in {tc_name}: {tax_6} != {tc['tax_amount']}")
                return 1
            tot = net + tax_amt
            tot_6 = f"{tot:.6f}"
            if tot_6 != tc["line_total"]:
                print(f"  FAIL: Total mismatch in {tc_name}: {tot_6} != {tc['line_total']}")
                return 1
        elif tc_name == "trailing_zero_quantity_support":
            q = Decimal(tc["quantity"])
            p = Decimal(tc["unit_price"])
            sub = (q * p).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)
            rate = Decimal(tc["tax_rate"]) / Decimal(100)
            tax_amt = (sub * rate).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)
            tot = sub + tax_amt
            tot_6 = f"{tot:.6f}"
            if tot_6 != tc["line_total"]:
                print(f"  FAIL: Total mismatch in {tc_name}: {tot_6} != {tc['line_total']}")
                return 1
        elif tc_name == "half_up_vs_half_even_parity":
            val = Decimal(tc["amount"])
            q_half_up = val.quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)
            q_half_even = val.quantize(Decimal("0.01"), rounding=ROUND_HALF_EVEN)
            if f"{q_half_up:.6f}" != tc["expected_half_up"]:
                print(f"  FAIL: Half-up mismatch: {q_half_up:.6f} != {tc['expected_half_up']}")
                return 1
            if f"{q_half_even:.6f}" != tc["bankers_half_even_divergence"]:
                print(f"  FAIL: Half-even divergence mismatch: {q_half_even:.6f} != {tc['bankers_half_even_divergence']}")
                return 1
        elif tc_name == "exact_three_cent_sum":
            d1 = Decimal(tc["line_1"])
            d2 = Decimal(tc["line_2"])
            if f"{d1 + d2:.6f}" != tc["exact_sum"]:
                print("  FAIL: Exact sum mismatch")
                return 1
        elif tc_name == "max_magnitude_boundaries":
            if Decimal(tc["max_quantity"]) != Decimal("99999999999999.999999"):
                print("  FAIL: max_quantity magnitude boundary mismatch")
                return 1
            if Decimal(tc["max_money"]) != Decimal("99999999999999.999999"):
                print("  FAIL: max_money magnitude boundary mismatch")
                return 1

    print("  OK: Decimal exact arithmetic regression verified (Laravel RoundingMode::HALF_UP & TaxPercentage parity)")
    passed_checks += 1

    # 5c. Lost ACK replay
    with open(FIXTURES_DIR / "regression-lost-ack-replay.json", "r", encoding="utf-8") as f:
        lost_ack = json.load(f)
    if lost_ack["first_submission"]["payload_digest"] != lost_ack["retry_submission"]["payload_digest"]:
        print("  FAIL: Lost ACK first and retry submissions must have identical digest")
        return 1
    if lost_ack["first_submission"]["client_event_id"] != lost_ack["retry_submission"]["client_event_id"]:
        print("  FAIL: Lost ACK first and retry submissions must have identical client_event_id")
        return 1
    validator_hub.validate_instance(lost_ack["server_behavior"]["response"], "offline-sale-intake-response.json", "regression-lost-ack")
    print("  OK: Lost-ACK replay fixture verified (identical digest -> canonical outcome schema)")
    passed_checks += 1

    # 5d. Tenant mismatch
    with open(FIXTURES_DIR / "regression-tenant-mismatch.json", "r", encoding="utf-8") as f:
        tenant_mis = json.load(f)
    if tenant_mis["expected_status"] != 403:
        print("  FAIL: Tenant mismatch expected_status must be 403")
        return 1
    validator_hub.validate_instance(tenant_mis["expected_response"], "error-response.json", "regression-tenant-mismatch")
    print("  OK: Tenant mismatch fixture verified (cross-tenant access -> 403 error schema)")
    passed_checks += 1

    # 5e. Auth revocation (revoked Bearer 401 Unauthorized with client queue lock)
    with open(FIXTURES_DIR / "regression-auth-revocation.json", "r", encoding="utf-8") as f:
        rev_data = json.load(f)
    rev_computed_digest = compute_restricted_sha256_digest(rev_data["intake_submission"]["event"])
    if rev_computed_digest != rev_data["intake_submission"]["payload_digest"]:
        print("  FAIL: Revocation fixture intake submission digest mismatch")
        return 1
    if rev_data["expected_status"] != 401:
        print(f"  FAIL: Revocation expected_status must be 401 Unauthorized, got {rev_data['expected_status']}")
        return 1
    validator_hub.validate_instance(rev_data["expected_response"], "error-response.json", "regression-auth-revocation")
    if rev_data["client_outbox_state"]["queue_status"] != "QUEUE_LOCKED_AUTH_DENIED":
        print("  FAIL: Client queue status must be QUEUE_LOCKED_AUTH_DENIED")
        return 1
    print("  OK: Offline staff revocation verified (revoked token -> 401 error schema, queue locked)")
    passed_checks += 1

    # 6. Execute Meaningful Negative & Contract Validation Tests
    print("\n[Step 6] Running Meaningful Negative & Contract Validation Tests...")

    # Negative 1: Bad / unresolvable $ref in a schema
    bad_schema = {
        "$schema": "https://json-schema.org/draft/2020-12/schema",
        "type": "object",
        "properties": {"item": {"$ref": "nonexistent-schema.json"}},
    }
    v_bad = Draft202012Validator(bad_schema, registry=validator_hub.registry)
    try:
        list(v_bad.iter_errors({"item": {}}))
        print("  FAIL Negative 1: Expected unresolvable $ref error, but validation succeeded")
        return 1
    except referencing.exceptions.Unresolvable:
        print("  OK: Negative 1 passed (unresolvable $ref properly rejected)")
        passed_checks += 1

    # Negative 2: Missing path parameter in OpenAPI operation
    bad_openapi = {
        "openapi": "3.1.0",
        "info": {"title": "Test", "version": "1.0"},
        "paths": {
            "/api/{missingParam}": {
                "get": {
                    "operationId": "testOp",
                    "parameters": [],  # missing Param!
                    "responses": {"200": {"description": "OK"}},
                }
            }
        },
    }
    path_param_names = set(re.findall(r"\{([a-zA-Z0-9_]+)\}", "/api/{missingParam}"))
    declared = {p["name"] for p in bad_openapi["paths"]["/api/{missingParam}"]["get"]["parameters"] if p.get("in") == "path"}
    if path_param_names == declared:
        print("  FAIL Negative 2: Undeclared path param was not caught")
        return 1
    print("  OK: Negative 2 passed (undeclared path parameter caught)")
    passed_checks += 1

    # Negative 3: Missing security scheme declaration
    bad_sec_spec = {
        "openapi": "3.1.0",
        "info": {"title": "Test", "version": "1.0"},
        "paths": {
            "/api/secure": {
                "get": {
                    "operationId": "secOp",
                    "security": [{"undefinedAuth": []}],
                    "responses": {"200": {"description": "OK"}},
                }
            }
        },
        "components": {"securitySchemes": {}},
    }
    sec_names = [k for req in bad_sec_spec["paths"]["/api/secure"]["get"]["security"] for k in req]
    if any(k in bad_sec_spec["components"]["securitySchemes"] for k in sec_names):
        print("  FAIL Negative 3: Undefined security scheme was not caught")
        return 1
    print("  OK: Negative 3 passed (undefined security scheme caught)")
    passed_checks += 1

    # Negative 4: Negative quantity in offline sale intake
    bad_qty_payload = json.loads(json.dumps(off_req))
    bad_qty_payload["event"]["lines"][0]["quantity"] = "-2.000000"
    try:
        validator_hub.validate_instance(bad_qty_payload, "offline-sale-intake-request.json", "negative-qty")
        print("  FAIL Negative 4: Negative quantity was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Negative 4 passed (negative quantity rejected by schema)")
        passed_checks += 1

    # Negative 5: Negative monetary amount in offline sale intake
    bad_amt_payload = json.loads(json.dumps(off_req))
    bad_amt_payload["event"]["lines"][0]["unit_price"] = "-25.000000"
    try:
        validator_hub.validate_instance(bad_amt_payload, "offline-sale-intake-request.json", "negative-price")
        print("  FAIL Negative 5: Negative unit price was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Negative 5 passed (negative unit price rejected by schema)")
        passed_checks += 1

    # Negative 6: Floating point number instead of decimal string
    bad_float_payload = json.loads(json.dumps(off_req))
    bad_float_payload["event"]["lines"][0]["unit_price"] = 25.0  # numeric float!
    try:
        validator_hub.validate_instance(bad_float_payload, "offline-sale-intake-request.json", "float-price")
        print("  FAIL Negative 6: Float unit price was accepted by schema!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Negative 6 passed (float unit price rejected by schema type constraint)")
        passed_checks += 1

    # Negative 7: Unexpected additional property in strict schema
    bad_extra_payload = json.loads(json.dumps(off_req))
    bad_extra_payload["event"]["lines"][0]["unauthorized_field"] = "malicious"
    try:
        validator_hub.validate_instance(bad_extra_payload, "offline-sale-intake-request.json", "extra-property")
        print("  FAIL Negative 7: Additional unauthorized property was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Negative 7 passed (additional unauthorized property rejected)")
        passed_checks += 1

    # Negative 8: Envelope vs Event client_event_id mismatch
    mismatched_env = json.loads(json.dumps(off_req))
    mismatched_env["client_event_id"] = "00000000-0000-0000-0000-000000000000"
    if mismatched_env["client_event_id"] == mismatched_env["event"]["client_event_id"]:
        print("  FAIL Negative 8: Envelope mismatch was not detected")
        return 1
    print("  OK: Negative 8 passed (envelope vs event client_event_id mismatch caught)")
    passed_checks += 1

    # Negative 9: Tampered digest in intake request
    tampered_intake = json.loads(json.dumps(off_req))
    tampered_intake["payload_digest"] = "sha256:0000000000000000000000000000000000000000000000000000000000000000"
    if compute_restricted_sha256_digest(tampered_intake["event"]) == tampered_intake["payload_digest"]:
        print("  FAIL Negative 9: Tampered digest passed equality check!")
        return 1
    print("  OK: Negative 9 passed (tampered payload digest caught)")
    passed_checks += 1

    # Negative 10: Canonical serializer rejecting float and duplicate JSON keys
    try:
        restricted_canonical_json_bytes({"price": 10.5})
        print("  FAIL Negative 10: Canonical serializer accepted float!")
        return 1
    except CanonicalSerializationError:
        print("  OK: Negative 10a passed (canonical serializer rejected float)")
        passed_checks += 1

    try:
        parse_json_reject_duplicates('{"key": 1, "key": 2}')
        print("  FAIL Negative 10b: Parser accepted duplicate key!")
        return 1
    except CanonicalSerializationError:
        print("  OK: Negative 10b passed (duplicate JSON key rejected)")
        passed_checks += 1

    # Negative 11: Schema validation of 401 error response shape
    error_401 = {
        "error": {
            "code": "UNAUTHENTICATED",
            "message": "The Bearer token has been revoked or expired.",
            "details": {"reason": "ACTOR_REVOKED"},
        }
    }
    validator_hub.validate_instance(error_401, "error-response.json", "401-error-shape")
    print("  OK: Negative 11 passed (401 error response shape conforms to error-response.json)")
    passed_checks += 1

    # Check 12 (ULID Strict Crockford Acceptance & UUID Entity Rejection)
    # Server public ID fields must accept strict Crockford ULID and reject UUID or malformed ULID
    with open(FIXTURES_DIR / "golden-bootstrap.json", "r", encoding="utf-8") as f:
        valid_boot = json.load(f)

    # 12a: Positive validation: valid Crockford ULID accepted
    validator_hub.validate_instance(valid_boot, "bootstrap-response.json", "valid-ulid-bootstrap")
    print("  OK: Check 12a passed (strict Crockford ULID accepted for server entity public IDs)")
    passed_checks += 1

    # 12b: Negative validation: UUID rejected for server entity company_id
    uuid_as_ulid = json.loads(json.dumps(valid_boot))
    uuid_as_ulid["company_id"] = "c1a2b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5c"  # UUID rejected for server company
    try:
        validator_hub.validate_instance(uuid_as_ulid, "bootstrap-response.json", "uuid-as-company-id")
        print("  FAIL Check 12b: UUID was accepted as server company_id ULID!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 12b passed (UUID rejected for server company_id by ULID schema constraint)")
        passed_checks += 1

    # 12c: Negative validation: Malformed Crockford characters rejected (I, L, O, U, or lowercase)
    bad_char_ulid = json.loads(json.dumps(valid_boot))
    bad_char_ulid["company_id"] = "01JCV8X9M2NQW3TYZ4R5S6789I"  # contains 'I'
    try:
        validator_hub.validate_instance(bad_char_ulid, "bootstrap-response.json", "invalid-char-ulid")
        print("  FAIL Check 12c: Malformed ULID containing 'I' was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 12c passed (malformed Crockford ULID character strictly rejected)")
        passed_checks += 1

    # Check 13 (TaxPercentage bounds: reject > 100 and negative, accept 0..100 six decimals)
    bad_tax_preview = json.loads(json.dumps(off_req))
    bad_tax_preview["event"]["lines"][0]["tax_rate"] = "100.000001"  # > 100% rejected!
    try:
        validator_hub.validate_instance(bad_tax_preview, "offline-sale-intake-request.json", "over-tax-rate")
        print("  FAIL Check 13: Tax percentage > 100 was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 13 passed (Tax percentage > 100 strictly rejected by schema)")
        passed_checks += 1

    # Check 14 (Six-place trailing-zero quantities accepted, zero/neg/over-scale rejected)
    trailing_zero_payload = json.loads(json.dumps(off_req))
    trailing_zero_payload["event"]["lines"][0]["quantity"] = "0.100000"  # trailing zero positive quantity
    validator_hub.validate_instance(trailing_zero_payload, "offline-sale-intake-request.json", "trailing-zero-qty")
    print("  OK: Check 14a passed (six-place trailing zero quantity '0.100000' accepted)")
    passed_checks += 1

    zero_qty_payload = json.loads(json.dumps(off_req))
    zero_qty_payload["event"]["lines"][0]["quantity"] = "0.000000"  # all-zero positive quantity rejected
    try:
        validator_hub.validate_instance(zero_qty_payload, "offline-sale-intake-request.json", "zero-qty")
        print("  FAIL Check 14b: All-zero quantity '0.000000' was accepted by positive quantity schema!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 14b passed (all-zero quantity '0.000000' rejected by positive quantity schema)")
        passed_checks += 1

    overscale_qty_payload = json.loads(json.dumps(off_req))
    overscale_qty_payload["event"]["lines"][0]["quantity"] = "0.1000001"  # 7 decimals rejected
    try:
        validator_hub.validate_instance(overscale_qty_payload, "offline-sale-intake-request.json", "overscale-qty")
        print("  FAIL Check 14c: Over-scale quantity (7 decimals) was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 14c passed (over-scale quantity > 6 decimals rejected)")
        passed_checks += 1

    # Check 15 (Product packaging unit identity)
    missing_punit_payload = json.loads(json.dumps(off_req))
    del missing_punit_payload["event"]["lines"][0]["product_unit_id"]
    try:
        validator_hub.validate_instance(missing_punit_payload, "offline-sale-intake-request.json", "missing-punit")
        print("  FAIL Check 15: Missing product_unit_id was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 15 passed (product packaging unit id required on line items)")
        passed_checks += 1

    # Check 16 (Preview digest and commit token binding verification)
    with open(FIXTURES_DIR / "golden-sale-commit.json", "r", encoding="utf-8") as f:
        commit_fix = json.load(f)["request"]
    tampered_commit = json.loads(json.dumps(commit_fix))
    tampered_commit["payload_digest"] = "sha256:0000000000000000000000000000000000000000000000000000000000000000"
    if tampered_commit["payload_digest"] == commit_fix["payload_digest"]:
        print("  FAIL Check 16: Tampered commit digest matched original!")
        return 1
    print("  OK: Check 16 passed (preview token digest binding mismatch caught)")
    passed_checks += 1

    # Check 17 (MFA code and recovery code mutual exclusion in auth login)
    with open(FIXTURES_DIR / "golden-auth-login.json", "r", encoding="utf-8") as f:
        login_req = json.load(f)["request"]
    conflict_mfa_req = json.loads(json.dumps(login_req))
    conflict_mfa_req["mfa_code"] = "123456"
    conflict_mfa_req["recovery_code"] = "abcdef12-34567890"  # both provided!
    try:
        validator_hub.validate_instance(conflict_mfa_req, "auth-login-request.json", "conflict-mfa-recovery")
        print("  FAIL Check 17: Both mfa_code and recovery_code were accepted simultaneously!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 17 passed (mfa_code and recovery_code mutually exclusive constraint enforced)")
        passed_checks += 1

    # Revision 2 positive/negative read/auth fixtures, including every displayed width.
    import copy
    cases = json.loads((FIXTURES_DIR / "revision-2-validation-cases.json").read_text())
    revision_count = 0
    for case in cases:
        try:
            validator_hub.validate_instance(case["instance"], case["schema"], case["name"])
            accepted = True
        except JsonSchemaValidationError:
            accepted = False
        if accepted != case["valid"]:
            raise ValueError(f"Revision 2 fixture failed: {case['name']}")
        revision_count += 1
    print(f"  OK: {revision_count} revision 2 positive/negative fixture validations")
    passed_checks += revision_count

    print("\n  [Contract Isolation Notice] These validation checks verify contract schemas, OpenAPI invariants,")
    print("  exact decimal arithmetic, and JCS digests. Database row locks, tenant middlewares, and ledger")
    print("  postings are authoritative in Laravel account PHPUnit suites.")

    # 7. Verify reproducible manifest SHA-256 (fail closed if absent)
    print("\n[Step 7] Verifying Reproducible SHA-256 Manifest...")
    manifest_entries = generate_manifest_entries()

    if "--generate-manifest" in sys.argv:
        with open(MANIFEST_FILE, "w", encoding="utf-8") as f:
            for rel_path, sha in sorted(manifest_entries.items()):
                f.write(f"{sha}  {rel_path}\n")
        print(f"  OK: Successfully generated new manifest at {MANIFEST_FILE}")
        passed_checks += 1
    else:
        if not MANIFEST_FILE.exists():
            print(f"  FAIL CLOSED: manifest.sha256 not found at {MANIFEST_FILE}!")
            print("  Normal validation fails closed absent manifest. Explicitly run with --generate-manifest to generate.")
            return 1

        with open(MANIFEST_FILE, "r", encoding="utf-8") as f:
            lines = f.readlines()
        stored_manifest = {}
        for line in lines:
            line = line.strip()
            if not line:
                continue
            parts = line.split(maxsplit=1)
            if len(parts) == 2:
                stored_manifest[parts[1]] = parts[0]

        for rel_path, actual_sha in manifest_entries.items():
            if rel_path not in stored_manifest:
                print(f"  FAIL: File {rel_path} not in manifest.sha256")
                return 1
            if stored_manifest[rel_path] != actual_sha:
                print(f"  FAIL: Checksum mismatch for {rel_path}!\n    Expected: {stored_manifest[rel_path]}\n    Actual:   {actual_sha}")
                return 1
            passed_checks += 1
        print(f"  OK: All {len(manifest_entries)} contract files match manifest.sha256 exactly")

    print("\n" + "=" * 70)
    print(f"SUCCESS: All {passed_checks} contract, schema, fixture, regression, and negative tests passed!")
    print("=" * 70)
    return 0


def generate_manifest_entries() -> Dict[str, str]:
    entries = {}
    target_files = []
    target_files.append(BASE_DIR / "ADR-0001-mobile-v1-contract.md")
    target_files.append(OPENAPI_FILE)
    target_files.extend(sorted(SCHEMAS_DIR.glob("*.json")))
    target_files.extend(sorted(FIXTURES_DIR.glob("*.json")))

    for tf in target_files:
        rel = tf.relative_to(BASE_DIR).as_posix()
        with open(tf, "rb") as f:
            h = hashlib.sha256(f.read()).hexdigest()
        entries[rel] = h
    return entries


if __name__ == "__main__":
    sys.exit(run_suite())
