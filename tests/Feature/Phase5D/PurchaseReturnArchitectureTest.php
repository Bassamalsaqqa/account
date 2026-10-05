<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5D;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PurchaseReturnArchitectureTest extends TestCase
{
    public static function canonicalBoundaries(): array
    {
        return [
            ['recordPurchaseReturnIssue', 'Services/Inventory/InventoryMovementService.php'],
            ['withinCanonicalReturnPosting', 'Services/Purchasing/PurchaseReturnPostingScope.php'],
        ];
    }

    #[DataProvider('canonicalBoundaries')]
    public function test_only_purchase_return_posting_can_enter_the_canonical_runtime_boundary(string $method, string $definition): void
    {
        $callers = [];
        $definitions = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $previous = null;
            foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (is_array($token) && $token[0] === T_STRING && strcasecmp($token[1], $method) === 0) {
                    $path = str_replace('\\', '/', $file->getPathname());
                    if (is_array($previous) && $previous[0] === T_FUNCTION) {
                        $definitions[] = $path;
                    } else {
                        $callers[] = $path;
                    }
                }
                $previous = $token;
            }
        }
        $this->assertSame([str_replace('\\', '/', app_path('Actions/Purchasing/PostPurchaseReturnAction.php'))], $callers);
        $this->assertSame([str_replace('\\', '/', app_path($definition))], $definitions);
    }

    public function test_purchase_return_uses_exact_values_and_canonical_stock_and_accounting_writers(): void
    {
        $files = [
            app_path('Actions/Purchasing/CreatePurchaseReturnDraftAction.php'),
            app_path('Actions/Purchasing/UpdatePurchaseReturnDraftAction.php'),
            app_path('Actions/Purchasing/PostPurchaseReturnAction.php'),
            app_path('Services/Purchasing/PurchaseReturnAmounts.php'),
            app_path('Services/Purchasing/HistoricalPurchaseReceiptValue.php'),
            app_path('Services/Purchasing/PurchaseReturnValuation.php'),
            app_path('Services/Purchasing/PurchaseReturnStockProvenance.php'),
            app_path('Services/Purchasing/PurchaseReturnPostingScope.php'),
            app_path('Services/Purchasing/PurchaseReturnPostingCommandBuilder.php'),
            app_path('Services/Purchasing/PurchaseReturnReadModel.php'),
            app_path('Models/PurchaseReturn.php'),
            app_path('Models/PurchaseReturnLine.php'),
            app_path('Models/PurchaseReturnAllocation.php'),
        ];

        $violations = [];
        foreach ($files as $file) {
            if (! file_exists($file)) {
                continue;
            }
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

        $action = file_get_contents(app_path('Actions/Purchasing/PostPurchaseReturnAction.php'));
        $this->assertStringContainsString('InventoryMovementService::class', $action);
        $this->assertStringContainsString('AccountingPostingService::class', $action);
    }
}
