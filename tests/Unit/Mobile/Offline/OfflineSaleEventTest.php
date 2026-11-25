<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile\Offline;

use App\Support\Mobile\Contract\CanonicalJsonSerializer;
use App\Support\Mobile\Offline\OfflineSaleConflict;
use App\Support\Mobile\Offline\OfflineSaleEvent;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OfflineSaleEventTest extends TestCase
{
    public function test_revision_three_fixture_preserves_original_digest_and_cash_projection(): void
    {
        $wire = $this->fixture();
        $sale = OfflineSaleEvent::fromWire(json_encode($wire, JSON_THROW_ON_ERROR));
        $this->assertSame($wire['payload_digest'], 'sha256:'.hash('sha256', $sale->canonicalBytes()));
        $this->assertEquals($wire['event'], $sale->event);
        $profile = $sale->cashProfile(2);
        $this->assertSame('50.000000', $profile['payment']['amount']);
        $this->assertSame('full', $profile['payment']['settlement_mode']);
        $this->assertSame('2.000000', $profile['lines'][0]['quantity']);
        $this->assertArrayNotHasKey('local_receipt_number', $profile);
    }

    public function test_changed_event_with_original_digest_is_refused(): void
    {
        $wire = $this->fixture();
        $wire['event']['local_receipt_number'] = 'changed';

        $this->expectException(OfflineSaleConflict::class);
        $this->expectExceptionMessage('payload digest does not match');
        OfflineSaleEvent::fromWire(json_encode($wire, JSON_THROW_ON_ERROR));
    }

    public function test_wrong_envelope_scope_is_refused(): void
    {
        $wire = $this->fixture();
        $wire['device_id'] = 'a0000000-0000-0000-0000-000000000002';

        $this->expectException(InvalidArgumentException::class);
        OfflineSaleEvent::fromWire(json_encode($wire, JSON_THROW_ON_ERROR));
    }

    public function test_exact_tender_and_change_are_checked_at_currency_precision(): void
    {
        $wire = $this->fixture();
        $wire['event']['payment']['amount_tendered'] = '60.001';
        $wire['event']['payment']['change_returned'] = '10.001';
        $wire['payload_digest'] = CanonicalJsonSerializer::computeDigest($wire['event']);
        $sale = OfflineSaleEvent::fromWire(json_encode($wire, JSON_THROW_ON_ERROR));

        $this->assertSame('50.000000', $sale->cashProfile(3)['payment']['amount']);
        $this->expectException(InvalidArgumentException::class);
        $sale->cashProfile(2);
    }

    public function test_legacy_event_requires_client_recovery(): void
    {
        $wire = $this->fixture();
        $wire['event'] = ['event_version' => 1];

        $this->expectException(OfflineSaleConflict::class);
        $this->expectExceptionMessage('version cannot be submitted');
        OfflineSaleEvent::fromWire(json_encode($wire, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $path = dirname(__DIR__, 4).'/contracts/mobile-revision3/docs/integration/mobile-v1/fixtures/golden-offline-sale-intake-v2.json';

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['request'];
    }
}
