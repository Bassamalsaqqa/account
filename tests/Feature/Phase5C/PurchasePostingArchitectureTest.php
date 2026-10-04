<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5C;

use Tests\TestCase;

class PurchasePostingArchitectureTest extends TestCase
{
    public function test_purchasing_uses_exact_values_and_canonical_stock_and_accounting_writers(): void
    {
        $files = array_merge(
            glob(app_path('Actions/Purchasing/*.php')),
            glob(app_path('Services/Purchasing/*.php')),
            glob(app_path('Domain/Purchasing/*.php')),
            [app_path('Models/Purchase.php'), app_path('Models/PurchaseLine.php'), app_path('Models/PurchaseLineLot.php')],
        );
        $violations = [];
        foreach ($files as $file) {
            $source = file_get_contents($file);
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && ($token[0] === T_DOUBLE_CAST || $token[0] === T_DNUMBER
                    || ($token[0] === T_STRING && in_array(strtolower($token[1]), ['floatval', 'doubleval'], true)))) {
                    $violations[] = $file.':'.$token[2].' floating point';
                }
            }
            foreach (['StockMovement', 'InventoryLot', 'InventoryBalance', 'InventoryCostState', 'PostingBatch', 'PostingLine'] as $model) {
                if (preg_match('/'.preg_quote($model, '/').'::(?:create|insert|upsert)\s*\(/', $source)) {
                    $violations[] = $file.' bypasses canonical '.$model.' writer';
                }
            }
            if (preg_match('/DB::table\s*\(/', $source)) {
                $violations[] = $file.' bypasses economic models';
            }
        }
        $this->assertSame([], $violations);
        $action = file_get_contents(app_path('Actions/Purchasing/PostPurchaseAction.php'));
        $this->assertStringContainsString('InventoryMovementService::class', $action);
        $this->assertStringContainsString('AccountingPostingService::class', $action);
    }
}
