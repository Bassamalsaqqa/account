<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile\Contract;

use App\Support\Mobile\Contract\CanonicalJsonSerializer;
use App\Support\Mobile\Contract\ContractConstants;
use App\Support\Mobile\Contract\DecimalLexeme;
use App\Support\Mobile\Contract\Exceptions\CanonicalSerializationException;
use App\Support\Mobile\Contract\StrictJsonArray;
use App\Support\Mobile\Contract\StrictJsonNumber;
use App\Support\Mobile\Contract\StrictJsonObject;
use App\Support\Mobile\Contract\StrictJsonParser;
use App\Support\Mobile\Contract\StrictJsonString;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use stdClass;

class CanonicalJsonSerializerTest extends TestCase
{
    private function fixturesDir(): string
    {
        return dirname(__DIR__, 4).'/docs/integration/mobile-v1/fixtures';
    }

    public function test_golden_canonical_json_vectors_parity(): void
    {
        $vectorsFile = $this->fixturesDir().'/golden-canonical-vectors.json';
        $this->assertFileExists($vectorsFile);

        $rawJson = (string) file_get_contents($vectorsFile);
        /** @var list<array{name: string, description: string, input: mixed, canonical_json: string, digest: string}> $vectors */
        $vectors = json_decode($rawJson, true);

        $this->assertNotEmpty($vectors);

        foreach ($vectors as $vec) {
            $name = $vec['name'];
            $input = $vec['input'];
            $expectedCanonical = $vec['canonical_json'];
            $expectedDigest = $vec['digest'];

            // 1. Serialize from decoded structure
            $actualCanonical = CanonicalJsonSerializer::serialize($input);
            $this->assertSame($expectedCanonical, $actualCanonical, "Vector '{$name}' canonical JSON mismatch");

            // 2. Exact UTF-8 byte matching
            $actualBytes = CanonicalJsonSerializer::serializeToUtf8Bytes($input);
            $expectedBytes = $expectedCanonical;
            $this->assertSame(strlen($expectedBytes), strlen($actualBytes), "Vector '{$name}' byte length mismatch");
            $this->assertSame($expectedBytes, $actualBytes, "Vector '{$name}' byte content mismatch");

            // 3. Exact SHA-256 digest matching
            $actualDigest = CanonicalJsonSerializer::computeDigest($input);
            $this->assertSame($expectedDigest, $actualDigest, "Vector '{$name}' digest mismatch");
        }
    }

    public function test_golden_offline_sale_intake_parity_and_digest(): void
    {
        $goldenFile = $this->fixturesDir().'/golden-offline-sale-intake.json';
        $this->assertFileExists($goldenFile);

        $rawJson = (string) file_get_contents($goldenFile);
        $rootElement = StrictJsonParser::parse($rawJson);
        $this->assertInstanceOf(StrictJsonObject::class, $rootElement);

        $reqElement = $rootElement->require('request');
        $this->assertInstanceOf(StrictJsonObject::class, $reqElement);

        $eventElement = $reqElement->require('event');
        $this->assertInstanceOf(StrictJsonObject::class, $eventElement);

        // 1. Envelope bindings check
        $clientEventId = $reqElement->require('client_event_id');
        $this->assertInstanceOf(StrictJsonString::class, $clientEventId);
        $eventClientEventId = $eventElement->require('client_event_id');
        $this->assertInstanceOf(StrictJsonString::class, $eventClientEventId);
        $this->assertSame($clientEventId->value, $eventClientEventId->value);

        $deviceId = $reqElement->require('device_id');
        $this->assertInstanceOf(StrictJsonString::class, $deviceId);
        $eventDeviceId = $eventElement->require('device_id');
        $this->assertInstanceOf(StrictJsonString::class, $eventDeviceId);
        $this->assertSame($deviceId->value, $eventDeviceId->value);

        $capturedAt = $reqElement->require('captured_at');
        $this->assertInstanceOf(StrictJsonString::class, $capturedAt);
        $eventCapturedAt = $eventElement->require('captured_at');
        $this->assertInstanceOf(StrictJsonString::class, $eventCapturedAt);
        $this->assertSame($capturedAt->value, $eventCapturedAt->value);

        // 2. Packaging unit mapping check: product_unit_id is unit public ULID bound to product
        $lines = $eventElement->require('lines');
        $this->assertInstanceOf(StrictJsonArray::class, $lines);
        $this->assertGreaterThan(0, $lines->count());
        $line1 = $lines->get(0);
        $this->assertInstanceOf(StrictJsonObject::class, $line1);

        $productUnitId = $line1->require('product_unit_id');
        $this->assertInstanceOf(StrictJsonString::class, $productUnitId);
        $unitId = $line1->require('unit_id');
        $this->assertInstanceOf(StrictJsonString::class, $unitId);

        $this->assertSame('01JCV8X9MBV4W5X6Y7Z8012345', $productUnitId->value);
        $this->assertSame($productUnitId->value, $unitId->value);

        // 3. Compute canonical JSON digest over the event object
        $computedDigest = CanonicalJsonSerializer::computeDigest($eventElement);
        $declaredDigest = $reqElement->require('payload_digest');
        $this->assertInstanceOf(StrictJsonString::class, $declaredDigest);

        $expectedDigest = 'sha256:d442ce49a7609a9a42bb5165990d481d1c7c563cb8030403487099a9919728d9';
        $this->assertSame($expectedDigest, $computedDigest);
        $this->assertSame($declaredDigest->value, $computedDigest);
    }

    public function test_utf16_surrogate_key_ordering_supplementary_vs_high_bmp(): void
    {
        // High BMP U+FFFF vs Supplementary plane emoji U+1F600 (\uD83D\uDE00)
        // In UTF-16 code units, 0xD83D (55357) < 0xFFFF (65535)
        $map = [
            "\xef\xbf\xbf" => 'bmp_last',
            "\xf0\x9f\x98\x80" => 'supplementary_plane',
        ];

        $canonical = CanonicalJsonSerializer::serialize($map);
        $this->assertSame("{\"😀\":\"supplementary_plane\",\"\u{ffff}\":\"bmp_last\"}", $canonical);

        $digest = CanonicalJsonSerializer::computeDigest($map);
        $this->assertSame('sha256:0f6e2a5ec50588eebe12034a62dba2dea312e7419a3b4b35b07642405cc2fb06', $digest);
    }

    public function test_control_character_escaping(): void
    {
        $map = [
            'tab' => "a\tb",
            'newline' => "line1\nline2",
            'null_char' => "prefix\x00suffix",
        ];

        $canonical = CanonicalJsonSerializer::serialize($map);
        $this->assertSame('{"newline":"line1\\nline2","null_char":"prefix\u0000suffix","tab":"a\tb"}', $canonical);

        $digest = CanonicalJsonSerializer::computeDigest($map);
        $this->assertSame('sha256:0468796dc39483b190fca87ef8328eec7fea550efcdd53ac9aaaf117fd041244', $digest);
    }

    public function test_arabic_utf8_text_preserved(): void
    {
        $map = [
            'arabic' => 'عميل نقدي / Walk-in',
            'english' => 'Cash Customer',
        ];

        $canonical = CanonicalJsonSerializer::serialize($map);
        $this->assertSame('{"arabic":"عميل نقدي / Walk-in","english":"Cash Customer"}', $canonical);
    }

    public function test_reject_unpaired_surrogates(): void
    {
        // Lone high surrogate escape in JSON
        $this->expectException(CanonicalSerializationException::class);
        StrictJsonParser::parse('{"bad": "prefix\\uD800suffix"}');
    }

    public function test_reject_lone_low_surrogate(): void
    {
        $this->expectException(CanonicalSerializationException::class);
        StrictJsonParser::parse('{"bad": "prefix\\uDC00suffix"}');
    }

    public function test_reject_reversed_surrogate_pair(): void
    {
        // Low surrogate preceding high surrogate
        $this->expectException(CanonicalSerializationException::class);
        StrictJsonParser::parse('{"bad": "prefix\\uDC00\\uD800suffix"}');
    }

    public function test_reject_floats_in_parser(): void
    {
        $this->expectException(CanonicalSerializationException::class);
        $this->expectExceptionMessage('IEEE 754 floats are strictly forbidden');
        StrictJsonParser::parse('{"price": 10.5}');
    }

    public function test_reject_scientific_notation_in_parser(): void
    {
        $this->expectException(CanonicalSerializationException::class);
        $this->expectExceptionMessage('IEEE 754 floats are strictly forbidden');
        StrictJsonParser::parse('{"amount": 1e5}');
    }

    public function test_reject_floats_in_serializer(): void
    {
        $this->expectException(CanonicalSerializationException::class);
        $this->expectExceptionMessage('IEEE 754 floats are strictly forbidden');
        CanonicalJsonSerializer::serialize(['price' => 10.5]);
    }

    public function test_strict_json_parser_rejects_duplicate_keys(): void
    {
        $duplicateJson = '{"a": 1, "a": 2}';
        $this->expectException(CanonicalSerializationException::class);
        $this->expectExceptionMessage("Duplicate JSON key detected: 'a'");
        StrictJsonParser::parse($duplicateJson);
    }

    public function test_strict_json_parser_rejects_escaped_duplicate_keys(): void
    {
        // "a" and "\u0061" represent the exact same key
        $escapedDuplicateJson = '{"a": 1, "\u0061": 2}';
        $this->expectException(CanonicalSerializationException::class);
        $this->expectExceptionMessage("Duplicate JSON key detected: 'a'");
        StrictJsonParser::parse($escapedDuplicateJson);
    }

    public function test_strict_json_parser_rejects_control_char_escaped_duplicate_keys(): void
    {
        // "\n" and "\u000a" represent the exact same key
        $json = '{"k\n": 1, "k\u000a": 2}';
        $this->expectException(CanonicalSerializationException::class);
        $this->expectExceptionMessage("Duplicate JSON key detected: 'k\n'");
        StrictJsonParser::parse($json);
    }

    public function test_distinguishes_empty_object_and_array(): void
    {
        $emptyObj = CanonicalJsonSerializer::serialize(new StrictJsonObject([]));
        $emptyList = CanonicalJsonSerializer::serialize(new StrictJsonArray([]));

        $this->assertSame('{}', $emptyObj);
        $this->assertSame('[]', $emptyList);
        $this->assertNotSame($emptyObj, $emptyList);

        // Native stdClass vs empty array
        $stdObj = CanonicalJsonSerializer::serialize(new stdClass);
        $stdList = CanonicalJsonSerializer::serialize([]);
        $this->assertSame('{}', $stdObj);
        $this->assertSame('[]', $stdList);
    }

    public function test_canonical_integer_negative_zero_forbidden(): void
    {
        // In parser
        $this->expectException(CanonicalSerializationException::class);
        $this->expectExceptionMessage("Canonical integer negative zero '-0' is strictly forbidden");
        StrictJsonParser::parse('{"count": -0}');
    }

    public function test_negative_zero_number_element_rejected_in_serializer(): void
    {
        $this->expectException(CanonicalSerializationException::class);
        $this->expectExceptionMessage("Negative zero '-0' is forbidden in canonical JSON profile");
        CanonicalJsonSerializer::serialize(new StrictJsonNumber('-0', 0, true));
    }

    public function test_bounded_canonical_integer_domain(): void
    {
        $maxSafe = ContractConstants::MAX_SAFE_INTEGER;
        $minSafe = ContractConstants::MIN_SAFE_INTEGER;

        $this->assertSame('9007199254740991', CanonicalJsonSerializer::serialize($maxSafe));
        $this->assertSame('-9007199254740991', CanonicalJsonSerializer::serialize($minSafe));

        // Parser accepts safe integers
        $parsedMax = StrictJsonParser::parse('{"val": 9007199254740991}');
        $this->assertInstanceOf(StrictJsonObject::class, $parsedMax);
        $num = $parsedMax->require('val');
        $this->assertInstanceOf(StrictJsonNumber::class, $num);
        $this->assertSame($maxSafe, $num->asLong());

        // Serializer rejects out of range
        $this->expectException(CanonicalSerializationException::class);
        $this->expectExceptionMessage('exceeds canonical safe integer domain');
        CanonicalJsonSerializer::serialize(9007199254740992);
    }

    public function test_parser_rejects_integer_exceeding_safe_domain(): void
    {
        $this->expectException(CanonicalSerializationException::class);
        $this->expectExceptionMessage('exceeds canonical safe integer domain');
        StrictJsonParser::parse('{"val": 9007199254740992}');
    }

    public function test_recursion_depth_bound_exceeded(): void
    {
        $deepJson = '{"leaf": 1}';
        for ($i = 0; $i < 35; $i++) {
            $deepJson = '{"nested": '.$deepJson.'}';
        }

        $this->expectException(CanonicalSerializationException::class);
        $this->expectExceptionMessage('recursion depth exceeded');
        StrictJsonParser::parse($deepJson);
    }

    public function test_string_length_bound_exceeded(): void
    {
        $longString = '"'.str_repeat('a', 70000).'"';

        $this->expectException(CanonicalSerializationException::class);
        $this->expectExceptionMessage('string length exceeds maximum');
        StrictJsonParser::parse($longString);
    }

    public function test_exact_decimal_lexeme_preservation_and_digest_parity(): void
    {
        // In restricted canonical JSON, all decimals must be string-encoded.
        // Different lexemes like "1" vs "1.000000" represent the same economic value,
        // but have distinct SHA-256 digests. Digest calculation MUST be performed
        // on the exact unnormalized string lexemes as received.
        $payloadA = '{"currency":"ILS","quantity":"1","total":"50"}';
        $payloadB = '{"currency":"ILS","quantity":"1.000000","total":"50.000000"}';

        $digestA = CanonicalJsonSerializer::computeDigestForRawJson($payloadA);
        $digestB = CanonicalJsonSerializer::computeDigestForRawJson($payloadB);

        $this->assertNotSame($digestA, $digestB, 'Different string lexemes must yield different digests');

        // Verify DecimalLexeme value object preserves lexemes
        $lexA = DecimalLexeme::of('1');
        $lexB = DecimalLexeme::of('1.000000');

        $this->assertSame('1', $lexA->toPlainString());
        $this->assertSame('1.000000', $lexB->toPlainString());

        // Verify that conversion to BigDecimal happens AFTER digest verification
        // and evaluates to mathematically equal BigDecimal instances
        $bigA = $lexA->toBigDecimal();
        $bigB = $lexB->toBigDecimal();

        $this->assertTrue($bigA->isEqualTo($bigB), 'BigDecimals must be economically equal after deferred normalization');
        $this->assertSame('1.000000', (string) $bigA->toScale(6));
        $this->assertSame('1.000000', (string) $bigB->toScale(6));
    }

    public function test_reject_raw_big_decimal_in_serializer(): void
    {
        $this->expectException(CanonicalSerializationException::class);
        $this->expectExceptionMessage('Raw BigDecimal cannot be emitted directly as a JSON number');
        CanonicalJsonSerializer::serialize(['amount' => BigDecimal::of('100.00')]);
    }

    public function test_altered_payload_conflict_regression_fixture(): void
    {
        $altFile = $this->fixturesDir().'/regression-altered-payload-conflict.json';
        $this->assertFileExists($altFile);

        $rawJson = (string) file_get_contents($altFile);
        $root = StrictJsonParser::parse($rawJson);
        $this->assertInstanceOf(StrictJsonObject::class, $root);

        $incomingReq = $root->require('incoming_request');
        $this->assertInstanceOf(StrictJsonObject::class, $incomingReq);
        $storedRecord = $root->require('stored_record');
        $this->assertInstanceOf(StrictJsonObject::class, $storedRecord);

        $incomingEvent = $incomingReq->require('event');
        $this->assertInstanceOf(StrictJsonObject::class, $incomingEvent);

        $computedDigest = CanonicalJsonSerializer::computeDigest($incomingEvent);
        $declaredDigest = $incomingReq->require('payload_digest');
        $this->assertInstanceOf(StrictJsonString::class, $declaredDigest);
        $storedDigest = $storedRecord->require('payload_digest');
        $this->assertInstanceOf(StrictJsonString::class, $storedDigest);

        // Incoming event digest matches incoming declared digest
        $this->assertSame($declaredDigest->value, $computedDigest);

        // Tampered/altered payload digest strictly differs from stored record digest (409 Conflict trigger)
        $this->assertNotSame($storedDigest->value, $computedDigest);
    }

    public function test_lost_ack_replay_parity_fixture(): void
    {
        $lostAckFile = $this->fixturesDir().'/regression-lost-ack-replay.json';
        $this->assertFileExists($lostAckFile);

        $rawJson = (string) file_get_contents($lostAckFile);
        $root = StrictJsonParser::parse($rawJson);
        $this->assertInstanceOf(StrictJsonObject::class, $root);

        $firstSub = $root->require('first_submission');
        $this->assertInstanceOf(StrictJsonObject::class, $firstSub);
        $retrySub = $root->require('retry_submission');
        $this->assertInstanceOf(StrictJsonObject::class, $retrySub);

        $firstClientEventId = $firstSub->require('client_event_id');
        $this->assertInstanceOf(StrictJsonString::class, $firstClientEventId);
        $retryClientEventId = $retrySub->require('client_event_id');
        $this->assertInstanceOf(StrictJsonString::class, $retryClientEventId);
        $this->assertSame($firstClientEventId->value, $retryClientEventId->value);

        $firstDigest = $firstSub->require('payload_digest');
        $this->assertInstanceOf(StrictJsonString::class, $firstDigest);
        $retryDigest = $retrySub->require('payload_digest');
        $this->assertInstanceOf(StrictJsonString::class, $retryDigest);
        $this->assertSame($firstDigest->value, $retryDigest->value);
    }
}
