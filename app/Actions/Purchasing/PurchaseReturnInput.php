<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Support\Mobile\Receipt\NativeWriteContextResolver;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Shared side-effect-free validation for preview and canonical execution. */
final class PurchaseReturnInput
{
    /** @return list<array{purchase_line_id: ?string, product_id: ?string, quantity: string}> */
    public static function normalize(mixed $lines): array
    {
        if (! is_array($lines) || $lines === [] || ! array_is_list($lines)) {
            throw new InvalidArgumentException('Purchase return requires at least one line item.');
        }

        // Canonical payload construction for deterministic request hashing
        $normalizedLines = [];
        foreach ($lines as $idx => $lineData) {
            if (! is_array($lineData)) {
                throw new InvalidArgumentException("Line [{$idx}] must be an array.");
            }
            $pLineId = $lineData['purchase_line_id'] ?? null;
            $prodId = $lineData['product_id'] ?? null;
            if ($pLineId === null && $prodId === null) {
                throw new InvalidArgumentException("Line [{$idx}] must specify purchase_line_id or product_id.");
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
                'purchase_line_id' => is_string($pLineId) ? trim($pLineId) : null,
                'product_id' => is_string($prodId) ? trim($prodId) : null,
                'quantity' => is_string($rawQty) ? trim($rawQty) : $qtyStr,
            ];
        }

        return $normalizedLines;
    }

    /** @param array{purchase_line_id: ?string, product_id: ?string, quantity: string} $lineData */
    public static function resolveLine(Purchase $purchase, array $lineData): PurchaseLine
    {
        $pLineId = $lineData['purchase_line_id'];
        $prodId = $lineData['product_id'];

        if ($pLineId !== null) {
            /** @var PurchaseLine|null $origLine */
            $origLine = $purchase->lines->firstWhere('public_id', $pLineId);
            if ($origLine === null) {
                throw new InvalidArgumentException("Purchase line [{$pLineId}] does not belong to this purchase bill.");
            }
            if ($prodId !== null && ($origLine->product === null || (string) $origLine->product->public_id !== $prodId)) {
                throw new InvalidArgumentException("Supplied product [{$prodId}] does not match purchase line [{$pLineId}].");
            }
        } else {
            $matches = $purchase->lines->filter(fn (PurchaseLine $line): bool => $line->product !== null && (string) $line->product->public_id === $prodId);
            if ($matches->isEmpty()) {
                throw new InvalidArgumentException("Product [{$prodId}] does not belong to this purchase bill.");
            }
            if ($matches->count() > 1) {
                throw new InvalidArgumentException("Ambiguous return line for product [{$prodId}]. Multiple purchase lines exist; specify purchase_line_id.");
            }
            $origLine = $matches->first();
        }

        return $origLine;
    }
}
