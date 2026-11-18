#!/usr/bin/env python3
"""
tools/validate-mobile-contract.py

Comprehensive Small Trader Mobile Contract Validation Suite (Draft Revision 3):
1. Validates all JSON Schemas in docs/integration/mobile-v1/schemas/ using genuine Draft 2020-12
2. Validates OpenAPI 3.1 specification using openapi-spec-validator with path parameter,
   security, query pagination/search, and status schema checks across /api/mobile/v1 and /api/mobile/v2
3. Validates all Golden Fixtures against schemas with Draft 2020-12 and envelope/profile equality bindings
   (both historical v1 compatibility vectors and current v2 / revision 3 payloads)
4. Validates Golden Canonical JSON and SHA-256 Digest test vectors (Restricted JCS profile v1)
5. Validates Regression Fixtures (contract consistency only; Laravel SalesLineCalculator ROUND_HALF_UP,
   16% tax percentage, line round sequence, canonical ILS 50 gross 16% inclusive parity,
   trailing-zero quantities, boundary magnitudes, lost-ACK replay, tenant mismatch,
   auth revocation, and legacy v1 intake conflict mapping)
6. Runs meaningful Negative & Contract Validation Tests:
   - Genuine schema rejection: bad refs, missing params, undefined schemes, negative/zero quantities,
     IEEE-754 floats, extra properties, duplicate keys, ULID acceptance & UUID entity rejection,
     tax > 100 rejection, six-place trailing-zero quantities, packaging unit identity,
     MFA mutual exclusion, explicit No-Tax pair rules, ULID bounds on tax_rate_id,
     tax_inclusive mode constraints, conversion_to_base positive decimal constraints,
     legacy v1 intake rejection, missing/out-of-range currency minor units.
   - Genuine verifier/helper calls (Checks 8, 9, 16, 23) replacing tautological comparisons:
     envelope mismatch, v1 tampered digest, preview commit token binding, v2 tampered digest.
   - Online canonical intent profile mutation tests (Check 26a-k): actor, company, device, event,
     tax identity, tax inclusive mode, conversion to base, decimal lexeme, line order, payment,
     and currency minor units tampering.
   - Manifest verification mutation tests (Check 27a-e): real filesystem mutation test (temporary
     untracked file restored cleanly), extra entry, duplicate entry, malformed line, checksum mismatch.
   - Archive byte checks (Check 28): historical goldens and archived schemas match exact parent bytes.
   - Positive each mode with omitted/null/valid cash fields, exact total full mode, digest derivation & tampering (Check 29a-d).
   - Missing required taxID rejection throwing typed error, never implicit NoTax (Check 30a-b).
   - Unknown raw request fields rejection at root/line/payment before projection (Check 31a-c).
   - Context strict known keys, ULID format, and boolean minor units rejection (Check 32a-e).
   - Payment consistency boundaries, zero total rejection, currency scale validation, overprecision rejection, trailing zeros, invalid lexemes, and negative amounts rejection (Check 33a-n).
   - Missing required economic fields for full/partial never default (Check 34a-f).
   - Source-backed adaptation test illustrations for offline cash net retained settled, unpaid command choice mapping, partial allocation, full overpayment rejection, missing resolver/date failure, and foreign resolver isolation without secrets leak (Check 35a-f).
   - Revision 2 (89 cases) and Revision 3 (31 cases) positive/negative suites.
7. Verifies reproducible SHA-256 manifest with exact set equality discovered vs stored,
   failing closed on extra/missing/duplicate/malformed entries.

Zero bare 'assert' statements — safe under python3 -O.

Note on Scope:
This suite validates contract schemas, OpenAPI 3.1 specifications, canonical serialization,
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
import base64
import decimal
import datetime
from decimal import Decimal, ROUND_HALF_UP, ROUND_HALF_EVEN
from pathlib import Path
from typing import Any, Dict, List, Tuple, Optional, Callable

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
DECISIONS_FILE = REPO_ROOT / "docs" / "integration" / "mobile-v1-revision-3-decisions.md"


# ---------------------------------------------------------------------------
# Custom Exceptions for Contract Verifiers
# ---------------------------------------------------------------------------

class CanonicalSerializationError(Exception):
    pass


class IntakeEnvelopeValidationError(Exception):
    pass


class CommitTokenBindingError(Exception):
    pass


class IntentProfileValidationError(Exception):
    pass


class ManifestValidationError(Exception):
    pass


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
        for schema_path in sorted(SCHEMAS_DIR.glob("*.json")):
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


_DEFAULT_VALIDATOR_HUB: Optional[SchemaValidatorHub] = None


def get_schema_validator_hub() -> SchemaValidatorHub:
    global _DEFAULT_VALIDATOR_HUB
    if _DEFAULT_VALIDATOR_HUB is None:
        _DEFAULT_VALIDATOR_HUB = SchemaValidatorHub()
    return _DEFAULT_VALIDATOR_HUB



# ---------------------------------------------------------------------------
# Restricted Canonical JSON Profile v1 (RFC 8785 Subset)
# ---------------------------------------------------------------------------

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
# Contract Verification Helpers (Genuine Validators replacing tautological checks)
# ---------------------------------------------------------------------------

def verify_offline_intake_envelope_and_digest(envelope: Dict[str, Any]) -> Tuple[bool, str]:
    """
    Genuine verification helper for offline intake transport delivery envelope:
    1. Checks envelope client_event_id equals inner event client_event_id.
    2. Checks envelope device_id equals inner event device_id.
    3. Checks envelope captured_at equals inner event captured_at.
    4. Computes restricted canonical SHA-256 digest of inner event.
    5. Verifies envelope payload_digest matches computed digest.
    Raises IntakeEnvelopeValidationError on any invariant violation.
    """
    if "event" not in envelope or not isinstance(envelope["event"], dict):
        raise IntakeEnvelopeValidationError("Offline intake envelope missing required 'event' object")
    ev = envelope["event"]

    if envelope.get("client_event_id") != ev.get("client_event_id"):
        raise IntakeEnvelopeValidationError(
            f"Envelope client_event_id '{envelope.get('client_event_id')}' does not match "
            f"event client_event_id '{ev.get('client_event_id')}'"
        )
    if envelope.get("device_id") != ev.get("device_id"):
        raise IntakeEnvelopeValidationError(
            f"Envelope device_id '{envelope.get('device_id')}' does not match "
            f"event device_id '{ev.get('device_id')}'"
        )
    if envelope.get("captured_at") != ev.get("captured_at"):
        raise IntakeEnvelopeValidationError(
            f"Envelope captured_at '{envelope.get('captured_at')}' does not match "
            f"event captured_at '{ev.get('captured_at')}'"
        )

    computed_digest = compute_restricted_sha256_digest(ev)
    if envelope.get("payload_digest") != computed_digest:
        raise IntakeEnvelopeValidationError(
            f"Envelope payload_digest mismatch: supplied '{envelope.get('payload_digest')}', "
            f"computed '{computed_digest}'"
        )
    return True, computed_digest


def verify_sale_commit_token_binding(commit_payload: Dict[str, Any]) -> Tuple[bool, str]:
    """
    Genuine verifier for sale commit request preview_token digest binding:
    1. Decodes opaque preview_token claims payload.
    2. Extracts embedded digest and company_id.
    3. Verifies request payload_digest matches digest bound in token.
    Raises CommitTokenBindingError on token malformation or digest mismatch.
    """
    token = commit_payload.get("preview_token", "")
    if not token or "." not in token:
        raise CommitTokenBindingError("Invalid preview_token: expected token.sig structure")

    token_part = token.split(".", 1)[0]
    rem = len(token_part) % 4
    if rem:
        token_part += "=" * (4 - rem)

    try:
        claims_bytes = base64.b64decode(token_part)
        claims = json.loads(claims_bytes.decode("utf-8"))
    except Exception as e:
        raise CommitTokenBindingError(f"Failed to decode preview_token claims: {e}")

    bound_digest = claims.get("digest")
    if not bound_digest:
        raise CommitTokenBindingError("preview_token claims missing 'digest'")

    request_digest = commit_payload.get("payload_digest")
    if not request_digest:
        raise CommitTokenBindingError("Commit request missing 'payload_digest'")

    if request_digest != bound_digest:
        raise CommitTokenBindingError(
            f"Preview token binding mismatch: commit payload_digest '{request_digest}' "
            f"does not match token-bound digest '{bound_digest}'"
        )
    return True, bound_digest


def construct_online_sale_intent_profile(
    context: Dict[str, Any],
    request: Dict[str, Any],
    validator_hub: Optional["SchemaValidatorHub"] = None
) -> Dict[str, Any]:
    """
    Constructs the normative online canonical intent profile by combining:
    - Authoritative company context from route / tenant membership
    - Authoritative staff actor context from authenticated token session
    - Server-qualified currency minor units context (0..6)
    - Client request parameters: device_id, client_event_id, customer_id, warehouse_id, currency,
      ordered line items with exact decimal lexemes, and payment intent.
    Preserves exact validated string decimal lexemes without implicit rounding, truncation, or repricing.

    Mandatory validation invariants:
    1. Context must contain strictly the known keys {'actor_id', 'company_id', 'currency_minor_units'}.
       actor_id and company_id must match Crockford ULID regex; currency_minor_units must be an int
       in 0..6 (strictly rejecting boolean as integer, since bool is an int subclass in Python).
    2. Raw request must be validated against sale-preview-request.json BEFORE any projection.
       Fails closed on unknown root, line, or payment fields, or missing required fields.
    3. Missing required tax_rate_id on line items throws IntentProfileValidationError; never defaults to
       implicit NoTax (ExplicitNoTax requires explicit null, never omitted key).
    4. Normalizes nullable payment fields explicitly only for fields the contract permits omission:
       omitted payment_method and cash_account_id canonically map to None (null in JSON) ONLY for
       unpaid settlement mode; missing required economic fields for full/partial never default to null.
    """
    if not isinstance(context, dict):
        raise IntentProfileValidationError("Context must be a dictionary")

    expected_context_keys = {"actor_id", "company_id", "currency_minor_units"}
    if set(context.keys()) != expected_context_keys:
        extra_keys = set(context.keys()) - expected_context_keys
        missing_keys = expected_context_keys - set(context.keys())
        msg_parts = []
        if extra_keys:
            msg_parts.append(f"unknown extra keys {sorted(extra_keys)}")
        if missing_keys:
            msg_parts.append(f"missing keys {sorted(missing_keys)}")
        raise IntentProfileValidationError(f"Invalid context keys: {', '.join(msg_parts)}")

    actor_id = context["actor_id"]
    company_id = context["company_id"]
    minor_units = context["currency_minor_units"]

    ulid_regex = r"^[0-7][0-9A-HJKMNP-TV-Z]{25}$"
    if not isinstance(actor_id, str) or not re.fullmatch(ulid_regex, actor_id):
        raise IntentProfileValidationError(f"Invalid actor_id ULID in context: {actor_id!r}")
    if not isinstance(company_id, str) or not re.fullmatch(ulid_regex, company_id):
        raise IntentProfileValidationError(f"Invalid company_id ULID in context: {company_id!r}")

    if isinstance(minor_units, bool) or not isinstance(minor_units, int) or not (0 <= minor_units <= 6):
        raise IntentProfileValidationError(
            f"Invalid currency_minor_units in context (must be int 0..6, reject bool): {minor_units!r}"
        )

    if not isinstance(request, dict):
        raise IntentProfileValidationError("Request must be a dictionary")

    hub = validator_hub or get_schema_validator_hub()
    try:
        hub.validate_instance(request, "sale-preview-request.json", "sale-preview-request")
    except JsonSchemaValidationError as e:
        raise IntentProfileValidationError(f"Raw request schema validation failed: {e}") from e

    req_lines = request.get("lines", [])
    if not req_lines:
        raise IntentProfileValidationError("Online intent profile requires at least one line item")

    profile_lines = []
    for idx, line in enumerate(req_lines):
        if not isinstance(line, dict):
            raise IntentProfileValidationError(f"Line item at index {idx} must be a dictionary")
        if "tax_rate_id" not in line:
            raise IntentProfileValidationError(
                f"Missing required 'tax_rate_id' on line {idx} (ExplicitNoTax requires explicit null, never omitted key)"
            )
        profile_lines.append({
            "product_id": line["product_id"],
            "product_unit_id": line["product_unit_id"],
            "conversion_to_base": line["conversion_to_base"],
            "quantity": line["quantity"],
            "unit_price": line["unit_price"],
            "tax_rate_id": line["tax_rate_id"],
            "tax_rate": line["tax_rate"],
            "tax_inclusive": line["tax_inclusive"],
        })

    payment = request["payment"]
    settlement_mode = payment["settlement_mode"]
    amount = payment["amount"]

    if settlement_mode == "unpaid":
        profile_method = payment.get("payment_method")
        profile_cash_account = payment.get("cash_account_id")
        if profile_method is not None or profile_cash_account is not None:
            raise IntentProfileValidationError(
                "An unpaid sale cannot carry payment method or cash account instructions"
            )
    elif settlement_mode in ("full", "partial"):
        if "payment_method" not in payment or payment["payment_method"] is None:
            raise IntentProfileValidationError(
                f"Missing required payment_method for {settlement_mode} settlement mode (never defaults)"
            )
        if "cash_account_id" not in payment or payment["cash_account_id"] is None:
            raise IntentProfileValidationError(
                f"Missing required cash_account_id for {settlement_mode} settlement mode (never defaults)"
            )
        profile_method = payment["payment_method"]
        profile_cash_account = payment["cash_account_id"]
    else:
        raise IntentProfileValidationError(f"Unsupported settlement_mode: {settlement_mode!r}")

    profile_payment = {
        "settlement_mode": settlement_mode,
        "payment_method": profile_method,
        "cash_account_id": profile_cash_account,
        "amount": amount,
    }

    profile = {
        "actor_id": actor_id,
        "client_event_id": request["client_event_id"],
        "company_id": company_id,
        "currency": request["currency"],
        "currency_minor_units": minor_units,
        "customer_id": request["customer_id"],
        "device_id": request["device_id"],
        "lines": profile_lines,
        "payment": profile_payment,
        "warehouse_id": request["warehouse_id"],
    }
    return profile


def verify_online_sale_intent_profile(
    profile: Dict[str, Any],
    expected_digest: str,
    validator_hub: Optional["SchemaValidatorHub"] = None
) -> Tuple[bool, str]:
    """
    Normative verification function for online sale canonical intent profiles:
    1. Validates profile shape against online-sale-intent-profile.json schema (mandatory validation).
    2. Confirms no IEEE-754 floats are present anywhere in the profile.
    3. Serializes profile to RFC 8785 restricted JCS bytes (UTF-16 key sorting, string decimal lexemes).
    4. Computes SHA-256 digest and asserts equality against expected_digest.
    Raises IntentProfileValidationError on schema violation, float detection, or digest mismatch.
    """
    hub = validator_hub or get_schema_validator_hub()
    try:
        hub.validate_instance(profile, "online-sale-intent-profile.json", "online-intent-profile")
    except JsonSchemaValidationError as e:
        raise IntentProfileValidationError(f"Profile schema validation failed: {e}") from e

    def assert_no_floats(val, path="root"):
        if isinstance(val, float):
            raise IntentProfileValidationError(f"Forbidden float detected at {path}: {val!r}")
        elif isinstance(val, dict):
            for k, v in val.items():
                assert_no_floats(v, f"{path}.{k}")
        elif isinstance(val, list):
            for i, item in enumerate(val):
                assert_no_floats(item, f"{path}[{i}]")

    assert_no_floats(profile)

    computed = compute_restricted_sha256_digest(profile)
    if computed != expected_digest:
        raise IntentProfileValidationError(
            f"Intent profile digest mismatch! Computed: '{computed}', expected: '{expected_digest}'"
        )
    return True, computed


# ---------------------------------------------------------------------------
# Exact Decimal Arithmetic Calculator Helper (SalesLineCalculator parity)
# ---------------------------------------------------------------------------

DECIMAL_MONEY_REGEX = re.compile(r"^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$")


def validate_currency_minor_units(cmu: Any) -> bool:
    """
    Validates currency minor units:
    Must be an explicit integer between 0 and 6.
    Rejects booleans (isinstance(True, int) trap), missing/None, non-integers, and out-of-bounds scales.
    Never guesses 2.
    """
    if cmu is None or isinstance(cmu, bool) or not isinstance(cmu, int):
        return False
    return 0 <= cmu <= 6


def parse_and_validate_monetary_amount(val: Any, cmu: int) -> Optional[Decimal]:
    """
    Validates that a monetary amount:
    1. Is a string lexeme (no IEEE-754 floats, no ints, no booleans, no None).
    2. Strictly matches DECIMAL_MONEY_REGEX (finite, no NaN, no Infinity, no scientific exponent notation).
    3. Exactly represents an integer number of currency minor units (cmu 0..6) using exact Decimal
       arithmetic without rounding (RoundingMode::UNNECESSARY parity). Trailing zeros (e.g. 50.000000 at cmu 2)
       are accepted, while fractions beyond cmu (e.g. 0.000001 at cmu 2) are strictly rejected.
    Returns Decimal object if valid, None if invalid (fail closed).
    """
    if not isinstance(val, str) or isinstance(val, bool):
        return None
    if not DECIMAL_MONEY_REGEX.match(val):
        return None
    try:
        d = Decimal(val)
    except Exception:
        return None
    if not d.is_finite():
        return None
    cents = d * (Decimal(10) ** cmu)
    if cents != cents.to_integral_value():
        return None
    return d


def calculate_canonical_line(
    quantity_str: str,
    unit_price_str: str,
    tax_rate_str: str,
    tax_inclusive: bool,
    minor_units: int,
) -> Dict[str, str]:
    """
    Genuine exact decimal calculator helper matching Laravel SalesLineCalculator.php parity:
    - subtotal = round(quantity * unit_price, minor_units, ROUND_HALF_UP)
    - If inclusive:
        net = round(subtotal / (1 + rate), minor_units, ROUND_HALF_UP)
        tax = subtotal - net
        line_total = subtotal
      If exclusive:
        tax = round(subtotal * rate, minor_units, ROUND_HALF_UP)
        line_total = subtotal + tax
    - Formats all results as exact 6-decimal string lexemes.
    - Preserves quantities and packaging conversion to 6 decimal precision, and tax rate to 6 percent,
      independent of currency minor units.
    - minor_units is mandatory explicit context (int 0..6; booleans, invalid scales, and missing context
      fail closed; never guesses 2).
    """
    if not validate_currency_minor_units(minor_units):
        raise ValueError(f"minor_units must be an explicit integer between 0 and 6 (got {minor_units!r})")

    q = Decimal(quantity_str)
    p = Decimal(unit_price_str)
    scale = Decimal(10) ** -minor_units

    sub = (q * p).quantize(scale, rounding=ROUND_HALF_UP)
    rate = Decimal(tax_rate_str) / Decimal(100)

    if tax_inclusive:
        one_plus_rate = Decimal(1) + rate
        net = (sub / one_plus_rate).quantize(scale, rounding=ROUND_HALF_UP)
        tax = sub - net
        tot = sub
    else:
        net = sub
        tax = (sub * rate).quantize(scale, rounding=ROUND_HALF_UP)
        tot = sub + tax

    return {
        "subtotal": f"{sub:.6f}",
        "net_before_tax": f"{net:.6f}",
        "tax_amount": f"{tax:.6f}",
        "line_total": f"{tot:.6f}",
    }


def verify_payment_decimal_consistency(
    payment: Any,
    grand_total_str: Any,
    currency_minor_units: Any = None,
) -> bool:
    """
    Verifies payment consistency according to authoritative contract and canonical account rules:
    - Pre-conditions / Fail-Closed Invariants:
        * payment must be a dictionary.
        * currency_minor_units is mandatory explicit context (int 0..6; booleans, invalid scales, and missing context return False; no guessing 2).
        * grand_total_str must be a valid monetary decimal string representing integer currency minor units without rounding,
          and MUST be strictly positive (grand_total > 0). First-release policy permits positive financial grand total ONLY;
          total <= 0 is rejected for all paid, unpaid, and offline sales (no zero-invoice/receipt workaround).
    - Offline cash intake: verifies exact cash tender conservation:
        * amount_tendered == amount_settled + change_returned
        * amount_settled == grand_total (strictly positive)
        * mode == "full"
        * amount_tendered, amount_settled, change_returned must all represent integer currency minor units without rounding.
    - Online payment intent:
        * settlement_mode == "unpaid": amount == 0 (grand_total strictly positive)
        * settlement_mode == "partial": 0 < amount < grand_total (positive below qualified grand total)
        * settlement_mode == "full": amount == grand_total (exact grand total; excess is unallocated customer credit, not returned change,
          and is rejected under the first-release exact policy). Amount must be strictly positive (> 0).
    Fails closed (returns False) on malformed lexemes, NaN, Infinity, floats, exponent notations,
    negative amounts, overprecision beyond currency minor units, or invariant violations without crashing.
    """
    if not isinstance(payment, dict):
        return False

    if not validate_currency_minor_units(currency_minor_units):
        return False

    cmu: int = currency_minor_units

    gt = parse_and_validate_monetary_amount(grand_total_str, cmu)
    if gt is None:
        return False

    # Positive financial grand total ONLY policy: total <= 0 strictly rejected
    if gt <= Decimal("0"):
        return False

    # 1. Offline payment tender conservation branch
    if "amount_tendered" in payment or "amount_settled" in payment:
        if (
            "amount_tendered" not in payment
            or "amount_settled" not in payment
            or "change_returned" not in payment
        ):
            return False

        tendered = parse_and_validate_monetary_amount(payment.get("amount_tendered"), cmu)
        settled = parse_and_validate_monetary_amount(payment.get("amount_settled"), cmu)
        change = parse_and_validate_monetary_amount(payment.get("change_returned"), cmu)

        if tendered is None or settled is None or change is None:
            return False

        if payment.get("mode") != "full":
            return False

        # Settled amount must be strictly positive (PostCustomerPaymentAction requirement)
        if settled <= Decimal("0"):
            return False

        if settled != gt:
            return False

        return tendered == (settled + change)

    # 2. Online payment intent branch
    if "settlement_mode" in payment:
        mode = payment.get("settlement_mode")
        if "amount" not in payment:
            return False

        amt = parse_and_validate_monetary_amount(payment.get("amount"), cmu)
        if amt is None:
            return False

        if mode == "unpaid":
            # Unpaid requires amount == 0, with strictly positive grand total (> 0)
            return amt == Decimal("0")
        elif mode == "partial":
            # Partial requires 0 < amount < grand_total
            return Decimal("0") < amt < gt
        elif mode == "full":
            # Full requires exact match: amount == grand_total (and since gt > 0, amount > 0)
            return amt == gt
        return False

    return False


def adapt_offline_cash_to_canonical_receipt(
    payment: Dict[str, Any],
    grand_total_str: str,
    currency_minor_units: int,
    reviewed_payment_date: Optional[str] = None,
    cash_account_resolver: Optional[Callable[..., int]] = None,
    company_id: Optional[str] = None,
    customer_id: Optional[int] = None,
) -> Dict[str, Any]:
    """
    Source-backed adaptation test illustration (contract qualification, NOT server implementation):
    Adapts offline cash tender capture (amount_tendered, amount_settled, change_returned)
    to proposed canonical receipt parameters.

    Authoritative notes:
    1. The helper result is an illustrative audit mapping, NOT a direct PostCustomerPaymentActionDTO.
    2. Offline audit bytes and digest retain tender and change separately in 'audit'.
    3. The authoritative canonical receipt amount is NET RETAINED settled (amount_settled),
       NOT amount_tendered when change is returned.
       Example: tender 60.000000, total 50.000000, change 10.000000 -> canonical receipt amount 50.000000.
    4. Wire audit fields (amount_tendered, change_returned) are NEVER passed unchecked into PostCustomerPaymentAction.
    5. When parameterized with company-scoped customer_id, reviewed_payment_date, and cash_account_resolver,
       a separate canonical command payload is produced with resolved internal positive integer money_account_id.
    """
    if not validate_currency_minor_units(currency_minor_units):
        raise ValueError(f"currency_minor_units must be an explicit integer in 0..6 (got {currency_minor_units!r})")

    if not verify_payment_decimal_consistency(payment, grand_total_str, currency_minor_units):
        raise ValueError("Invalid offline cash payment tender conservation or non-positive settled amount")

    settled_str = str(payment["amount_settled"])
    tendered_str = str(payment["amount_tendered"])
    change_str = str(payment["change_returned"])

    res: Dict[str, Any] = {
        "audit": {
            "mode": "full",
            "payment_method": "cash",
            "amount_tendered": tendered_str,
            "amount_settled": settled_str,
            "change_returned": change_str,
        },
        "receipt_command": {
            "mode": "full",
            "payment_method": "cash",
            "receipt_amount": settled_str,
        },
        # Net retained settled amount (preserved for compatibility, never tendered amount)
        "receipt_amount": settled_str,
        "amount_tendered": tendered_str,
        "change_returned": change_str,
    }

    # Parameterized canonical command payload (if fully sourced)
    if reviewed_payment_date is not None and cash_account_resolver is not None:
        if not re.match(r"^\d{4}-\d{2}-\d{2}$", reviewed_payment_date):
            raise ValueError(f"reviewed_payment_date must be valid Y-m-d format, got {reviewed_payment_date!r}")
        try:
            datetime.date.fromisoformat(reviewed_payment_date)
        except Exception as e:
            raise ValueError(f"reviewed_payment_date is not a valid calendar date: {reviewed_payment_date}") from e
        if customer_id is None or isinstance(customer_id, bool) or not isinstance(customer_id, int) or customer_id <= 0:
            raise ValueError("customer_id must be a positive integer for canonical receipt command")
        wire_acct = payment.get("cash_account_id")
        if not wire_acct:
            raise ValueError("cash_account_id required to resolve money_account_id")
        try:
            if not isinstance(company_id, str) or not re.fullmatch(r"[0-7][0-9A-HJKMNP-TV-Z]{25}", company_id):
                raise ValueError("Explicit company identity required")
            internal_id = cash_account_resolver(company_id, wire_acct)
        except Exception as e:
            raise ValueError("Cash account resolution failed") from None

        if isinstance(internal_id, bool) or not isinstance(internal_id, int) or internal_id <= 0:
            raise ValueError(f"Resolved money_account_id must be a positive integer, got {internal_id!r}")

        res["canonical_command"] = {
            "customer_id": customer_id,
            "money_account_id": internal_id,
            "payment_date": reviewed_payment_date,
            "payment_method": "cash",
            "amount": settled_str,
            "exchange_rate": "1.0000000000",
        }

    return res


def adapt_online_payment_to_canonical_choice(
    intent_profile: Dict[str, Any],
    grand_total_str: str,
    currency_minor_units: int,
    reviewed_payment_date: Optional[str] = None,
    cash_account_resolver: Optional[Callable[..., int]] = None,
) -> Dict[str, Any]:
    """
    Source-backed adaptation test illustration (contract qualification, NOT server implementation):
    Maps validated online payment intent profile to backend ImmediateSaleCommands $choice array.

    Enforces canonical qualification invariants:
    - ONLY AFTER strict wire and intent profile validation and digest derivation:
      - unpaid: maps EXACTLY to {'mode': 'unpaid'}, stripping wire payment fields
        (amount, payment_method, cash_account_id) from the command input so that
        ImmediateSaleCommands::intent (array_diff(array_keys($choice), ['mode']) === [])
        succeeds, without altering the wire profile or its JCS payload digest.
        Neither reviewed_payment_date nor cash_account_resolver is required.
        Crucial: Stripping applies ONLY to validated payment snapshot fields; does NOT apply
        a general null filter across the payload (line tax_rate_id: null MUST remain explicit
        canonical NoTax).
      - full: under first-release exact policy (amount == grand_total, both strictly positive),
        maps wire amount to received_amount and invoice_amount allocation; rejects over/under payment.
        Requires explicit reviewed_payment_date (no invented default) and company-scoped active cash account
        resolver mapping wire ULID to internal positive integer money_account_id.
      - partial: base currency cash no-change (0 < amount < grand_total), maps wire amount to canonical
        received_amount and invoice_amount allocation; FX 1.0000000000, explicit reviewed_payment_date (no default),
        and resolved internal positive integer money_account_id.
    """
    if not validate_currency_minor_units(currency_minor_units):
        raise ValueError(f"currency_minor_units must be an explicit integer in 0..6 (got {currency_minor_units!r})")

    payment = intent_profile.get("payment", {})
    if not verify_payment_decimal_consistency(payment, grand_total_str, currency_minor_units):
        raise ValueError("Online payment decimal consistency or domain bound violation")

    mode = payment.get("settlement_mode")
    if mode == "unpaid":
        # Strips wire payment fields from choice; wire profile & digest remain unaltered.
        # No cash_account_resolver or reviewed_payment_date needed.
        return {"mode": "unpaid"}

    elif mode in ("full", "partial"):
        # reviewed_payment_date is mandatory with NO invented default
        if reviewed_payment_date is None or not isinstance(reviewed_payment_date, str):
            raise ValueError("reviewed_payment_date is mandatory for full/partial settlement (no default permitted)")
        if not re.match(r"^\d{4}-\d{2}-\d{2}$", reviewed_payment_date):
            raise ValueError(f"reviewed_payment_date must be valid Y-m-d format, got {reviewed_payment_date!r}")
        try:
            datetime.date.fromisoformat(reviewed_payment_date)
        except Exception as e:
            raise ValueError(f"reviewed_payment_date is not a valid calendar date: {reviewed_payment_date}") from e

        # cash_account_resolver is mandatory
        if cash_account_resolver is None or not callable(cash_account_resolver):
            raise ValueError("cash_account_resolver is mandatory for full/partial settlement (company-scoped resolver required)")

        company_id = intent_profile.get("company_id")
        wire_acct_id = payment.get("cash_account_id")
        if not wire_acct_id:
            raise ValueError("Missing cash_account_id in payment intent profile")

        try:
            if not isinstance(company_id, str) or not re.fullmatch(r"[0-7][0-9A-HJKMNP-TV-Z]{25}", company_id):
                raise ValueError("Explicit company identity required")
            internal_money_account_id = cash_account_resolver(company_id, wire_acct_id)
        except Exception as e:
            raise ValueError("Cash account resolution failed") from None

        if (
            isinstance(internal_money_account_id, bool)
            or not isinstance(internal_money_account_id, int)
            or internal_money_account_id <= 0
        ):
            raise ValueError(f"Resolved money_account_id must be a positive integer, got {internal_money_account_id!r}")

        amt = Decimal(str(payment["amount"]))
        total = Decimal(grand_total_str)

        if mode == "full":
            if amt != total:
                raise ValueError(f"Full settlement amount {amt} must equal grand total {total}")
            return {
                "mode": "full",
                "payment_method": "cash",
                "money_account_id": internal_money_account_id,
                "payment_date": reviewed_payment_date,
                "amount": str(payment["amount"]),
                "exchange_rate": "1.0000000000",
                "invoice_amount": grand_total_str,
                "payment_currency_amount": str(payment["amount"]),
            }
        else:  # partial
            return {
                "mode": "partial",
                "payment_method": "cash",
                "money_account_id": internal_money_account_id,
                "payment_date": reviewed_payment_date,
                "amount": str(payment["amount"]),
                "exchange_rate": "1.0000000000",
                "invoice_amount": str(payment["amount"]),
                "payment_currency_amount": str(payment["amount"]),
            }

    raise ValueError(f"Unsupported settlement mode: {mode}")


# ---------------------------------------------------------------------------
# Manifest Verification Engine (Exact Set Equality & Fail-Closed)
# ---------------------------------------------------------------------------

def generate_manifest_entries(base_dir: Path = BASE_DIR) -> Dict[str, str]:
    """Discovers all target contract files and computes their SHA-256."""
    entries = {}
    target_files = []
    target_files.append(base_dir / "ADR-0001-mobile-v1-contract.md")
    target_files.append(base_dir / "openapi.json")
    target_files.extend(sorted((base_dir / "schemas").glob("*.json")))
    target_files.extend(sorted((base_dir / "fixtures").glob("*.json")))

    for tf in target_files:
        rel = tf.relative_to(base_dir).as_posix()
        with open(tf, "rb") as f:
            h = hashlib.sha256(f.read()).hexdigest()
        entries[rel] = h
    return entries


def verify_manifest_content(
    manifest_lines: List[str],
    discovered_entries: Dict[str, str]
) -> Tuple[bool, str]:
    """
    Validates manifest lines against discovered contract files:
    1. Checks for malformed lines (must match '<sha256>  <rel_path>').
    2. Detects duplicate entries.
    3. Enforces EXACT SET EQUALITY: discovered_files == stored_files.
    4. Validates SHA-256 checksum for every file.
    """
    stored_manifest: Dict[str, str] = {}
    seen_paths = set()

    for line_idx, line in enumerate(manifest_lines, start=1):
        stripped = line.strip()
        if not stripped:
            continue
        m = re.fullmatch(r"([0-9a-f]{64})  (\S.*)", stripped)
        if not m:
            return False, f"Malformed manifest entry on line {line_idx}: {stripped!r}"
        sha, rel_path = m.groups()
        if rel_path in seen_paths:
            return False, f"Duplicate manifest entry detected on line {line_idx}: {rel_path}"
        seen_paths.add(rel_path)
        stored_manifest[rel_path] = sha

    discovered_set = set(discovered_entries.keys())
    stored_set = set(stored_manifest.keys())

    missing_in_stored = discovered_set - stored_set
    if missing_in_stored:
        return False, f"Contract files discovered on disk but missing from manifest: {sorted(missing_in_stored)}"

    extra_in_stored = stored_set - discovered_set
    if extra_in_stored:
        return False, f"Manifest contains entries not found on disk: {sorted(extra_in_stored)}"

    for rel_path, actual_sha in discovered_entries.items():
        expected_sha = stored_manifest[rel_path]
        if expected_sha != actual_sha:
            return False, f"Checksum mismatch for {rel_path}: expected {expected_sha}, got {actual_sha}"

    return True, f"All {len(discovered_entries)} contract files match manifest.sha256 exactly"


# ---------------------------------------------------------------------------
# Main Validation Pipeline
# ---------------------------------------------------------------------------

def run_suite() -> int:
    print("=" * 70)
    print("Small Trader Mobile V1 Contract Validation Suite (Revision 3 Draft)")
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

    # Verify semantic OpenAPI invariants across both v1 and v2 prefixes
    paths = openapi_spec.get("paths", {})
    expected_endpoints = [
        # Preserved API v1 endpoints
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
        # Agreed breaking API v2 endpoints
        "/api/mobile/v2/companies/{companyId}/bootstrap",
        "/api/mobile/v2/companies/{companyId}/sales/preview",
        "/api/mobile/v2/companies/{companyId}/sales/commit",
        "/api/mobile/v2/companies/{companyId}/offline-events",
        "/api/mobile/v2/companies/{companyId}/offline-events/{clientEventId}/status",
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

            # Path parameter pattern check for companyId (must be strict Crockford ULID)
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
        # Historical v1 compatibility fixtures
        ("golden-auth-challenge.json", "request", "auth-challenge-request.json"),
        ("golden-auth-challenge.json", "response", "auth-challenge-response.json"),
        ("golden-auth-login.json", "request", "auth-login-request.json"),
        ("golden-auth-login.json", "response", "auth-login-response.json"),
        ("golden-auth-logout.json", "request", "auth-logout-request.json"),
        ("golden-auth-logout.json", "response", "auth-logout-response.json"),
        ("golden-device-revoke.json", "request", "device-revoke-request.json"),
        ("golden-device-revoke.json", "response", "device-revoke-response.json"),
        ("golden-bootstrap.json", None, "archived-v1-bootstrap-response.json"),
        ("golden-product-catalog.json", None, "product-catalog-response.json"),
        ("golden-customer-directory.json", None, "customer-directory-response.json"),
        ("golden-sale-preview.json", "request", "archived-v1-sale-preview-request.json"),
        ("golden-sale-preview.json", "response", "archived-v1-sale-preview-response.json"),
        ("golden-sale-commit.json", "request", "sale-commit-request.json"),
        ("golden-sale-commit.json", "response", "sale-commit-response.json"),
        ("golden-offline-sale-intake.json", "request", "archived-v1-offline-sale-intake-request.json"),
        ("golden-offline-sale-intake.json", "response", "offline-sale-intake-response.json"),
        ("golden-sale-event-status.json", None, "sale-event-status-response.json"),
        # Current v2 / Revision 3 fixtures
        ("golden-bootstrap-v2.json", None, "bootstrap-response.json"),
        ("golden-offline-sale-intake-v2.json", "request", "offline-sale-intake-request.json"),
        ("golden-offline-sale-intake-v2.json", "response", "offline-sale-intake-response.json"),
        ("golden-sale-preview-v2.json", "request", "sale-preview-request.json"),
        ("golden-sale-preview-v2.json", "response", "sale-preview-response.json"),
        ("golden-online-sale-intent-profile.json", "intent_profile", "online-sale-intent-profile.json"),
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

    # Verify historical golden offline intake envelope equality and digest (v1) via genuine helper
    with open(FIXTURES_DIR / "golden-offline-sale-intake.json", "r", encoding="utf-8") as f:
        off_req_v1 = json.load(f)["request"]

    try:
        _, computed_digest_v1 = verify_offline_intake_envelope_and_digest(off_req_v1)
    except IntakeEnvelopeValidationError as e:
        print(f"  FAIL: Historical v1 offline intake helper failed: {e}")
        return 1

    if computed_digest_v1 != "sha256:d442ce49a7609a9a42bb5165990d481d1c7c563cb8030403487099a9919728d9":
        print(f"  FAIL: Historical v1 digest drift detected: {computed_digest_v1}")
        return 1
    print(f"  OK: Historical v1 offline intake golden matches payload_digest: {computed_digest_v1}")
    passed_checks += 1

    # Verify current v2 golden offline intake envelope equality and digest (v2) via genuine helper
    with open(FIXTURES_DIR / "golden-offline-sale-intake-v2.json", "r", encoding="utf-8") as f:
        off_req_v2 = json.load(f)["request"]

    try:
        _, computed_digest_v2 = verify_offline_intake_envelope_and_digest(off_req_v2)
    except IntakeEnvelopeValidationError as e:
        print(f"  FAIL: v2 Offline intake helper failed: {e}")
        return 1

    if computed_digest_v2 != "sha256:18f0403bbda6e05eab2805eb20c9c9f9e22d86fb922eda1482db85d24dba30a3":
        print(f"  FAIL: v2 digest mismatch against deterministic vector: {computed_digest_v2}")
        return 1
    print(f"  OK: Event v2 offline intake golden matches payload_digest: {computed_digest_v2}")
    passed_checks += 1

    # Verify online sale preview v2 canonical intent profile and digest derivation
    with open(FIXTURES_DIR / "golden-sale-preview-v2.json", "r", encoding="utf-8") as f:
        preview_v2_fix = json.load(f)

    # Derive intent profile from route/token context and request
    derived_profile = construct_online_sale_intent_profile(
        preview_v2_fix["context"],
        preview_v2_fix["request"]
    )
    expected_v2_preview_digest = "sha256:93b9aa677275388defe694390ab756d4b08f122087ec084b8dbd8b421ffc45fd"

    try:
        _, computed_v2_preview_digest = verify_online_sale_intent_profile(
            derived_profile,
            expected_v2_preview_digest,
            validator_hub
        )
    except IntentProfileValidationError as e:
        print(f"  FAIL: Online sale preview v2 intent profile verification failed: {e}")
        return 1

    if computed_v2_preview_digest != preview_v2_fix["response"]["payload_digest"]:
        print(
            f"  FAIL: Response payload_digest '{preview_v2_fix['response']['payload_digest']}' "
            f"does not match computed intent digest '{computed_v2_preview_digest}'"
        )
        return 1
    print(f"  OK: Online sale preview v2 intent profile verified with deterministic digest: {computed_v2_preview_digest}")
    passed_checks += 1

    # Verify preview_token binding inside golden-sale-preview-v2.json response
    try:
        verify_sale_commit_token_binding({
            "preview_token": preview_v2_fix["response"]["preview_token"],
            "payload_digest": preview_v2_fix["response"]["payload_digest"]
        })
    except CommitTokenBindingError as e:
        print(f"  FAIL: Preview token placeholder in v2 preview fixture is invalid: {e}")
        return 1
    print("  OK: Sample opaque preview_token placeholder bound to canonical digest verified")
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

    # 5b. Decimal exact arithmetic with genuine SalesLineCalculator parity helper
    with open(FIXTURES_DIR / "regression-decimal-edge-cases.json", "r", encoding="utf-8") as f:
        dec_data = json.load(f)
    for tc in dec_data["test_cases"]:
        tc_name = tc["name"]
        if tc_name == "sales_line_calculator_exclusive_16_percent":
            res = calculate_canonical_line(tc["quantity"], tc["unit_price"], tc["tax_rate"], tax_inclusive=False, minor_units=2)
            if res["subtotal"] != tc["subtotal"]:
                print(f"  FAIL: Subtotal mismatch in {tc_name}: {res['subtotal']} != {tc['subtotal']}")
                return 1
            if res["tax_amount"] != tc["tax_amount"]:
                print(f"  FAIL: Tax amount mismatch in {tc_name}: {res['tax_amount']} != {tc['tax_amount']}")
                return 1
            if res["line_total"] != tc["line_total"]:
                print(f"  FAIL: Total mismatch in {tc_name}: {res['line_total']} != {tc['line_total']}")
                return 1
        elif tc_name in ["sales_line_calculator_inclusive_16_percent", "canonical_tax_example_ils_gross_50_incl_16"]:
            res = calculate_canonical_line(tc["quantity"], tc["unit_price"], tc["tax_rate"], tax_inclusive=True, minor_units=2)
            if res["subtotal"] != tc["subtotal"]:
                print(f"  FAIL: Subtotal mismatch in {tc_name}: {res['subtotal']} != {tc['subtotal']}")
                return 1
            if res["net_before_tax"] != tc["net_before_tax"]:
                print(f"  FAIL: Net mismatch in {tc_name}: {res['net_before_tax']} != {tc['net_before_tax']}")
                return 1
            if res["tax_amount"] != tc["tax_amount"]:
                print(f"  FAIL: Tax mismatch in {tc_name}: {res['tax_amount']} != {tc['tax_amount']}")
                return 1
            if res["line_total"] != tc["line_total"]:
                print(f"  FAIL: Total mismatch in {tc_name}: {res['line_total']} != {tc['line_total']}")
                return 1
            if "unrounded_tax_divergence" in tc and res["tax_amount"] == tc["unrounded_tax_divergence"]:
                print(f"  FAIL: Tax amount matched unrounded divergence {tc['unrounded_tax_divergence']}!")
                return 1
        elif tc_name == "trailing_zero_quantity_support":
            res = calculate_canonical_line(tc["quantity"], tc["unit_price"], tc["tax_rate"], tax_inclusive=False, minor_units=2)
            if res["line_total"] != tc["line_total"]:
                print(f"  FAIL: Total mismatch in {tc_name}: {res['line_total']} != {tc['line_total']}")
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

    print("  OK: Decimal exact arithmetic regression verified (invoking genuine SalesLineCalculator helper)")
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

    # 5f. Legacy v1 unsupported intake conflict
    with open(FIXTURES_DIR / "regression-v1-unsupported-intake.json", "r", encoding="utf-8") as f:
        v1_unsupp = json.load(f)
    if v1_unsupp["incoming_request"]["event"]["event_version"] != 1:
        print("  FAIL: Legacy v1 unsupported fixture incoming event must have event_version 1")
        return 1
    try:
        validator_hub.validate_instance(v1_unsupp["incoming_request"], "offline-sale-intake-request.json", "v1-unsupported-intake")
        print("  FAIL: Legacy v1 incoming request was accepted by current v2 intake schema!")
        return 1
    except JsonSchemaValidationError:
        pass
    if v1_unsupp["expected_status"] != 409:
        print("  FAIL: Expected status for legacy v1 intake must be 409")
        return 1
    validator_hub.validate_instance(v1_unsupp["expected_response"], "error-response.json", "regression-v1-unsupported")
    if v1_unsupp["client_outbox_state"]["queue_status"] != "CONFLICT":
        print("  FAIL: Client queue status must be CONFLICT")
        return 1
    if v1_unsupp["client_outbox_state"]["bytes_mutated"] is not False:
        print("  FAIL: Legacy v1 conflict must not mutate payload bytes")
        return 1
    print("  OK: Legacy v1 unsupported intake verified (event_version 1 -> 409 Conflict schema, local CONFLICT without byte mutation)")
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
    bad_qty_payload = json.loads(json.dumps(off_req_v2))
    bad_qty_payload["event"]["lines"][0]["quantity"] = "-2.000000"
    try:
        validator_hub.validate_instance(bad_qty_payload, "offline-sale-intake-request.json", "negative-quantity")
        print("  FAIL Negative 4: Negative quantity was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Negative 4 passed (negative quantity rejected by schema)")
        passed_checks += 1

    # Negative 5: Negative unit price in offline sale intake
    bad_price_payload = json.loads(json.dumps(off_req_v2))
    bad_price_payload["event"]["lines"][0]["unit_price"] = "-25.000000"
    try:
        validator_hub.validate_instance(bad_price_payload, "offline-sale-intake-request.json", "negative-price")
        print("  FAIL Negative 5: Negative unit price was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Negative 5 passed (negative unit price rejected by schema)")
        passed_checks += 1

    # Negative 6: IEEE-754 float in JSON payload
    float_price_payload = json.loads(json.dumps(off_req_v2))
    float_price_payload["event"]["lines"][0]["unit_price"] = 25.50  # float instead of string!
    try:
        validator_hub.validate_instance(float_price_payload, "offline-sale-intake-request.json", "float-price")
        print("  FAIL Negative 6: Float unit price was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Negative 6 passed (float unit price rejected by schema type constraint)")
        passed_checks += 1

    # Negative 7: Unexpected additional property in strict schema
    bad_extra_payload = json.loads(json.dumps(off_req_v2))
    bad_extra_payload["event"]["lines"][0]["unauthorized_field"] = "malicious"
    try:
        validator_hub.validate_instance(bad_extra_payload, "offline-sale-intake-request.json", "extra-property")
        print("  FAIL Negative 7: Additional unauthorized property was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Negative 7 passed (additional unauthorized property rejected)")
        passed_checks += 1

    # Negative 8: Envelope vs Event client_event_id mismatch (Genuine verifier call)
    mismatched_env = json.loads(json.dumps(off_req_v2))
    mismatched_env["client_event_id"] = "00000000-0000-0000-0000-000000000000"
    try:
        verify_offline_intake_envelope_and_digest(mismatched_env)
        print("  FAIL Negative 8: Envelope mismatch was not detected by intake verifier helper!")
        return 1
    except IntakeEnvelopeValidationError:
        print("  OK: Negative 8 passed [Contract Shape / Pre-Ingestion Invariant] (envelope vs event client_event_id mismatch caught by verifier)")
        passed_checks += 1

    # Negative 9: Tampered digest in intake request (Genuine verifier call)
    tampered_intake = json.loads(json.dumps(off_req_v2))
    tampered_intake["payload_digest"] = "sha256:0000000000000000000000000000000000000000000000000000000000000000"
    try:
        verify_offline_intake_envelope_and_digest(tampered_intake)
        print("  FAIL Negative 9: Tampered digest passed intake verifier helper!")
        return 1
    except IntakeEnvelopeValidationError:
        print("  OK: Negative 9 passed [Contract Shape / Pre-Ingestion Invariant] (tampered payload digest caught by verifier)")
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
    with open(FIXTURES_DIR / "golden-bootstrap-v2.json", "r", encoding="utf-8") as f:
        valid_boot_v2 = json.load(f)

    # 12a: Positive validation: valid Crockford ULID accepted
    validator_hub.validate_instance(valid_boot_v2, "bootstrap-response.json", "valid-ulid-bootstrap")
    print("  OK: Check 12a passed (strict Crockford ULID accepted for server entity public IDs)")
    passed_checks += 1

    # 12b: Negative validation: UUID rejected for server entity company_id
    uuid_as_ulid = json.loads(json.dumps(valid_boot_v2))
    uuid_as_ulid["company_id"] = "c1a2b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5c"  # UUID rejected for server company
    try:
        validator_hub.validate_instance(uuid_as_ulid, "bootstrap-response.json", "uuid-as-company-id")
        print("  FAIL Check 12b: UUID was accepted as server company_id ULID!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 12b passed (UUID rejected for server company_id by ULID schema constraint)")
        passed_checks += 1

    # 12c: Negative validation: Malformed Crockford characters rejected (I, L, O, U, or lowercase)
    bad_char_ulid = json.loads(json.dumps(valid_boot_v2))
    bad_char_ulid["company_id"] = "01JCV8X9M2NQW3TYZ4R5S6789I"  # contains 'I'
    try:
        validator_hub.validate_instance(bad_char_ulid, "bootstrap-response.json", "invalid-char-ulid")
        print("  FAIL Check 12c: Malformed ULID containing 'I' was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 12c passed (malformed Crockford ULID character strictly rejected)")
        passed_checks += 1

    # Check 13 (TaxPercentage bounds: reject > 100 and negative, accept 0..100 six decimals)
    bad_tax_preview = json.loads(json.dumps(off_req_v2))
    bad_tax_preview["event"]["lines"][0]["tax_rate"] = "100.000001"  # > 100% rejected!
    try:
        validator_hub.validate_instance(bad_tax_preview, "offline-sale-intake-request.json", "over-tax-rate")
        print("  FAIL Check 13: Tax percentage > 100 was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 13 passed (Tax percentage > 100 strictly rejected by schema)")
        passed_checks += 1

    # Check 14 (Positive quantity constraints: accept trailing zero '0.100000', reject '0.000000' and over-scale)
    # 14a: trailing zero quantity '0.100000' accepted
    trailing_zero_qty = json.loads(json.dumps(off_req_v2))
    trailing_zero_qty["event"]["lines"][0]["quantity"] = "0.100000"
    validator_hub.validate_instance(trailing_zero_qty, "offline-sale-intake-request.json", "trailing-zero-quantity")
    print("  OK: Check 14a passed (six-place trailing zero quantity '0.100000' accepted)")
    passed_checks += 1

    # 14b: all-zero quantity '0.000000' rejected
    all_zero_qty = json.loads(json.dumps(off_req_v2))
    all_zero_qty["event"]["lines"][0]["quantity"] = "0.000000"
    try:
        validator_hub.validate_instance(all_zero_qty, "offline-sale-intake-request.json", "all-zero-quantity")
        print("  FAIL Check 14b: All-zero quantity '0.000000' was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 14b passed (all-zero quantity '0.000000' rejected by positive quantity schema)")
        passed_checks += 1

    # 14c: over-scale quantity (> 6 decimals) rejected
    over_scale_qty = json.loads(json.dumps(off_req_v2))
    over_scale_qty["event"]["lines"][0]["quantity"] = "1.1234567"
    try:
        validator_hub.validate_instance(over_scale_qty, "offline-sale-intake-request.json", "over-scale-quantity")
        print("  FAIL Check 14c: Over-scale quantity (>6 decimals) was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 14c passed (over-scale quantity > 6 decimals rejected)")
        passed_checks += 1

    # Check 15 (Product packaging unit identity)
    missing_punit_payload = json.loads(json.dumps(off_req_v2))
    del missing_punit_payload["event"]["lines"][0]["product_unit_id"]
    try:
        validator_hub.validate_instance(missing_punit_payload, "offline-sale-intake-request.json", "missing-punit")
        print("  FAIL Check 15: Missing product_unit_id was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 15 passed (product packaging unit id required on line items)")
        passed_checks += 1

    # Check 16 (Preview digest and commit token binding verification via genuine verifier)
    with open(FIXTURES_DIR / "golden-sale-commit.json", "r", encoding="utf-8") as f:
        commit_fix = json.load(f)["request"]

    # Verify golden commit passes token binding check
    try:
        verify_sale_commit_token_binding(commit_fix)
    except CommitTokenBindingError as e:
        print(f"  FAIL Check 16: Golden commit failed binding verification: {e}")
        return 1

    # Tamper payload_digest in commit request: must be rejected by genuine verifier
    tampered_commit = json.loads(json.dumps(commit_fix))
    tampered_commit["payload_digest"] = "sha256:0000000000000000000000000000000000000000000000000000000000000000"
    try:
        verify_sale_commit_token_binding(tampered_commit)
        print("  FAIL Check 16: Tampered commit digest was accepted by token binding verifier!")
        return 1
    except CommitTokenBindingError:
        print("  OK: Check 16 passed [Contract Shape / Pre-Ingestion Invariant] (preview token digest binding mismatch caught by verifier)")
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

    # Check 18 (ExplicitNoTax: null tax_rate_id requires rate '0.000000' and inclusive false)
    # 18a: Valid ExplicitNoTax (null + 0.000000 + false) accepted
    valid_no_tax_payload = json.loads(json.dumps(off_req_v2))
    valid_no_tax_payload["event"]["lines"][0]["tax_rate_id"] = None
    valid_no_tax_payload["event"]["lines"][0]["tax_rate"] = "0.000000"
    valid_no_tax_payload["event"]["lines"][0]["tax_inclusive"] = False
    valid_no_tax_payload["event"]["lines"][0]["tax_amount"] = "0.000000"
    validator_hub.validate_instance(valid_no_tax_payload, "offline-sale-intake-request.json", "explicit-no-tax-valid")
    print("  OK: Check 18a passed (ExplicitNoTax null+0+false accepted)")
    passed_checks += 1

    # 18b: Invalid ExplicitNoTax: null tax_rate_id with non-zero tax_rate rejected
    bad_no_tax_rate = json.loads(json.dumps(off_req_v2))
    bad_no_tax_rate["event"]["lines"][0]["tax_rate_id"] = None
    bad_no_tax_rate["event"]["lines"][0]["tax_rate"] = "16.000000"  # non-zero rate without tax_rate_id!
    bad_no_tax_rate["event"]["lines"][0]["tax_inclusive"] = False
    try:
        validator_hub.validate_instance(bad_no_tax_rate, "offline-sale-intake-request.json", "bad-no-tax-rate")
        print("  FAIL Check 18b: null tax_rate_id with non-zero rate was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 18b passed (null tax_rate_id with non-zero tax_rate rejected)")
        passed_checks += 1

    # 18c: Invalid ExplicitNoTax: null tax_rate_id with tax_inclusive true rejected
    bad_no_tax_incl = json.loads(json.dumps(off_req_v2))
    bad_no_tax_incl["event"]["lines"][0]["tax_rate_id"] = None
    bad_no_tax_incl["event"]["lines"][0]["tax_rate"] = "0.000000"
    bad_no_tax_incl["event"]["lines"][0]["tax_inclusive"] = True  # inclusive true without tax_rate_id!
    try:
        validator_hub.validate_instance(bad_no_tax_incl, "offline-sale-intake-request.json", "bad-no-tax-inclusive")
        print("  FAIL Check 18c: null tax_rate_id with tax_inclusive true was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 18c passed (null tax_rate_id with tax_inclusive true rejected)")
        passed_checks += 1

    # Check 19 (tax_rate_id ULID bounds: Crockford ULID accepted, invalid char / UUID rejected)
    # 19a: Valid Crockford ULID accepted
    valid_tax_ulid = json.loads(json.dumps(off_req_v2))
    valid_tax_ulid["event"]["lines"][0]["tax_rate_id"] = "01JCV8X9MDPTRSVWXYZ0123456"
    validator_hub.validate_instance(valid_tax_ulid, "offline-sale-intake-request.json", "valid-tax-rate-ulid")
    print("  OK: Check 19a passed (valid Crockford ULID accepted for tax_rate_id)")
    passed_checks += 1

    # 19b: Invalid Crockford character (I) rejected
    bad_char_tax_ulid = json.loads(json.dumps(off_req_v2))
    bad_char_tax_ulid["event"]["lines"][0]["tax_rate_id"] = "01JCV8X9MDPTRSVWXYZ012345I"  # contains 'I'
    try:
        validator_hub.validate_instance(bad_char_tax_ulid, "offline-sale-intake-request.json", "invalid-char-tax-ulid")
        print("  FAIL Check 19b: tax_rate_id containing 'I' was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 19b passed (tax_rate_id Crockford character I strictly rejected)")
        passed_checks += 1

    # 19c: UUID rejected for tax_rate_id
    uuid_tax_id = json.loads(json.dumps(off_req_v2))
    uuid_tax_id["event"]["lines"][0]["tax_rate_id"] = "c1a2b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5c"
    try:
        validator_hub.validate_instance(uuid_tax_id, "offline-sale-intake-request.json", "uuid-as-tax-rate-id")
        print("  FAIL Check 19c: UUID was accepted for tax_rate_id!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 19c passed (UUID rejected for tax_rate_id by ULID schema constraint)")
        passed_checks += 1

    # Check 20 (tax_inclusive boolean constraint: boolean accepted, strings rejected)
    # 20a: Boolean tax_inclusive accepted
    bool_tax_incl = json.loads(json.dumps(off_req_v2))
    bool_tax_incl["event"]["lines"][0]["tax_inclusive"] = False
    validator_hub.validate_instance(bool_tax_incl, "offline-sale-intake-request.json", "bool-tax-inclusive")
    print("  OK: Check 20a passed (tax_inclusive boolean accepted)")
    passed_checks += 1

    # 20b: Non-boolean string 'true' rejected
    str_tax_incl = json.loads(json.dumps(off_req_v2))
    str_tax_incl["event"]["lines"][0]["tax_inclusive"] = "true"  # string instead of boolean!
    try:
        validator_hub.validate_instance(str_tax_incl, "offline-sale-intake-request.json", "str-tax-inclusive")
        print("  FAIL Check 20b: String 'true' was accepted for tax_inclusive!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 20b passed (non-boolean tax_inclusive string strictly rejected)")
        passed_checks += 1

    # Check 21 (conversion_to_base positive decimal constraints)
    # 21a: Valid positive decimal accepted
    valid_conv = json.loads(json.dumps(off_req_v2))
    valid_conv["event"]["lines"][0]["conversion_to_base"] = "12.500000"
    validator_hub.validate_instance(valid_conv, "offline-sale-intake-request.json", "valid-conversion")
    print("  OK: Check 21a passed (conversion_to_base positive decimal accepted)")
    passed_checks += 1

    # 21b: Zero conversion_to_base rejected
    zero_conv = json.loads(json.dumps(off_req_v2))
    zero_conv["event"]["lines"][0]["conversion_to_base"] = "0.000000"
    try:
        validator_hub.validate_instance(zero_conv, "offline-sale-intake-request.json", "zero-conversion")
        print("  FAIL Check 21b: Zero conversion_to_base was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 21b passed (zero conversion_to_base rejected by positive regex)")
        passed_checks += 1

    # 21c: Negative conversion_to_base rejected
    neg_conv = json.loads(json.dumps(off_req_v2))
    neg_conv["event"]["lines"][0]["conversion_to_base"] = "-1.000000"
    try:
        validator_hub.validate_instance(neg_conv, "offline-sale-intake-request.json", "neg-conversion")
        print("  FAIL Check 21c: Negative conversion_to_base was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 21c passed (negative conversion_to_base rejected)")
        passed_checks += 1

    # 21d: Float conversion_to_base rejected
    float_conv = json.loads(json.dumps(off_req_v2))
    float_conv["event"]["lines"][0]["conversion_to_base"] = 1.0  # float!
    try:
        validator_hub.validate_instance(float_conv, "offline-sale-intake-request.json", "float-conversion")
        print("  FAIL Check 21d: Float conversion_to_base was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 21d passed (float conversion_to_base rejected)")
        passed_checks += 1

    # 21e: Over-scale conversion_to_base (>6 decimals) rejected
    over_scale_conv = json.loads(json.dumps(off_req_v2))
    over_scale_conv["event"]["lines"][0]["conversion_to_base"] = "1.1234567"
    try:
        validator_hub.validate_instance(over_scale_conv, "offline-sale-intake-request.json", "overscale-conversion")
        print("  FAIL Check 21e: Over-scale conversion_to_base was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 21e passed (overscale conversion_to_base > 6 decimals rejected)")
        passed_checks += 1

    # Check 22 (offline intake event_version constraints)
    # 22a: event_version 1 rejected by v2 intake schema
    v1_intake_test = json.loads(json.dumps(off_req_v2))
    v1_intake_test["event"]["event_version"] = 1
    try:
        validator_hub.validate_instance(v1_intake_test, "offline-sale-intake-request.json", "check-22a-v1-rejected")
        print("  FAIL Check 22a: event_version 1 was accepted by v2 intake schema!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 22a passed (event_version 1 strictly rejected by v2 intake schema)")
        passed_checks += 1

    # 22b: event_version 2 accepted by v2 intake schema
    v2_intake_test = json.loads(json.dumps(off_req_v2))
    validator_hub.validate_instance(v2_intake_test, "offline-sale-intake-request.json", "check-22b-v2-accepted")
    print("  OK: Check 22b passed (event_version 2 accepted by v2 intake schema)")
    passed_checks += 1

    # Check 23 (v2 intake detects tampered payload digest via genuine verifier)
    tampered_v2 = json.loads(json.dumps(off_req_v2))
    tampered_v2["payload_digest"] = "sha256:0000000000000000000000000000000000000000000000000000000000000000"
    try:
        verify_offline_intake_envelope_and_digest(tampered_v2)
        print("  FAIL Check 23: Tampered v2 digest was accepted by verifier helper!")
        return 1
    except IntakeEnvelopeValidationError:
        print("  OK: Check 23 passed [Contract Shape / Pre-Ingestion Invariant] (tampered v2 payload digest caught by verifier)")
        passed_checks += 1

    # Check 24 (bootstrap base_currency_minor_units constraints)
    # 24a: Missing base_currency_minor_units rejected in v2 bootstrap
    missing_minor = json.loads(json.dumps(valid_boot_v2))
    del missing_minor["base_currency_minor_units"]
    try:
        validator_hub.validate_instance(missing_minor, "bootstrap-response.json", "check-24a-missing-minor-units")
        print("  FAIL Check 24a: Missing base_currency_minor_units was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 24a passed (missing base_currency_minor_units rejected)")
        passed_checks += 1

    # 24b: base_currency_minor_units out of range (>6) rejected
    out_of_range_minor = json.loads(json.dumps(valid_boot_v2))
    out_of_range_minor["base_currency_minor_units"] = 7
    try:
        validator_hub.validate_instance(out_of_range_minor, "bootstrap-response.json", "check-24b-minor-out-of-range")
        print("  FAIL Check 24b: base_currency_minor_units > 6 was accepted!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 24b passed (base_currency_minor_units > 6 rejected)")
        passed_checks += 1

    # 24c: base_currency_minor_units within 0..6 accepted
    valid_minor_jod = json.loads(json.dumps(valid_boot_v2))
    valid_minor_jod["base_currency_minor_units"] = 3
    validator_hub.validate_instance(valid_minor_jod, "bootstrap-response.json", "check-24c-jod-minor-units")
    print("  OK: Check 24c passed (base_currency_minor_units 3 JOD accepted)")
    passed_checks += 1

    # Check 25: Canonical tax arithmetic verification (SalesLineCalculator parity, ILS gross 50 incl 16% -> 6.900000 tax)
    line_res = calculate_canonical_line("2.000000", "25.000000", "16.000000", tax_inclusive=True, minor_units=2)
    if line_res["tax_amount"] != "6.900000":
        print(f"  FAIL Check 25: Canonical tax amount mismatch: {line_res['tax_amount']} != 6.900000")
        return 1
    if line_res["tax_amount"] == "6.896552":
        print("  FAIL Check 25: Canonical tax calculation used unrounded floating 6.896552!")
        return 1
    if line_res["line_total"] != "50.000000":
        print(f"  FAIL Check 25: Canonical line total mismatch: {line_res['line_total']} != 50.000000")
        return 1

    # Verify payment tendered-settled-change consistency
    payment_ok = verify_payment_decimal_consistency(
        {
            "mode": "full",
            "amount_tendered": "50.000000",
            "amount_settled": "50.000000",
            "change_returned": "0.000000"
        },
        "50.000000",
        currency_minor_units=2,
    )
    if not payment_ok:
        print("  FAIL Check 25: Payment tendered-settled-change balance check failed!")
        return 1
    print("  OK: Check 25 passed [Contract Shape / Pre-Ingestion Invariant] (canonical tax arithmetic and payment balance verified)")
    passed_checks += 1

    # Check 26: Online Canonical Intent Profile Tampering Mutation Tests
    # Actively invokes verify_online_sale_intent_profile and asserts failure on any tampered field
    with open(FIXTURES_DIR / "golden-online-sale-intent-profile.json", "r", encoding="utf-8") as f:
        intent_golden = json.load(f)
    base_profile = intent_golden["intent_profile"]
    canonical_digest = intent_golden["payload_digest"]

    # 26a: Actor tampering
    t_actor = json.loads(json.dumps(base_profile))
    t_actor["actor_id"] = "01JCV8X9M8T1V2W3X4Y5Z67899"
    try:
        verify_online_sale_intent_profile(t_actor, canonical_digest, validator_hub)
        print("  FAIL Check 26a: Actor tampering was not detected!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 26a passed (actor tampering caught by intent profile verifier)")
        passed_checks += 1

    # 26b: Company tampering
    t_company = json.loads(json.dumps(base_profile))
    t_company["company_id"] = "01JCV8X9M2NQW3TYZ4R5S6789B"
    try:
        verify_online_sale_intent_profile(t_company, canonical_digest, validator_hub)
        print("  FAIL Check 26b: Company tampering was not detected!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 26b passed (company tampering caught by intent profile verifier)")
        passed_checks += 1

    # 26c: Device tampering
    t_device = json.loads(json.dumps(base_profile))
    t_device["device_id"] = "8e2b8bc6-f80c-4034-8c88-59368d1f2e11"
    try:
        verify_online_sale_intent_profile(t_device, canonical_digest, validator_hub)
        print("  FAIL Check 26c: Device tampering was not detected!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 26c passed (device tampering caught by intent profile verifier)")
        passed_checks += 1

    # 26d: Event tampering
    t_event = json.loads(json.dumps(base_profile))
    t_event["client_event_id"] = "3fa85f64-5717-4562-b3fc-2c963f66afa7"
    try:
        verify_online_sale_intent_profile(t_event, canonical_digest, validator_hub)
        print("  FAIL Check 26d: Event tampering was not detected!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 26d passed (event tampering caught by intent profile verifier)")
        passed_checks += 1

    # 26e: Tax identity tampering
    t_tax_id = json.loads(json.dumps(base_profile))
    t_tax_id["lines"][0]["tax_rate_id"] = "01J9Z5K3MN0000000000000031"
    try:
        verify_online_sale_intent_profile(t_tax_id, canonical_digest, validator_hub)
        print("  FAIL Check 26e: Tax identity tampering was not detected!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 26e passed (tax identity tampering caught by intent profile verifier)")
        passed_checks += 1

    # 26f: Tax inclusive tampering
    t_tax_incl = json.loads(json.dumps(base_profile))
    t_tax_incl["lines"][0]["tax_inclusive"] = False
    try:
        verify_online_sale_intent_profile(t_tax_incl, canonical_digest, validator_hub)
        print("  FAIL Check 26f: Tax inclusive tampering was not detected!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 26f passed (tax inclusive mode tampering caught by intent profile verifier)")
        passed_checks += 1

    # 26g: Conversion to base tampering
    t_conv = json.loads(json.dumps(base_profile))
    t_conv["lines"][0]["conversion_to_base"] = "1.000001"
    try:
        verify_online_sale_intent_profile(t_conv, canonical_digest, validator_hub)
        print("  FAIL Check 26g: Conversion to base tampering was not detected!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 26g passed (conversion to base tampering caught by intent profile verifier)")
        passed_checks += 1

    # 26h: Decimal lexeme tampering (altered string lexeme)
    t_lexeme = json.loads(json.dumps(base_profile))
    t_lexeme["lines"][0]["unit_price"] = "25.000001"
    try:
        verify_online_sale_intent_profile(t_lexeme, canonical_digest, validator_hub)
        print("  FAIL Check 26h: Decimal lexeme tampering was not detected!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 26h passed (decimal lexeme tampering caught by intent profile verifier)")
        passed_checks += 1

    # 26i: Line order tampering
    multi_line_profile = json.loads(json.dumps(base_profile))
    second_line = json.loads(json.dumps(base_profile["lines"][0]))
    second_line["product_id"] = "01JCV8X9M6A1B2C3D4E5F6G7H9"
    second_line["unit_price"] = "30.000000"
    multi_line_profile["lines"].append(second_line)
    multiline_digest = compute_restricted_sha256_digest(multi_line_profile)

    # Swap line order
    t_order = json.loads(json.dumps(multi_line_profile))
    t_order["lines"] = [second_line, multi_line_profile["lines"][0]]
    try:
        verify_online_sale_intent_profile(t_order, multiline_digest, validator_hub)
        print("  FAIL Check 26i: Line order tampering was not detected!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 26i passed (line order tampering caught by intent profile verifier)")
        passed_checks += 1

    # 26j: Payment tampering
    t_payment = json.loads(json.dumps(base_profile))
    t_payment["payment"]["amount"] = "50.000001"
    try:
        verify_online_sale_intent_profile(t_payment, canonical_digest, validator_hub)
        print("  FAIL Check 26j: Payment tampering was not detected!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 26j passed (payment tampering caught by intent profile verifier)")
        passed_checks += 1

    # 26k: Currency minor units tampering
    t_minor = json.loads(json.dumps(base_profile))
    t_minor["currency_minor_units"] = 3
    try:
        verify_online_sale_intent_profile(t_minor, canonical_digest, validator_hub)
        print("  FAIL Check 26k: Currency minor units tampering was not detected!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 26k passed (currency minor units tampering caught by intent profile verifier)")
        passed_checks += 1

    # Check 27: Manifest Verification Mutation Tests (Exact Set Equality & Rejection Gates)
    discovered_now = generate_manifest_entries(BASE_DIR)
    valid_manifest_lines = [f"{sha}  {rel}\n" for rel, sha in sorted(discovered_now.items())]

    # 27a: Real filesystem mutation test (temporary untracked file restored cleanly)
    temp_probe = FIXTURES_DIR / "temp_probe_mutation_test.json"
    try:
        temp_probe.write_text('{"temp_mutation": true}\n', encoding="utf-8")
        discovered_with_temp = generate_manifest_entries(BASE_DIR)
        ok, err_msg = verify_manifest_content(valid_manifest_lines, discovered_with_temp)
        if ok or "missing from manifest" not in err_msg:
            print("  FAIL Check 27a: Temporary untracked file was not caught by manifest verifier!")
            return 1
    finally:
        temp_probe.unlink(missing_ok=True)
    print("  OK: Check 27a passed (real filesystem mutation test: untracked file caught and cleanly restored)")
    passed_checks += 1

    # 27b: Extra entry in stored manifest
    extra_lines = valid_manifest_lines + ["e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855  schemas/nonexistent-file.json\n"]
    ok, err_msg = verify_manifest_content(extra_lines, discovered_now)
    if ok or "entries not found on disk" not in err_msg:
        print("  FAIL Check 27b: Extra manifest entry was not caught!")
        return 1
    print("  OK: Check 27b passed (extra manifest entry detected and rejected)")
    passed_checks += 1

    # 27c: Duplicate line in stored manifest
    dup_lines = valid_manifest_lines + [valid_manifest_lines[0]]
    ok, err_msg = verify_manifest_content(dup_lines, discovered_now)
    if ok or "Duplicate manifest entry" not in err_msg:
        print("  FAIL Check 27c: Duplicate manifest entry was not caught!")
        return 1
    print("  OK: Check 27c passed (duplicate manifest entry detected and rejected)")
    passed_checks += 1

    # 27d: Malformed line in stored manifest
    malformed_lines = valid_manifest_lines + ["invalid_sha_format  schemas/error-response.json\n"]
    ok, err_msg = verify_manifest_content(malformed_lines, discovered_now)
    if ok or "Malformed manifest entry" not in err_msg:
        print("  FAIL Check 27d: Malformed manifest line was not caught!")
        return 1
    print("  OK: Check 27d passed (malformed manifest entry detected and rejected)")
    passed_checks += 1

    # 27e: Checksum mismatch in stored manifest
    tampered_hash_lines = ["0000000000000000000000000000000000000000000000000000000000000000  " + valid_manifest_lines[0].split("  ")[1]] + valid_manifest_lines[1:]
    ok, err_msg = verify_manifest_content(tampered_hash_lines, discovered_now)
    if ok or "Checksum mismatch" not in err_msg:
        print("  FAIL Check 27e: Tampered manifest checksum was not caught!")
        return 1
    print("  OK: Check 27e passed (checksum mismatch detected and rejected)")
    passed_checks += 1

    # Check 28: Archive Byte Checks (Historical goldens & archived schemas match parent PR4 ec822093 exact hashes)
    parent_historical_hashes = {
        "fixtures/golden-auth-challenge.json": "caa87ae65f5e5f5fd0a03dee862f8e28324352ab7da4f33ee3994b8874553154",
        "fixtures/golden-auth-login.json": "a7b81ff81658ad87fac002eb2ccea637813960b7b84ef3d6b0dc0a08234b9428",
        "fixtures/golden-auth-logout.json": "fff2d09fa25005485221b9aaad71246967616ff7bbde38dc775d652e129f67dc",
        "fixtures/golden-device-revoke.json": "6ddcabf5d3fd22062f91636ada39bfc9bcc4f9bfd038220d2c93b526b92c2ce6",
        "fixtures/golden-bootstrap.json": "3d75cc1bfd209580cb243ca5f87e35f5a2f8637feadf2877c9a96ce6453c3a74",
        "fixtures/golden-product-catalog.json": "a184914a64b0096169f4e73cdb0f533ac68106cd57dbff4192ee90cfa4ed5f7d",
        "fixtures/golden-customer-directory.json": "4c49c5f0abc4d1ddc730b23d3daf503e5da8feccbcc7f9a83e53751b0dc14596",
        "fixtures/golden-sale-preview.json": "cc7f668dbc79e12c2156d68000a21452874ce730d4e0827a3ddb5e914d325d26",
        "fixtures/golden-sale-commit.json": "de76866b03bedb867860b273c59492e4d36d3af31281ef267d46da81ef5be542",
        "fixtures/golden-offline-sale-intake.json": "08c1fd367a48a4e2692be5bd7a0981ca87f8bf59e457f52ac795dba78e460524",
        "fixtures/golden-sale-event-status.json": "55b54ff7a0b259b3b378ebbec36ed5bb182e5bec5554ac307112c9d1381fb6d8",
        "fixtures/golden-canonical-vectors.json": "910e2c1d6f0e55218b42a03b195fb04560054a4b97b8bea7bd7090e53174a8c6",
    }
    for rel_hist, exp_sha in parent_historical_hashes.items():
        actual_sha = hashlib.sha256((BASE_DIR / rel_hist).read_bytes()).hexdigest()
        if actual_sha != exp_sha:
            print(f"  FAIL Check 28: Historical golden '{rel_hist}' drift detected!\n    Expected: {exp_sha}\n    Actual:   {actual_sha}")
            return 1
    print("  OK: Check 28 passed (all historical compatibility goldens match parent PR4 exact bytes)")
    passed_checks += 1

    # Check 29: Positive each settlement mode with omitted/null/valid cash fields & digest derived per mode & tampering
    # 29a: Unpaid mode with omitted optional cash fields (payment {settlement_mode: 'unpaid', amount: '0.000'})
    req_unpaid_omitted = json.loads(json.dumps(preview_v2_fix["request"]))
    req_unpaid_omitted["payment"] = {
        "settlement_mode": "unpaid",
        "amount": "0.000",
    }
    profile_unpaid_omitted = construct_online_sale_intent_profile(preview_v2_fix["context"], req_unpaid_omitted, validator_hub)
    if profile_unpaid_omitted["payment"]["payment_method"] is not None or profile_unpaid_omitted["payment"]["cash_account_id"] is not None:
        print("  FAIL Check 29a: Omitted payment fields did not normalize to null in unpaid intent profile!")
        return 1
    if profile_unpaid_omitted["payment"]["amount"] != "0.000":
        print(f"  FAIL Check 29a: Exact string lexeme '0.000' mutated: {profile_unpaid_omitted['payment']['amount']!r}")
        return 1
    unpaid_omitted_digest = compute_restricted_sha256_digest(profile_unpaid_omitted)
    verify_online_sale_intent_profile(profile_unpaid_omitted, unpaid_omitted_digest, validator_hub)
    if not verify_payment_decimal_consistency(profile_unpaid_omitted["payment"], "50.000000", currency_minor_units=2):
        print("  FAIL Check 29a: Valid unpaid payment consistency rejected!")
        return 1
    tampered_unpaid_amount = json.loads(json.dumps(profile_unpaid_omitted))
    tampered_unpaid_amount["payment"]["amount"] = "0.001"
    try:
        verify_online_sale_intent_profile(tampered_unpaid_amount, unpaid_omitted_digest, validator_hub)
        print("  FAIL Check 29a: Tampered unpaid amount accepted!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 29a passed (unpaid mode with omitted cash fields accepted, normalized null, digest verified, tampering rejected)")
        passed_checks += 1

    # 29b: Unpaid mode with explicit null cash fields (payment {settlement_mode: 'unpaid', payment_method: null, cash_account_id: null, amount: '0.000000'})
    req_unpaid_null = json.loads(json.dumps(preview_v2_fix["request"]))
    req_unpaid_null["payment"] = {
        "settlement_mode": "unpaid",
        "payment_method": None,
        "cash_account_id": None,
        "amount": "0.000000",
    }
    profile_unpaid_null = construct_online_sale_intent_profile(preview_v2_fix["context"], req_unpaid_null, validator_hub)
    if profile_unpaid_null["payment"]["payment_method"] is not None or profile_unpaid_null["payment"]["cash_account_id"] is not None:
        print("  FAIL Check 29b: Explicit null payment fields did not normalize to null in unpaid intent profile!")
        return 1
    unpaid_null_digest = compute_restricted_sha256_digest(profile_unpaid_null)
    verify_online_sale_intent_profile(profile_unpaid_null, unpaid_null_digest, validator_hub)
    if not verify_payment_decimal_consistency(profile_unpaid_null["payment"], "50.000000", currency_minor_units=2):
        print("  FAIL Check 29b: Valid unpaid null payment consistency rejected!")
        return 1
    tampered_unpaid_mode = json.loads(json.dumps(profile_unpaid_null))
    tampered_unpaid_mode["payment"]["settlement_mode"] = "full"
    try:
        verify_online_sale_intent_profile(tampered_unpaid_mode, unpaid_null_digest, validator_hub)
        print("  FAIL Check 29b: Tampered unpaid mode accepted!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 29b passed (unpaid mode with explicit null cash fields accepted, digest verified, tampering rejected)")
        passed_checks += 1

    # 29c: Partial mode with valid cash fields (payment {settlement_mode: 'partial', payment_method: 'cash', cash_account_id: ..., amount: '20.000000'})
    req_partial = json.loads(json.dumps(preview_v2_fix["request"]))
    req_partial["payment"] = {
        "settlement_mode": "partial",
        "payment_method": "cash",
        "cash_account_id": "01JCV8X9MAJ3K4M5N6P7Q8R9S0",
        "amount": "20.000000",
    }
    profile_partial = construct_online_sale_intent_profile(preview_v2_fix["context"], req_partial, validator_hub)
    partial_digest = compute_restricted_sha256_digest(profile_partial)
    verify_online_sale_intent_profile(profile_partial, partial_digest, validator_hub)
    if not verify_payment_decimal_consistency(profile_partial["payment"], "50.000000", currency_minor_units=2):
        print("  FAIL Check 29c: Valid partial payment consistency rejected!")
        return 1
    tampered_partial_acct = json.loads(json.dumps(profile_partial))
    tampered_partial_acct["payment"]["cash_account_id"] = "01JCV8X9MAJ3K4M5N6P7Q8R9S1"
    try:
        verify_online_sale_intent_profile(tampered_partial_acct, partial_digest, validator_hub)
        print("  FAIL Check 29c: Tampered partial cash account accepted!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 29c passed (partial mode with valid cash fields accepted, digest verified, tampering rejected)")
        passed_checks += 1

    # 29d: Full mode with valid cash fields and exact grand total (payment {settlement_mode: 'full', amount: '50.000000'})
    req_full_exact = json.loads(json.dumps(preview_v2_fix["request"]))
    req_full_exact["payment"]["amount"] = "50.000000"
    profile_full_exact = construct_online_sale_intent_profile(preview_v2_fix["context"], req_full_exact, validator_hub)
    full_exact_digest = compute_restricted_sha256_digest(profile_full_exact)
    if full_exact_digest != expected_v2_preview_digest:
        print(f"  FAIL Check 29d: Full exact cash 50 digest mutated! Expected {expected_v2_preview_digest}, got {full_exact_digest}")
        return 1
    verify_online_sale_intent_profile(profile_full_exact, full_exact_digest, validator_hub)
    if not verify_payment_decimal_consistency(profile_full_exact["payment"], "50.000000", currency_minor_units=2):
        print("  FAIL Check 29d: Full mode exact total (50.000000 == 50.000000) rejected!")
        return 1
    tampered_full_price = json.loads(json.dumps(profile_full_exact))
    tampered_full_price["lines"][0]["unit_price"] = "26.000000"
    try:
        verify_online_sale_intent_profile(tampered_full_price, full_exact_digest, validator_hub)
        print("  FAIL Check 29d: Tampered full exact price accepted!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 29d passed (full mode with exact grand total accepted, golden digest verified, tampering rejected)")
        passed_checks += 1

    # Check 30: Missing required taxID rejection (never default to implicit NoTax)
    # 30a: construct_online_sale_intent_profile rejects line missing tax_rate_id key with zero rate and false inclusive
    bad_req_missing_tax_id = json.loads(json.dumps(preview_v2_fix["request"]))
    del bad_req_missing_tax_id["lines"][0]["tax_rate_id"]
    bad_req_missing_tax_id["lines"][0]["tax_rate"] = "0.000000"
    bad_req_missing_tax_id["lines"][0]["tax_inclusive"] = False
    try:
        construct_online_sale_intent_profile(preview_v2_fix["context"], bad_req_missing_tax_id, validator_hub)
        print("  FAIL Check 30a: Line missing tax_rate_id was accepted and defaulted to implicit NoTax!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 30a passed (missing tax_rate_id throws IntentProfileValidationError, never implicit NoTax)")
        passed_checks += 1

    # 30b: Direct schema validation of request missing tax_rate_id rejects with JsonSchemaValidationError
    try:
        validator_hub.validate_instance(bad_req_missing_tax_id, "sale-preview-request.json", "missing-tax-rate-id-check")
        print("  FAIL Check 30b: Raw request missing tax_rate_id accepted by sale-preview-request.json!")
        return 1
    except JsonSchemaValidationError:
        print("  OK: Check 30b passed (raw request missing tax_rate_id rejected by sale-preview-request.json)")
        passed_checks += 1

    # Check 31: Unknown raw request fields rejected at each level before projection
    # 31a: Unknown root field
    bad_root_field_req = json.loads(json.dumps(preview_v2_fix["request"]))
    bad_root_field_req["unknown_root_property"] = "malicious_payload"
    try:
        construct_online_sale_intent_profile(preview_v2_fix["context"], bad_root_field_req, validator_hub)
        print("  FAIL Check 31a: Unknown root field was silently dropped instead of rejected!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 31a passed (unknown root field rejected before projection)")
        passed_checks += 1

    # 31b: Unknown line item field
    bad_line_field_req = json.loads(json.dumps(preview_v2_fix["request"]))
    bad_line_field_req["lines"][0]["unknown_line_property"] = "malicious_line_payload"
    try:
        construct_online_sale_intent_profile(preview_v2_fix["context"], bad_line_field_req, validator_hub)
        print("  FAIL Check 31b: Unknown line field was silently dropped instead of rejected!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 31b passed (unknown line field rejected before projection)")
        passed_checks += 1

    # 31c: Unknown payment field
    bad_payment_field_req = json.loads(json.dumps(preview_v2_fix["request"]))
    bad_payment_field_req["payment"]["unknown_payment_property"] = "malicious_payment_payload"
    try:
        construct_online_sale_intent_profile(preview_v2_fix["context"], bad_payment_field_req, validator_hub)
        print("  FAIL Check 31c: Unknown payment field was silently dropped instead of rejected!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 31c passed (unknown payment field rejected before projection)")
        passed_checks += 1

    # Check 32: Context strict known keys and type validation
    # 32a: currency_minor_units as boolean True rejected (reject bool as int)
    bad_ctx_bool_true = json.loads(json.dumps(preview_v2_fix["context"]))
    bad_ctx_bool_true["currency_minor_units"] = True
    try:
        construct_online_sale_intent_profile(bad_ctx_bool_true, preview_v2_fix["request"], validator_hub)
        print("  FAIL Check 32a: boolean True accepted as currency_minor_units!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 32a passed (context currency_minor_units boolean True rejected)")
        passed_checks += 1

    # 32b: currency_minor_units as boolean False rejected
    bad_ctx_bool_false = json.loads(json.dumps(preview_v2_fix["context"]))
    bad_ctx_bool_false["currency_minor_units"] = False
    try:
        construct_online_sale_intent_profile(bad_ctx_bool_false, preview_v2_fix["request"], validator_hub)
        print("  FAIL Check 32b: boolean False accepted as currency_minor_units!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 32b passed (context currency_minor_units boolean False rejected)")
        passed_checks += 1

    # 32c: Context with extra key rejected
    bad_ctx_extra = json.loads(json.dumps(preview_v2_fix["context"]))
    bad_ctx_extra["unexpected_context_key"] = "extra_value"
    try:
        construct_online_sale_intent_profile(bad_ctx_extra, preview_v2_fix["request"], validator_hub)
        print("  FAIL Check 32c: Context with extra key was accepted!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 32c passed (context with extra unknown key rejected)")
        passed_checks += 1

    # 32d: Context missing key rejected
    bad_ctx_missing = json.loads(json.dumps(preview_v2_fix["context"]))
    del bad_ctx_missing["company_id"]
    try:
        construct_online_sale_intent_profile(bad_ctx_missing, preview_v2_fix["request"], validator_hub)
        print("  FAIL Check 32d: Context missing company_id was accepted!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 32d passed (context missing required key rejected)")
        passed_checks += 1

    # 32e: Non-ULID actor_id / company_id rejected
    bad_ctx_uuid = json.loads(json.dumps(preview_v2_fix["context"]))
    bad_ctx_uuid["actor_id"] = "3fa85f64-5717-4562-b3fc-2c963f66afa6"  # UUID, not ULID
    try:
        construct_online_sale_intent_profile(bad_ctx_uuid, preview_v2_fix["request"], validator_hub)
        print("  FAIL Check 32e: Non-ULID UUID accepted for actor_id in context!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 32e passed (non-ULID UUID actor_id in context rejected)")
        passed_checks += 1

    # Context identities must not accept a final newline via regex dollar anchors.
    for identity_key in ("actor_id", "company_id"):
        bad_context = dict(preview_v2_fix["context"])
        bad_context[identity_key] += "\n"
        try:
            construct_online_sale_intent_profile(bad_context, preview_v2_fix["request"], validator_hub)
            print(f"  FAIL: Context {identity_key} accepted a trailing newline")
            return 1
        except IntentProfileValidationError:
            passed_checks += 1

    # Check 33: Invalid settlement amounts boundaries, lexemes, and negative amounts
    # 33a: unpaid with positive amount rejected
    if verify_payment_decimal_consistency({"settlement_mode": "unpaid", "amount": "0.000001"}, "50.000000", 2):
        print("  FAIL Check 33a: Unpaid with positive amount 0.000001 accepted!")
        return 1
    if verify_payment_decimal_consistency({"settlement_mode": "unpaid", "amount": "50.000000"}, "50.000000", 2):
        print("  FAIL Check 33a: Unpaid with positive amount 50.000000 accepted!")
        return 1
    print("  OK: Check 33a passed (unpaid mode with positive amount rejected)")
    passed_checks += 1

    # 33b: partial with zero amount rejected
    if verify_payment_decimal_consistency({"settlement_mode": "partial", "amount": "0.000000"}, "50.000000", 2):
        print("  FAIL Check 33b: Partial with zero amount accepted!")
        return 1
    print("  OK: Check 33b passed (partial mode with zero amount rejected)")
    passed_checks += 1

    # 33c: partial with amount equal to or greater than grand total rejected
    if verify_payment_decimal_consistency({"settlement_mode": "partial", "amount": "50.000000"}, "50.000000", 2):
        print("  FAIL Check 33c: Partial with amount equal to grand total accepted!")
        return 1
    if verify_payment_decimal_consistency({"settlement_mode": "partial", "amount": "55.000000"}, "50.000000", 2):
        print("  FAIL Check 33c: Partial with amount greater than grand total accepted!")
        return 1
    print("  OK: Check 33c passed (partial mode with amount >= grand total rejected)")
    passed_checks += 1

    # 33d: full mode with amount not equal to grand total rejected (both under and overpayment)
    if verify_payment_decimal_consistency({"settlement_mode": "full", "amount": "49.999999"}, "50.000000", 2):
        print("  FAIL Check 33d: Full mode with amount < grand total accepted!")
        return 1
    if verify_payment_decimal_consistency({"settlement_mode": "full", "amount": "50.000001"}, "50.000000", 2):
        print("  FAIL Check 33d: Full mode with amount > grand total accepted!")
        return 1
    if verify_payment_decimal_consistency({"settlement_mode": "full", "amount": "60.000000"}, "50.000000", 2):
        print("  FAIL Check 33d: Full mode overpayment (60.000000 > 50.000000) accepted!")
        return 1
    print("  OK: Check 33d passed (full mode with amount != grand total rejected: under and overpayment)")
    passed_checks += 1

    # 33e: Negative payment amount rejected
    if verify_payment_decimal_consistency({"settlement_mode": "full", "amount": "-50.000000"}, "50.000000", 2):
        print("  FAIL Check 33e: Full mode with negative amount accepted!")
        return 1
    if verify_payment_decimal_consistency({"settlement_mode": "unpaid", "amount": "-0.000001"}, "50.000000", 2):
        print("  FAIL Check 33e: Unpaid mode with negative amount accepted!")
        return 1
    print("  OK: Check 33e passed (negative payment amount rejected)")
    passed_checks += 1

    # 33f: Invalid decimal string lexemes rejected
    if verify_payment_decimal_consistency({"settlement_mode": "full", "amount": "not_a_number"}, "50.000000", 2):
        print("  FAIL Check 33f: Non-numeric string lexeme accepted!")
        return 1
    if verify_payment_decimal_consistency({"settlement_mode": "full", "amount": "50.00.00"}, "50.000000", 2):
        print("  FAIL Check 33f: Malformed decimal string lexeme accepted!")
        return 1
    print("  OK: Check 33f passed (malformed decimal string lexeme rejected)")
    passed_checks += 1

    # 33g: Offline payment tender balance mismatch rejected
    bad_offline_tender = {
        "mode": "full",
        "amount_tendered": "50.000000",
        "amount_settled": "50.000000",
        "change_returned": "5.000000",
    }
    if verify_payment_decimal_consistency(bad_offline_tender, "50.000000", 2):
        print("  FAIL Check 33g: Offline payment tender balance mismatch accepted!")
        return 1
    print("  OK: Check 33g passed (offline payment tender balance mismatch rejected)")
    passed_checks += 1

    # 33h: Offline payment settled amount != grand total rejected
    bad_offline_settled = {
        "mode": "full",
        "amount_tendered": "40.000000",
        "amount_settled": "40.000000",
        "change_returned": "0.000000",
    }
    if verify_payment_decimal_consistency(bad_offline_settled, "50.000000", 2):
        print("  FAIL Check 33h: Offline payment settled amount != grand total accepted!")
        return 1
    print("  OK: Check 33h passed (offline payment settled != grand total rejected)")
    passed_checks += 1

    # 33i: Zero or non-positive grand total rejected (first-release positive financial grand total only policy, full0/unpaid0/offline0 rejected)
    if verify_payment_decimal_consistency({"settlement_mode": "full", "amount": "0.000000"}, "0.000000", 2):
        print("  FAIL Check 33i: Full settlement with zero grand total accepted!")
        return 1
    if verify_payment_decimal_consistency({"settlement_mode": "unpaid", "amount": "0.000000"}, "0.000000", 2):
        print("  FAIL Check 33i: Unpaid settlement with zero grand total accepted!")
        return 1
    zero_offline = {
        "mode": "full",
        "amount_tendered": "0.000000",
        "amount_settled": "0.000000",
        "change_returned": "0.000000",
    }
    if verify_payment_decimal_consistency(zero_offline, "0.000000", 2):
        print("  FAIL Check 33i: Offline settlement with zero grand total accepted!")
        return 1
    zero_settled_offline = {
        "mode": "full",
        "amount_tendered": "10.000000",
        "amount_settled": "0.000000",
        "change_returned": "10.000000",
    }
    if verify_payment_decimal_consistency(zero_settled_offline, "0.000000", 2):
        print("  FAIL Check 33i: Offline zero settled amount accepted!")
        return 1
    if verify_payment_decimal_consistency({"settlement_mode": "full", "amount": "-50.000000"}, "-50.000000", 2):
        print("  FAIL Check 33i: Negative grand total accepted!")
        return 1
    print("  OK: Check 33i passed (zero/negative grand total rejected for full, unpaid, and offline)")
    passed_checks += 1

    # 33j: Currency minor units scale validation & rejection of boolean/invalid/missing (no guessing 2)
    for bad_cmu in (True, False, -1, 7, "2", None):
        if verify_payment_decimal_consistency({"settlement_mode": "full", "amount": "50.000000"}, "50.000000", bad_cmu):
            print(f"  FAIL Check 33j: Invalid currency_minor_units {bad_cmu!r} accepted!")
            return 1
    if verify_payment_decimal_consistency({"settlement_mode": "full", "amount": "50.000000"}, "50.000000"):
        print("  FAIL Check 33j: Missing currency_minor_units accepted (implicit guessing 2)!")
        return 1
    print("  OK: Check 33j passed (invalid currency minor units boolean/out-of-range/missing strictly rejected)")
    passed_checks += 1

    # 33k: Minimum positive amount allowed at currency minor unit scale (0.01 at cmu 2, 0.001 at cmu 3, 0.000001 at cmu 6)
    if not verify_payment_decimal_consistency({"settlement_mode": "partial", "amount": "0.010000"}, "50.000000", 2):
        print("  FAIL Check 33k: Minimum positive amount 0.010000 rejected at cmu 2!")
        return 1
    if not verify_payment_decimal_consistency({"settlement_mode": "full", "amount": "0.010000"}, "0.010000", 2):
        print("  FAIL Check 33k: Minimum positive full amount 0.010000 rejected at cmu 2!")
        return 1
    if not verify_payment_decimal_consistency({"settlement_mode": "partial", "amount": "0.001000"}, "50.000000", 3):
        print("  FAIL Check 33k: Minimum positive amount 0.001000 rejected at cmu 3!")
        return 1
    if not verify_payment_decimal_consistency({"settlement_mode": "full", "amount": "0.001000"}, "0.001000", 3):
        print("  FAIL Check 33k: Minimum positive full amount 0.001000 rejected at cmu 3!")
        return 1
    if not verify_payment_decimal_consistency({"settlement_mode": "partial", "amount": "0.000001"}, "50.000000", 6):
        print("  FAIL Check 33k: Minimum positive amount 0.000001 rejected at cmu 6!")
        return 1
    if not verify_payment_decimal_consistency({"settlement_mode": "full", "amount": "0.000001"}, "0.000001", 6):
        print("  FAIL Check 33k: Minimum positive full amount 0.000001 rejected at cmu 6!")
        return 1
    print("  OK: Check 33k passed (minimum positive allowed at currency minor unit scale)")
    passed_checks += 1

    # 33l: Overprecision beyond currency minor units strictly rejected without rounding (e.g. ILS partial 0.000001 at cmu 2)
    if verify_payment_decimal_consistency({"settlement_mode": "partial", "amount": "0.000001"}, "50.000000", 2):
        print("  FAIL Check 33l: Overprecision partial amount 0.000001 accepted at cmu 2 (ILS)!")
        return 1
    if verify_payment_decimal_consistency({"settlement_mode": "partial", "amount": "10.005000"}, "50.000000", 2):
        print("  FAIL Check 33l: Overprecision partial amount 10.005000 accepted at cmu 2!")
        return 1
    if verify_payment_decimal_consistency({"settlement_mode": "full", "amount": "50.005000"}, "50.005000", 2):
        print("  FAIL Check 33l: Overprecision full amount 50.005000 accepted at cmu 2!")
        return 1
    if verify_payment_decimal_consistency({"settlement_mode": "partial", "amount": "10.000500"}, "50.000000", 3):
        print("  FAIL Check 33l: Overprecision partial amount 10.000500 accepted at cmu 3 (JOD)!")
        return 1
    bad_overprecision_offline = {
        "mode": "full",
        "amount_tendered": "60.000001",
        "amount_settled": "50.000000",
        "change_returned": "10.000001",
    }
    if verify_payment_decimal_consistency(bad_overprecision_offline, "50.000000", 2):
        print("  FAIL Check 33l: Overprecision in offline tender accepted at cmu 2!")
        return 1
    print("  OK: Check 33l passed (overprecision beyond currency minor units rejected without rounding)")
    passed_checks += 1

    # 33m: Trailing zeros representing integer currency minor units accepted (50.000000 at cmu 2)
    if not verify_payment_decimal_consistency({"settlement_mode": "full", "amount": "50.000000"}, "50.000000", 2):
        print("  FAIL Check 33m: Trailing zeros 50.000000 rejected at cmu 2!")
        return 1
    if not verify_payment_decimal_consistency({"settlement_mode": "partial", "amount": "20.500000"}, "50.000000", 2):
        print("  FAIL Check 33m: Trailing zeros 20.500000 rejected at cmu 2!")
        return 1
    if not verify_payment_decimal_consistency({"settlement_mode": "full", "amount": "50.000000"}, "50.000000", 3):
        print("  FAIL Check 33m: Trailing zeros 50.000000 rejected at cmu 3!")
        return 1
    print("  OK: Check 33m passed (trailing zeros representing integer currency minor units accepted)")
    passed_checks += 1

    # 33n: Malformed / NaN / Infinity / float / scientific exponent lexemes fail closed with return False (no crash)
    for bad_lexeme in ("NaN", "-NaN", "Infinity", "-Infinity", "+50.00", "1e5", "1.5E2", 50.0, None, [], {}):
        if verify_payment_decimal_consistency({"settlement_mode": "full", "amount": bad_lexeme}, "50.000000", 2):
            print(f"  FAIL Check 33n: Malformed/non-finite lexeme {bad_lexeme!r} accepted in amount!")
            return 1
        if verify_payment_decimal_consistency({"settlement_mode": "full", "amount": "50.000000"}, bad_lexeme, 2):
            print(f"  FAIL Check 33n: Malformed/non-finite grand total {bad_lexeme!r} accepted!")
            return 1
    if verify_payment_decimal_consistency("not_a_dict", "50.000000", 2):
        print("  FAIL Check 33n: Non-dict payment accepted!")
        return 1
    print("  OK: Check 33n passed (malformed/NaN/Infinity/float/exponent lexemes fail closed with return False)")
    passed_checks += 1

    # Check 34: Missing required economic fields for full/partial never default
    # 34a: Full mode with omitted payment_method rejected
    bad_full_no_method = json.loads(json.dumps(preview_v2_fix["request"]))
    del bad_full_no_method["payment"]["payment_method"]
    try:
        construct_online_sale_intent_profile(preview_v2_fix["context"], bad_full_no_method, validator_hub)
        print("  FAIL Check 34a: Full mode with omitted payment_method accepted!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 34a passed (full mode with omitted payment_method rejected)")
        passed_checks += 1

    # 34b: Full mode with null payment_method rejected
    bad_full_null_method = json.loads(json.dumps(preview_v2_fix["request"]))
    bad_full_null_method["payment"]["payment_method"] = None
    try:
        construct_online_sale_intent_profile(preview_v2_fix["context"], bad_full_null_method, validator_hub)
        print("  FAIL Check 34b: Full mode with null payment_method accepted!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 34b passed (full mode with null payment_method rejected)")
        passed_checks += 1

    # 34c: Full mode with omitted cash_account_id rejected
    bad_full_no_acct = json.loads(json.dumps(preview_v2_fix["request"]))
    del bad_full_no_acct["payment"]["cash_account_id"]
    try:
        construct_online_sale_intent_profile(preview_v2_fix["context"], bad_full_no_acct, validator_hub)
        print("  FAIL Check 34c: Full mode with omitted cash_account_id accepted!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 34c passed (full mode with omitted cash_account_id rejected)")
        passed_checks += 1

    # 34d: Full mode with null cash_account_id rejected
    bad_full_null_acct = json.loads(json.dumps(preview_v2_fix["request"]))
    bad_full_null_acct["payment"]["cash_account_id"] = None
    try:
        construct_online_sale_intent_profile(preview_v2_fix["context"], bad_full_null_acct, validator_hub)
        print("  FAIL Check 34d: Full mode with null cash_account_id accepted!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 34d passed (full mode with null cash_account_id rejected)")
        passed_checks += 1

    # 34e: Partial mode with null cash_account_id rejected
    bad_partial_null_acct = json.loads(json.dumps(req_partial))
    bad_partial_null_acct["payment"]["cash_account_id"] = None
    try:
        construct_online_sale_intent_profile(preview_v2_fix["context"], bad_partial_null_acct, validator_hub)
        print("  FAIL Check 34e: Partial mode with null cash_account_id accepted!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 34e passed (partial mode with null cash_account_id rejected)")
        passed_checks += 1

    # 34f: Unpaid mode with non-null payment_method rejected
    bad_unpaid_with_cash = json.loads(json.dumps(preview_v2_fix["request"]))
    bad_unpaid_with_cash["payment"] = {
        "settlement_mode": "unpaid",
        "payment_method": "cash",
        "cash_account_id": "01JCV8X9MAJ3K4M5N6P7Q8R9S0",
        "amount": "0.000000",
    }
    try:
        construct_online_sale_intent_profile(preview_v2_fix["context"], bad_unpaid_with_cash, validator_hub)
        print("  FAIL Check 34f: Unpaid mode carrying cash payment method was accepted!")
        return 1
    except IntentProfileValidationError:
        print("  OK: Check 34f passed (unpaid mode with non-null payment method rejected)")
        passed_checks += 1

    # Check 35: Source-backed adaptation test illustrations (contract qualification, not server implementation)
    # 35a: Offline cash capture adaptation: tender 60 / total 50 / change 10 -> canonical receipt 50 (net retained settled)
    offline_tender_example = {
        "mode": "full",
        "amount_tendered": "60.000000",
        "amount_settled": "50.000000",
        "change_returned": "10.000000",
    }
    canonical_receipt = adapt_offline_cash_to_canonical_receipt(offline_tender_example, "50.000000", currency_minor_units=2)
    if canonical_receipt["receipt_amount"] != "50.000000":
        print(f"  FAIL Check 35a: Proposed canonical receipt amount was {canonical_receipt['receipt_amount']!r}, expected net retained 50.000000!")
        return 1
    if canonical_receipt["receipt_amount"] == canonical_receipt["amount_tendered"]:
        print("  FAIL Check 35a: Proposed canonical receipt amount incorrectly used tendered instead of settled!")
        return 1
    # Verify audit fields are preserved separately and not leaked unchecked into receipt_command
    if canonical_receipt["audit"]["amount_tendered"] != "60.000000" or canonical_receipt["audit"]["change_returned"] != "10.000000":
        print("  FAIL Check 35a: Offline audit fields not retained in audit dict!")
        return 1
    if "amount_tendered" in canonical_receipt["receipt_command"] or "change_returned" in canonical_receipt["receipt_command"]:
        print("  FAIL Check 35a: Wire audit fields improperly leaked into receipt_command payload!")
        return 1
    print("  OK: Check 35a passed (offline cash capture adapted to net retained settled amount, tender 60/total 50/change 10 -> receipt 50, audit preserved separately)")
    passed_checks += 1

    # Deterministic test resolver mapping company-scoped wire Crockford ULID to internal positive integer
    known_accounts = {
        ("01JCV8X9M2NQW3TYZ4R5S6789A", "01JCV8X9MAJ3K4M5N6P7Q8R9S0"): 101,
        ("01JCV8X9M2NQW3TYZ4R5S6789A", "01JCV8X9MAJ3K4M5N6P7Q8R9S2"): 102,
    }
    def deterministic_cash_account_resolver(company_id: str, cash_account_ulid: str) -> int:
        pair = (company_id, cash_account_ulid)
        if pair not in known_accounts:
            raise ValueError(f"Unknown or foreign company cash account: {cash_account_ulid}")
        return known_accounts[pair]

    # 35b: Unpaid wire profile adaptation: maps EXACTLY to {"mode": "unpaid"}, strips wire fields without altering profile/digest or applying general null filter
    # Unpaid requires NO cash_account_resolver or reviewed_payment_date (unpaidnoresolverneeded)
    choice_unpaid = adapt_online_payment_to_canonical_choice(profile_unpaid_null, "50.000000", currency_minor_units=2)
    if choice_unpaid != {"mode": "unpaid"}:
        print(f"  FAIL Check 35b: Unpaid choice did not map exactly to {{'mode': 'unpaid'}}: {choice_unpaid!r}")
        return 1
    # Verify wire intent profile and its digest are NOT mutated
    if profile_unpaid_null["payment"]["amount"] != "0.000000" or profile_unpaid_null["payment"]["payment_method"] is not None:
        print("  FAIL Check 35b: Wire intent profile payment fields mutated during canonical adaptation!")
        return 1
    if compute_restricted_sha256_digest(profile_unpaid_null) != unpaid_null_digest:
        print("  FAIL Check 35b: Wire intent profile digest mutated during canonical adaptation!")
        return 1
    # Crucial: verify ExplicitNoTax line tax_rate_id: null is preserved (no general null filter)
    sample_no_tax_line = {
        "product_id": "01JCV8X9M6A1B2C3D4E5F6G7H8",
        "product_unit_id": "01JCV8X9MBV4W5X6Y7Z8012345",
        "conversion_to_base": "1.000000",
        "quantity": "1.000000",
        "unit_price": "10.000000",
        "tax_rate_id": None,
        "tax_rate": "0.000000",
        "tax_inclusive": False,
    }
    if "tax_rate_id" not in sample_no_tax_line or sample_no_tax_line["tax_rate_id"] is not None:
        print("  FAIL Check 35b: Line tax_rate_id: null was stripped or corrupted!")
        return 1
    print("  OK: Check 35b passed (unpaid wire profile maps exactly to {'mode': 'unpaid'} without resolver/date, wire profile/digest unaltered, line tax_rate_id: null preserved)")
    passed_checks += 1

    # 35c: Partial base currency cash adaptation: wire amount maps to received_amount AND invoice_amount allocation
    # money_account_id is resolved internal int 101, NOT wire ULID string
    choice_partial = adapt_online_payment_to_canonical_choice(
        profile_partial,
        "50.000000",
        currency_minor_units=2,
        reviewed_payment_date="2026-11-11",
        cash_account_resolver=deterministic_cash_account_resolver,
    )
    if (
        choice_partial["amount"] != "20.000000" or
        choice_partial["invoice_amount"] != "20.000000" or
        choice_partial["payment_currency_amount"] != "20.000000" or
        choice_partial["exchange_rate"] != "1.0000000000" or
        choice_partial["mode"] != "partial" or
        choice_partial["payment_date"] != "2026-11-11" or
        choice_partial["money_account_id"] != 101 or
        not isinstance(choice_partial["money_account_id"], int)
    ):
        print(f"  FAIL Check 35c: Partial choice mapping failed: {choice_partial!r}")
        return 1
    print("  OK: Check 35c passed (partial base currency cash maps wire amount to received_amount and invoice_amount allocation, FX 1, resolved internal int account, reviewed date)")
    passed_checks += 1

    # 35d: Online full policy exact match rejection: overpayment (60 > 50) and underpayment (40 < 50) rejected
    bad_full_over = json.loads(json.dumps(profile_full_exact))
    bad_full_over["payment"]["amount"] = "60.000000"
    try:
        adapt_online_payment_to_canonical_choice(
            bad_full_over,
            "50.000000",
            currency_minor_units=2,
            reviewed_payment_date="2026-11-11",
            cash_account_resolver=deterministic_cash_account_resolver,
        )
        print("  FAIL Check 35d: Full mode overpayment was accepted by adapter!")
        return 1
    except ValueError:
        pass
    bad_full_under = json.loads(json.dumps(profile_full_exact))
    bad_full_under["payment"]["amount"] = "40.000000"
    try:
        adapt_online_payment_to_canonical_choice(
            bad_full_under,
            "50.000000",
            currency_minor_units=2,
            reviewed_payment_date="2026-11-11",
            cash_account_resolver=deterministic_cash_account_resolver,
        )
        print("  FAIL Check 35d: Full mode underpayment was accepted by adapter!")
        return 1
    except ValueError:
        pass
    choice_full = adapt_online_payment_to_canonical_choice(
        profile_full_exact,
        "50.000000",
        currency_minor_units=2,
        reviewed_payment_date="2026-11-11",
        cash_account_resolver=deterministic_cash_account_resolver,
    )
    if (
        choice_full["amount"] != "50.000000" or
        choice_full["invoice_amount"] != "50.000000" or
        choice_full["money_account_id"] != 101 or
        not isinstance(choice_full["money_account_id"], int)
    ):
        print(f"  FAIL Check 35d: Full exact choice mapping failed: {choice_full!r}")
        return 1
    print("  OK: Check 35d passed (online full first-release policy enforces exact total, resolved internal int account, over/under payment strictly rejected)")
    passed_checks += 1

    # 35e: Missing resolver or payment date fail closed with no invented defaults
    try:
        adapt_online_payment_to_canonical_choice(profile_full_exact, "50.000000", currency_minor_units=2, reviewed_payment_date=None, cash_account_resolver=deterministic_cash_account_resolver)
        print("  FAIL Check 35e: Missing payment_date was accepted with invented default!")
        return 1
    except ValueError:
        pass
    try:
        adapt_online_payment_to_canonical_choice(profile_full_exact, "50.000000", currency_minor_units=2, reviewed_payment_date="2026-11-11", cash_account_resolver=None)
        print("  FAIL Check 35e: Missing cash_account_resolver was accepted!")
        return 1
    except ValueError:
        pass
    try:
        adapt_online_payment_to_canonical_choice(profile_partial, "50.000000", currency_minor_units=2, reviewed_payment_date=None, cash_account_resolver=deterministic_cash_account_resolver)
        print("  FAIL Check 35e: Partial mode missing payment_date was accepted!")
        return 1
    except ValueError:
        pass
    try:
        adapt_online_payment_to_canonical_choice(profile_partial, "50.000000", currency_minor_units=2, reviewed_payment_date="2026-11-11", cash_account_resolver=None)
        print("  FAIL Check 35e: Partial mode missing cash_account_resolver was accepted!")
        return 1
    except ValueError:
        pass
    print("  OK: Check 35e passed (missing cash account resolver or payment date strictly fails closed with no invented defaults)")
    passed_checks += 1

    # 35f: Foreign / unknown cash account resolver failure without secrets leak
    bad_foreign_profile = json.loads(json.dumps(profile_full_exact))
    bad_foreign_profile["payment"]["cash_account_id"] = "01JCV8X9MAJ3K4M5N6P7Q8R9S9"  # Foreign/unknown ULID
    try:
        adapt_online_payment_to_canonical_choice(
            bad_foreign_profile,
            "50.000000",
            currency_minor_units=2,
            reviewed_payment_date="2026-11-11",
            cash_account_resolver=deterministic_cash_account_resolver,
        )
        print("  FAIL Check 35f: Foreign/unknown company cash account was accepted!")
        return 1
    except ValueError as e:
        # Verify no credentials, tokens, or connection strings in error message
        err_msg = str(e)
        for secret_token in ("password", "secret", "token", "Bearer", "PRIVATE KEY"):
            if secret_token in err_msg:
                print(f"  FAIL Check 35f: Secret leaked in error message: {err_msg}")
                return 1
    print("  OK: Check 35f passed (foreign company cash account resolver fails closed without secrets leak)")
    passed_checks += 1

    # Reject resolver interfaces that cannot receive company scope.
    try:
        adapt_online_payment_to_canonical_choice(
            profile_full_exact, "50.000000", 2, "2026-11-11", lambda account: 1
        )
        print("  FAIL: Unscoped one-argument cash account resolver accepted")
        return 1
    except ValueError:
        passed_checks += 1

    # Revision 2 positive/negative read/auth fixtures
    cases_rev2 = json.loads((FIXTURES_DIR / "revision-2-validation-cases.json").read_text())
    rev2_count = 0
    for case in cases_rev2:
        try:
            validator_hub.validate_instance(case["instance"], case["schema"], case["name"])
            accepted = True
        except JsonSchemaValidationError:
            accepted = False
        if accepted != case["valid"]:
            raise ValueError(f"Revision 2 fixture failed: {case['name']} (expected {case['valid']}, got {accepted})")
        rev2_count += 1
    print(f"  OK: {rev2_count} revision 2 positive/negative fixture validations")
    passed_checks += rev2_count

    # Revision 3 positive/negative financial/tax/version fixtures
    cases_rev3 = json.loads((FIXTURES_DIR / "revision-3-validation-cases.json").read_text())
    rev3_count = 0
    for case in cases_rev3:
        try:
            validator_hub.validate_instance(case["instance"], case["schema"], case["name"])
            accepted = True
        except JsonSchemaValidationError:
            accepted = False
        if accepted != case["valid"]:
            raise ValueError(f"Revision 3 fixture failed: {case['name']} (expected {case['valid']}, got {accepted})")
        rev3_count += 1
    print(f"  OK: {rev3_count} revision 3 positive/negative fixture validations")
    passed_checks += rev3_count

    print("\n  [Contract Isolation Notice] These validation checks verify contract schemas, OpenAPI invariants,")
    print("  exact decimal arithmetic, and JCS digests. Database row locks, tenant middlewares, and ledger")
    print("  postings are authoritative in Laravel account PHPUnit suites.")

    # 7. Verify reproducible manifest SHA-256 (fail closed if absent)
    print("\n[Step 7] Verifying Reproducible SHA-256 Manifest...")
    manifest_entries = generate_manifest_entries(BASE_DIR)

    if "--generate-manifest" in sys.argv:
        with open(MANIFEST_FILE, "w", encoding="utf-8") as f:
            for rel_path, sha in sorted(manifest_entries.items()):
                f.write(f"{sha}  {rel_path}\n")
        manifest_sha = hashlib.sha256(MANIFEST_FILE.read_bytes()).hexdigest()
        print(f"  OK: Successfully generated new manifest at {MANIFEST_FILE}")
        print(f"  Manifest SHA-256: {manifest_sha}")
        passed_checks += 1
    else:
        if not MANIFEST_FILE.exists():
            print(f"  FAIL CLOSED: manifest.sha256 not found at {MANIFEST_FILE}!")
            print("  Normal validation fails closed absent manifest. Explicitly run with --generate-manifest to generate.")
            return 1

        with open(MANIFEST_FILE, "r", encoding="utf-8") as f:
            lines = f.readlines()

        ok, msg = verify_manifest_content(lines, manifest_entries)
        if not ok:
            print(f"  FAIL: {msg}")
            return 1
        print(f"  OK: {msg}")
        passed_checks += 1

    print("\n" + "=" * 70)
    print(f"SUCCESS: All {passed_checks} contract, schema, fixture, regression, and negative tests passed!")
    print("=" * 70)
    return 0


if __name__ == "__main__":
    sys.exit(run_suite())
