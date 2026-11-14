<?php

declare(strict_types=1);

namespace App\Support\Mobile\Contract\Revision3;

use InvalidArgumentException;

/**
 * Public Wire DTO Projection for Mobile V2 Online Sales.
 *
 * Enforces strict schema parity with frozen Revision 3 contract schemas:
 * - Commit (fresh & replay): sale-commit-response.json
 * - Status lookup: sale-event-status-response.json
 *
 * Strictly projects ONLY allowlisted public fields. Zero internal SQL IDs
 * (sales_invoice_id, customer_payment_id, customer_id), zero duplicate aliases,
 * and zero undocumented fields.
 */
final class OnlineSaleWireResponseProjection
{
    private const string ULID_REGEX = '/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/';

    private const string UUID_REGEX = '/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/';

    private const string POSITIVE_DECIMAL_REGEX = '/\A(?:0\.(?=[0-9]{1,6}\z)[0-9]*[1-9][0-9]*|[1-9][0-9]{0,13}(?:\.[0-9]{1,6})?)\z/';

    private const string NON_NEGATIVE_DECIMAL_REGEX = '/\A(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?\z/';

    private const string DATE_TIME_REGEX = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/';

    /**
     * Project canonical commit wire DTO matching sale-commit-response.json.
     * Guaranteed to produce ONLY allowlisted public fields with schema-parity.
     *
     * @param  array<string, mixed>  $terminalResult
     * @return array{
     *     client_event_id: string,
     *     status: string,
     *     invoice_id: string,
     *     invoice_number: string,
     *     payment_id: string|null,
     *     payment_receipt_number: string|null,
     *     grand_total: string,
     *     amount_paid: string,
     *     change_returned: string,
     *     posted_at: string
     * }
     */
    public static function projectCommitWireDto(string $clientEventId, array $terminalResult): array
    {
        if (preg_match(self::UUID_REGEX, $clientEventId) !== 1) {
            throw new InvalidArgumentException("Invalid client event ID UUID format [{$clientEventId}].");
        }

        $rawStatus = (string) ($terminalResult['status'] ?? '');
        $status = in_array($rawStatus, ['posted', 'completed'], true) ? 'posted' : $rawStatus;
        if ($status !== 'posted') {
            throw new InvalidArgumentException("Invalid terminal commit status [{$status}]; expected 'posted'.");
        }

        $invoiceId = (string) ($terminalResult['invoice_id'] ?? $terminalResult['sales_invoice_public_id'] ?? $terminalResult['invoice_public_id'] ?? '');
        if (preg_match(self::ULID_REGEX, $invoiceId) !== 1) {
            throw new InvalidArgumentException("Invalid public invoice ULID [{$invoiceId}].");
        }

        $invoiceNumber = (string) ($terminalResult['invoice_number'] ?? '');
        if ($invoiceNumber === '' || strlen($invoiceNumber) > 64) {
            throw new InvalidArgumentException("Invalid invoice number length [{$invoiceNumber}].");
        }

        $paymentId = $terminalResult['payment_id'] ?? $terminalResult['customer_payment_public_id'] ?? $terminalResult['payment_public_id'] ?? null;
        if ($paymentId !== null) {
            $paymentId = (string) $paymentId;
            if (preg_match(self::ULID_REGEX, $paymentId) !== 1) {
                throw new InvalidArgumentException("Invalid public payment ULID [{$paymentId}].");
            }
        }

        $paymentReceiptNumber = $terminalResult['payment_receipt_number'] ?? null;
        if ($paymentReceiptNumber !== null) {
            $paymentReceiptNumber = (string) $paymentReceiptNumber;
            if (strlen($paymentReceiptNumber) > 64) {
                throw new InvalidArgumentException('Payment receipt number exceeds 64 characters.');
            }
        }

        $grandTotal = (string) ($terminalResult['grand_total'] ?? $terminalResult['total_amount'] ?? '');
        if (preg_match(self::POSITIVE_DECIMAL_REGEX, $grandTotal) !== 1) {
            throw new InvalidArgumentException("Invalid scalar decimal format for grand_total [{$grandTotal}].");
        }

        $amountPaid = (string) ($terminalResult['amount_paid'] ?? $terminalResult['paid_amount'] ?? $terminalResult['amount'] ?? $grandTotal);
        if (preg_match(self::NON_NEGATIVE_DECIMAL_REGEX, $amountPaid) !== 1) {
            throw new InvalidArgumentException("Invalid scalar decimal format for amount_paid [{$amountPaid}].");
        }

        $changeReturned = (string) ($terminalResult['change_returned'] ?? '0.000000');
        if (preg_match(self::NON_NEGATIVE_DECIMAL_REGEX, $changeReturned) !== 1) {
            throw new InvalidArgumentException("Invalid scalar decimal format for change_returned [{$changeReturned}].");
        }

        $postedAt = (string) ($terminalResult['posted_at'] ?? '');
        if ($postedAt === '' || preg_match(self::DATE_TIME_REGEX, $postedAt) !== 1 || strtotime($postedAt) === false) {
            $postedAt = now()->toIso8601ZuluString();
        }

        return [
            'client_event_id' => $clientEventId,
            'status' => 'posted',
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoiceNumber,
            'payment_id' => $paymentId,
            'payment_receipt_number' => $paymentReceiptNumber,
            'grand_total' => $grandTotal,
            'amount_paid' => $amountPaid,
            'change_returned' => $changeReturned,
            'posted_at' => $postedAt,
        ];
    }

    /**
     * Project canonical status wire DTO matching sale-event-status-response.json.
     *
     * @param  array<string, mixed>|null  $canonicalResult
     * @return array{
     *     client_event_id: string,
     *     company_id: string,
     *     status: string,
     *     payload_digest: string|null,
     *     official_invoice: array{
     *         invoice_id: string,
     *         invoice_number: string,
     *         payment_id: string|null,
     *         payment_receipt_number: string|null,
     *         grand_total: string,
     *         posted_at: string
     *     }|null,
     *     conflict: array<string, mixed>|null,
     *     queried_at: string
     * }
     */
    public static function projectStatusWireDto(
        string $clientEventId,
        string $companyPublicId,
        ?string $payloadDigest,
        ?array $canonicalResult,
        ?string $queriedAt = null
    ): array {
        if (preg_match(self::UUID_REGEX, $clientEventId) !== 1) {
            throw new InvalidArgumentException("Invalid client event ID UUID format [{$clientEventId}].");
        }

        if (preg_match(self::ULID_REGEX, $companyPublicId) !== 1) {
            throw new InvalidArgumentException("Invalid company public ULID [{$companyPublicId}].");
        }

        $formattedDigest = null;
        if ($payloadDigest !== null) {
            $rawHash = str_starts_with($payloadDigest, 'sha256:') ? substr($payloadDigest, 7) : $payloadDigest;
            if (! preg_match('/\A[0-9a-f]{64}\z/', $rawHash)) {
                throw new InvalidArgumentException("Invalid payload digest hash [{$payloadDigest}].");
            }
            $formattedDigest = 'sha256:'.$rawHash;
        }

        $nowIso = $queriedAt ?? now()->toIso8601ZuluString();
        if (preg_match(self::DATE_TIME_REGEX, $nowIso) !== 1 || strtotime($nowIso) === false) {
            $nowIso = now()->toIso8601ZuluString();
        }

        if ($canonicalResult === null) {
            return [
                'client_event_id' => $clientEventId,
                'company_id' => $companyPublicId,
                'status' => 'unknown',
                'payload_digest' => $formattedDigest,
                'official_invoice' => null,
                'conflict' => null,
                'queried_at' => $nowIso,
            ];
        }

        $commitDto = self::projectCommitWireDto($clientEventId, $canonicalResult);

        return [
            'client_event_id' => $clientEventId,
            'company_id' => $companyPublicId,
            'status' => 'posted',
            'payload_digest' => $formattedDigest,
            'official_invoice' => [
                'invoice_id' => $commitDto['invoice_id'],
                'invoice_number' => $commitDto['invoice_number'],
                'payment_id' => $commitDto['payment_id'],
                'payment_receipt_number' => $commitDto['payment_receipt_number'],
                'grand_total' => $commitDto['grand_total'],
                'posted_at' => $commitDto['posted_at'],
            ],
            'conflict' => null,
            'queried_at' => $nowIso,
        ];
    }
}
