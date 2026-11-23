<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Support\Mobile\Receipt\NativeWriteContextResolver;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Shared side-effect-free validation for preview and canonical execution. */
final class SalesReturnInput
{
    /** @return list<array{sales_invoice_line_id: ?string, product_id: ?string, quantity: string}> */
    public static function normalize(mixed $lines): array
    {
        if (! is_array($lines) || $lines === [] || ! array_is_list($lines)) {
            throw new InvalidArgumentException('Sales return requires at least one line item.');
        }

        // Canonical payload construction for deterministic request hashing
        $normalizedLines = [];
        foreach ($lines as $idx => $lineData) {
            if (! is_array($lineData)) {
                throw new InvalidArgumentException("Line [{$idx}] must be an array.");
            }
            $pLineId = $lineData['sales_invoice_line_id'] ?? null;
            $prodId = $lineData['product_id'] ?? null;
            if ($pLineId === null && $prodId === null) {
                throw new InvalidArgumentException("Line [{$idx}] must specify sales_invoice_line_id or product_id.");
            }
            foreach ([$pLineId, $prodId] as $publicId) {
                if ($publicId !== null && (! is_string($publicId) || ! Str::isUlid($publicId))) {
                    throw new InvalidArgumentException("Line [{$idx}] contains an invalid identifier.");
                }
            }
            $rawQty = $lineData['quantity'] ?? null;
            $qtyStr = NativeWriteContextResolver::validateDecimalString(
                $rawQty,
                "line [{$idx}] quantity",
                6,
                true
            );
            $normalizedLines[] = [
                'sales_invoice_line_id' => is_string($pLineId) ? trim($pLineId) : null,
                'product_id' => is_string($prodId) ? trim($prodId) : null,
                'quantity' => is_string($rawQty) ? trim($rawQty) : $qtyStr,
            ];
        }

        return $normalizedLines;
    }

    /** @param array{sales_invoice_line_id: ?string, product_id: ?string, quantity: string} $lineData */
    public static function resolveLine(SalesInvoice $invoice, array $lineData): SalesInvoiceLine
    {
        $pLineId = $lineData['sales_invoice_line_id'];
        $prodId = $lineData['product_id'];

        if ($pLineId !== null) {
            /** @var SalesInvoiceLine|null $origLine */
            $origLine = $invoice->lines->firstWhere('public_id', $pLineId);
            if ($origLine === null) {
                throw new InvalidArgumentException("Sales invoice line [{$pLineId}] does not belong to this sales invoice.");
            }
            if ($prodId !== null && ($origLine->product === null || (string) $origLine->product->public_id !== $prodId)) {
                throw new InvalidArgumentException("Supplied product [{$prodId}] does not match invoice line [{$pLineId}].");
            }
        } else {
            $matches = $invoice->lines->filter(fn (SalesInvoiceLine $line): bool => $line->product !== null && (string) $line->product->public_id === $prodId);
            if ($matches->isEmpty()) {
                throw new InvalidArgumentException("Product [{$prodId}] does not belong to this sales invoice.");
            }
            if ($matches->count() > 1) {
                throw new InvalidArgumentException("Ambiguous return line for product [{$prodId}]. Multiple sales invoice lines exist; specify sales_invoice_line_id.");
            }
            $origLine = $matches->first();
        }

        return $origLine;
    }
}
