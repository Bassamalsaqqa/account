import json
import sys
from pathlib import Path
from jsonschema import Draft202012Validator, FormatChecker
schema = json.loads((Path(__file__).resolve().parents[3] / 'docs/integration/mobile-v1/schemas' / (sys.argv[1] + '.json')).read_text())
Draft202012Validator(schema, format_checker=FormatChecker()).validate(json.load(sys.stdin))
