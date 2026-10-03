<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Domain\Purchasing\PreparedPurchaseDraft;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseLineLot;

final class PurchaseDraftWriter
{
    /** Caller owns the Company-first transaction and authorization. */
    public function replaceLines(Purchase $purchase, PreparedPurchaseDraft $draft): void
    {
        $purchase->assertMutableDraft();
        foreach ($purchase->lines()->where('company_id', $purchase->company_id)->get() as $line) {
            foreach ($line->lots()->where('company_id', $purchase->company_id)->get() as $lot) {
                $lot->delete();
            }
            $line->delete();
        }
        foreach ($draft->lines as $prepared) {
            $line = PurchaseLine::create($prepared['attributes'] + ['company_id' => $purchase->company_id, 'purchase_id' => $purchase->id]);
            foreach ($prepared['lots'] as $attributes) {
                PurchaseLineLot::create($attributes + ['company_id' => $purchase->company_id, 'purchase_line_id' => $line->id]);
            }
        }
    }
}
