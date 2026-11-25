<?php

declare(strict_types=1);

namespace App\Support\Mobile\Offline;

use App\Support\Mobile\Contract\CanonicalJsonSerializer;
use App\Support\Mobile\Contract\ContractConstants;
use App\Support\Mobile\Contract\Exceptions\CanonicalSerializationException;
use App\Support\Mobile\Contract\Revision3\OnlineSaleIntentProfile;
use App\Support\Mobile\Contract\Revision3\PaymentSettlementValidator;
use App\Support\Mobile\Contract\StrictJsonParser;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use InvalidArgumentException;

final class OfflineSaleEvent
{
    public const string REPRESENTATION = 'offline_sale_intake';

    private const string DECIMAL = '/\A(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?\z/';

    /** @param array<string, mixed> $event */
    private function __construct(public readonly array $event) {}

    public static function fromWire(string $body): self
    {
        if (strlen($body) > ContractConstants::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('Offline sale request exceeds the payload limit.');
        }

        try {
            $canonical = CanonicalJsonSerializer::serialize(StrictJsonParser::parse($body));
        } catch (CanonicalSerializationException $e) {
            throw new InvalidArgumentException('Invalid offline sale JSON.', 0, $e);
        }
        $wire = self::object(json_decode($canonical, true, flags: JSON_THROW_ON_ERROR), [
            'client_event_id', 'device_id', 'captured_at', 'payload_digest', 'event',
        ]);
        $sale = self::fromEvent($wire['event']);
        foreach (['client_event_id', 'device_id', 'captured_at'] as $field) {
            if ($wire[$field] !== $sale->event[$field]) {
                throw new InvalidArgumentException('Offline event and envelope disagree.');
            }
        }
        if ($wire['payload_digest'] !== 'sha256:'.hash('sha256', $sale->canonicalBytes())) {
            throw new OfflineSaleConflict('DIGEST_MISMATCH', 'Offline sale payload digest does not match the saved event.');
        }

        return $sale;
    }

    public static function fromEvent(mixed $value): self
    {
        if (is_array($value) && ($value['event_version'] ?? null) === 1) {
            throw new OfflineSaleConflict('UNSUPPORTED_EVENT_VERSION', 'This offline sale event version cannot be submitted.');
        }
        $event = self::object($value, [
            'client_event_id', 'event_version', 'company_id', 'actor_id', 'device_id',
            'device_lease_id', 'captured_at', 'customer_id', 'currency', 'warehouse_id',
            'cash_account_id', 'catalog_snapshot_version', 'local_receipt_number', 'lines', 'payment',
        ]);
        if ($event['event_version'] !== 2) {
            throw new OfflineSaleConflict('UNSUPPORTED_EVENT_VERSION', 'This offline sale event version cannot be submitted.');
        }

        if (! is_string($event['device_lease_id']) || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $event['device_lease_id']) !== 1
            || ! is_string($event['catalog_snapshot_version']) || (trim($event['catalog_snapshot_version']) === '' || mb_strlen($event['catalog_snapshot_version']) > 64)
            || ! is_string($event['local_receipt_number']) || trim($event['local_receipt_number']) === '' || mb_strlen($event['local_receipt_number']) > 64) {
            throw new InvalidArgumentException('Invalid offline lease, snapshot version, or local receipt number.');
        }
        self::captureTime($event['captured_at']);

        $payment = self::object($event['payment'], [
            'mode', 'payment_method', 'cash_account_id', 'amount_tendered', 'amount_settled', 'change_returned',
        ]);
        if ($payment['mode'] !== 'full' || $payment['payment_method'] !== 'cash'
            || $payment['cash_account_id'] !== $event['cash_account_id']) {
            throw new InvalidArgumentException('Offline sales require full settlement in the configured cash account.');
        }
        foreach (['amount_tendered', 'amount_settled', 'change_returned'] as $field) {
            self::decimal($payment[$field]);
        }

        if (! is_array($event['lines']) || ! array_is_list($event['lines']) || $event['lines'] === [] || count($event['lines']) > 100) {
            throw new InvalidArgumentException('Offline sale requires between 1 and 100 lines.');
        }
        $lineSum = BigDecimal::zero();
        foreach ($event['lines'] as $line) {
            $line = self::object($line, [
                'product_id', 'product_unit_id', 'unit_id', 'conversion_to_base', 'quantity',
                'unit_price', 'tax_rate_id', 'tax_rate', 'tax_inclusive', 'tax_amount', 'line_total',
            ]);
            if ($line['unit_id'] !== $line['product_unit_id']) {
                throw new InvalidArgumentException('Offline product unit identifiers disagree.');
            }
            $tax = self::decimal($line['tax_amount']);
            $total = self::decimal($line['line_total']);
            if (! $total->isPositive() || $tax->isGreaterThan($total)
                || ($line['tax_rate_id'] === null && (! $tax->isZero() || $line['tax_rate'] !== '0.000000'))) {
                throw new InvalidArgumentException('Invalid offline line total or tax amount.');
            }
            $lineSum = $lineSum->plus($total);
        }
        if (! PaymentSettlementValidator::verifyPaymentDecimalConsistency($payment, (string) $lineSum, 6)) {
            throw new InvalidArgumentException('Offline cash tender, change, and line totals do not reconcile.');
        }

        $sale = new self($event);
        $sale->cashProfile(6);

        return $sale;
    }

    public function canonicalBytes(): string
    {
        return CanonicalJsonSerializer::serialize($this->event);
    }

    /** @return array<string, mixed> */
    public function cashProfile(int $minorUnits): array
    {
        $profile = array_intersect_key($this->event, array_flip([
            'actor_id', 'client_event_id', 'company_id', 'currency', 'customer_id', 'device_id', 'warehouse_id',
        ]));
        $profile['currency_minor_units'] = $minorUnits;
        $profile['lines'] = array_map(
            static fn (array $line): array => array_diff_key($line, array_flip(['unit_id', 'tax_amount', 'line_total'])),
            $this->event['lines'],
        );
        $payment = $this->event['payment'];
        if (! PaymentSettlementValidator::verifyPaymentDecimalConsistency($payment, $payment['amount_settled'], $minorUnits)) {
            throw new InvalidArgumentException('Offline cash tender or change cannot be represented at the lease currency precision.');
        }
        $profile['payment'] = [
            'settlement_mode' => 'full',
            'payment_method' => 'cash',
            'cash_account_id' => $this->event['cash_account_id'],
            'amount' => $payment['amount_settled'],
        ];
        OnlineSaleIntentProfile::validateNormativeProfile($profile);

        return $profile;
    }

    public static function captureTime(mixed $value): DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/', $value) !== 1) {
            throw new InvalidArgumentException('Invalid offline capture timestamp.');
        }
        $time = new DateTimeImmutable($value);
        if ($time->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)) {
            throw new InvalidArgumentException('Invalid offline capture timestamp.');
        }

        return $time;
    }

    /** @param list<string> $keys
     * @return array<string, mixed>
     */
    private static function object(mixed $value, array $keys): array
    {
        if (! is_array($value) || count($value) !== count($keys) || array_diff($keys, array_keys($value)) !== []) {
            throw new InvalidArgumentException('Invalid offline sale payload fields.');
        }

        return $value;
    }

    private static function decimal(mixed $value): BigDecimal
    {
        if (! is_string($value) || preg_match(self::DECIMAL, $value) !== 1) {
            throw new InvalidArgumentException('Invalid offline sale decimal.');
        }

        return BigDecimal::of($value);
    }
}
