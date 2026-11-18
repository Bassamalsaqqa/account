<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile\Contract\Revision3;

use App\Support\Mobile\Contract\Revision3\OnlineSaleWireResponseProjection;
use App\Support\Mobile\WireResponse;
use InvalidArgumentException;
use Tests\TestCase;

final class OnlineSaleWireResponseProjectionTest extends TestCase
{
    private const string VALID_UUID = '3fa85f64-5717-4562-b3fc-2c963f66afa6';

    private const string VALID_INVOICE_ULID = '01JCV8X9ME7890123456789ABC';

    private const string VALID_PAYMENT_ULID = '01JCV8X9MFGHJKMNPQRSTVWXYZ';

    private const string VALID_COMPANY_ULID = '01JCV8X9M2NQW3TYZ4R5S6789A';

    public function test_project_commit_wire_dto_strict_whole_keys_and_ulid_regex(): void
    {
        $terminal = [
            'status' => 'completed',
            'sales_invoice_id' => 101,
            'sales_invoice_public_id' => self::VALID_INVOICE_ULID,
            'invoice_id' => self::VALID_INVOICE_ULID,
            'invoice_number' => 'INV-2026-00042',
            'customer_payment_id' => 202,
            'customer_payment_public_id' => self::VALID_PAYMENT_ULID,
            'payment_id' => self::VALID_PAYMENT_ULID,
            'payment_receipt_number' => 'RCT-2026-00042',
            'customer_id' => 303,
            'grand_total' => '58.000000',
            'amount_paid' => '58.000000',
            'change_returned' => '0.000000',
            'posted_at' => '2026-11-10T12:01:05Z',
            'lines_count' => 2,
        ];

        $dto = OnlineSaleWireResponseProjection::projectCommitWireDto(self::VALID_UUID, $terminal);

        // Strict whole keys check
        $expectedKeys = [
            'client_event_id',
            'status',
            'invoice_id',
            'invoice_number',
            'payment_id',
            'payment_receipt_number',
            'grand_total',
            'amount_paid',
            'change_returned',
            'posted_at',
        ];
        $this->assertSame($expectedKeys, array_keys($dto));

        // No internal SQL IDs or aliases leaked
        $this->assertArrayNotHasKey('sales_invoice_id', $dto);
        $this->assertArrayNotHasKey('customer_payment_id', $dto);
        $this->assertArrayNotHasKey('customer_id', $dto);
        $this->assertArrayNotHasKey('sales_invoice_public_id', $dto);
        $this->assertArrayNotHasKey('customer_payment_public_id', $dto);
        $this->assertArrayNotHasKey('total_amount', $dto);
        $this->assertArrayNotHasKey('paid_amount', $dto);
        $this->assertArrayNotHasKey('lines_count', $dto);

        // Public ULID regex check
        $this->assertMatchesRegularExpression('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $dto['invoice_id']);
        $this->assertMatchesRegularExpression('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $dto['payment_id']);

        // Decimal scalar format check
        $this->assertMatchesRegularExpression('/\A(?:0\.(?=[0-9]{1,6}\z)[0-9]*[1-9][0-9]*|[1-9][0-9]{0,13}(?:\.[0-9]{1,6})?)\z/', $dto['grand_total']);
        $this->assertMatchesRegularExpression('/\A(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?\z/', $dto['amount_paid']);
        $this->assertMatchesRegularExpression('/\A(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?\z/', $dto['change_returned']);

        // Date-time format check
        $this->assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/', $dto['posted_at']);

        // WireResponse schema verification against sale-commit-response.json
        $obj = json_decode((string) json_encode($dto));
        $this->assertTrue(WireResponse::accepts($obj, 'sale-commit-response'));
    }

    public function test_project_commit_wire_dto_null_handling(): void
    {
        $terminal = [
            'status' => 'posted',
            'invoice_id' => self::VALID_INVOICE_ULID,
            'invoice_number' => 'INV-2026-00099',
            'payment_id' => null,
            'payment_receipt_number' => null,
            'grand_total' => '100.500000',
            'amount_paid' => '0.000000',
            'change_returned' => '0.000000',
            'posted_at' => '2026-11-10T12:01:05Z',
        ];

        $dto = OnlineSaleWireResponseProjection::projectCommitWireDto(self::VALID_UUID, $terminal);

        $this->assertNull($dto['payment_id']);
        $this->assertNull($dto['payment_receipt_number']);
        $this->assertSame('100.500000', $dto['grand_total']);
        $this->assertSame('0.000000', $dto['amount_paid']);

        $obj = json_decode((string) json_encode($dto));
        $this->assertTrue(WireResponse::accepts($obj, 'sale-commit-response'));
    }

    public function test_project_commit_wire_dto_rejects_invalid_values(): void
    {
        // Invalid UUID
        $this->expectException(InvalidArgumentException::class);
        OnlineSaleWireResponseProjection::projectCommitWireDto('invalid-uuid', [
            'status' => 'posted',
            'invoice_id' => self::VALID_INVOICE_ULID,
            'invoice_number' => 'INV-001',
            'grand_total' => '50.000000',
        ]);
    }

    public function test_project_commit_wire_dto_rejects_invalid_invoice_ulid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OnlineSaleWireResponseProjection::projectCommitWireDto(self::VALID_UUID, [
            'status' => 'posted',
            'invoice_id' => 'not-a-valid-ulid',
            'invoice_number' => 'INV-001',
            'grand_total' => '50.000000',
        ]);
    }

    public function test_project_commit_wire_dto_rejects_non_scalar_negative_decimal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OnlineSaleWireResponseProjection::projectCommitWireDto(self::VALID_UUID, [
            'status' => 'posted',
            'invoice_id' => self::VALID_INVOICE_ULID,
            'invoice_number' => 'INV-001',
            'grand_total' => '-50.000000',
        ]);
    }

    public function test_project_status_wire_dto_strict_whole_keys_and_schema_parity(): void
    {
        $terminal = [
            'status' => 'posted',
            'invoice_id' => self::VALID_INVOICE_ULID,
            'invoice_number' => 'INV-2026-00042',
            'payment_id' => self::VALID_PAYMENT_ULID,
            'payment_receipt_number' => 'RCT-2026-00042',
            'grand_total' => '58.000000',
            'amount_paid' => '58.000000',
            'change_returned' => '0.000000',
            'posted_at' => '2026-11-10T12:02:10Z',
        ];

        $digest = 'd442ce49a7609a9a42bb5165990d481d1c7c563cb8030403487099a9919728d9';
        $statusDto = OnlineSaleWireResponseProjection::projectStatusWireDto(
            self::VALID_UUID,
            self::VALID_COMPANY_ULID,
            $digest,
            $terminal,
            '2026-11-10T12:05:00Z'
        );

        $expectedKeys = [
            'client_event_id',
            'company_id',
            'status',
            'payload_digest',
            'official_invoice',
            'conflict',
            'queried_at',
        ];
        $this->assertSame($expectedKeys, array_keys($statusDto));

        $expectedInvoiceKeys = [
            'invoice_id',
            'invoice_number',
            'payment_id',
            'payment_receipt_number',
            'grand_total',
            'posted_at',
        ];
        $this->assertIsArray($statusDto['official_invoice']);
        $this->assertSame($expectedInvoiceKeys, array_keys($statusDto['official_invoice']));
        $this->assertNull($statusDto['conflict']);

        $obj = json_decode((string) json_encode($statusDto));
        $this->assertTrue(WireResponse::accepts($obj, 'sale-event-status-response'));
    }

    public function test_real_kotlin_contract_fixture_mapping_parity(): void
    {
        $goldenCommit = json_decode(
            (string) file_get_contents(base_path('docs/integration/mobile-v1/fixtures/golden-sale-commit.json')),
            true
        );
        $expectedResponse = $goldenCommit['response'];

        $terminal = [
            'status' => $expectedResponse['status'],
            'invoice_id' => $expectedResponse['invoice_id'],
            'invoice_number' => $expectedResponse['invoice_number'],
            'payment_id' => $expectedResponse['payment_id'],
            'payment_receipt_number' => $expectedResponse['payment_receipt_number'],
            'grand_total' => $expectedResponse['grand_total'],
            'amount_paid' => $expectedResponse['amount_paid'],
            'change_returned' => $expectedResponse['change_returned'],
            'posted_at' => $expectedResponse['posted_at'],
        ];

        $projected = OnlineSaleWireResponseProjection::projectCommitWireDto(
            $expectedResponse['client_event_id'],
            $terminal
        );

        $this->assertSame($expectedResponse, $projected);
    }

    public function test_project_status_wire_dto_unknown_and_null_schema_parity(): void
    {
        $statusDto = OnlineSaleWireResponseProjection::projectStatusWireDto(
            self::VALID_UUID,
            self::VALID_COMPANY_ULID,
            null,
            null,
            '2026-11-10T12:05:00Z'
        );

        $expectedKeys = [
            'client_event_id',
            'company_id',
            'status',
            'payload_digest',
            'official_invoice',
            'conflict',
            'queried_at',
        ];
        $this->assertSame($expectedKeys, array_keys($statusDto));
        $this->assertSame(self::VALID_UUID, $statusDto['client_event_id']);
        $this->assertSame(self::VALID_COMPANY_ULID, $statusDto['company_id']);
        $this->assertSame('unknown', $statusDto['status']);
        $this->assertNull($statusDto['payload_digest']);
        $this->assertNull($statusDto['official_invoice']);
        $this->assertNull($statusDto['conflict']);
        $this->assertSame('2026-11-10T12:05:00Z', $statusDto['queried_at']);

        // Conforms to sale-event-status-response.json
        $obj = json_decode((string) json_encode($statusDto));
        $this->assertTrue(WireResponse::accepts($obj, 'sale-event-status-response'));
    }
}
