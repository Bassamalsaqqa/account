<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5C;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PurchasePostingArchitectureTest extends TestCase
{
    public static function canonicalBoundaries(): array
    {
        return [
            ['recordPurchaseReceipt', 'Services/Inventory/InventoryMovementService.php'],
            ['withinCanonicalPosting', 'Services/Purchasing/PurchasePostingScope.php'],
        ];
    }

    #[DataProvider('canonicalBoundaries')]
    public function test_only_purchase_posting_can_enter_the_canonical_runtime_boundary(string $method, string $definition): void
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
        $this->assertSame([str_replace('\\', '/', app_path('Actions/Purchasing/PostPurchaseAction.php'))], $callers);
        $this->assertSame([str_replace('\\', '/', app_path($definition))], $definitions);
    }

    public function test_purchasing_uses_exact_values_and_canonical_stock_and_accounting_writers(): void
    {
        $files = array_merge(
            glob(app_path('Actions/Purchasing/*.php')),
            glob(app_path('Services/Purchasing/*.php')),
            glob(app_path('Domain/Purchasing/*.php')),
            [app_path('Models/Purchase.php'), app_path('Models/PurchaseLine.php'), app_path('Models/PurchaseLineLot.php')],
        );
        // These reviewed readers use aggregate/provenance queries, never SQL writes.
        $sqlReaders = [
            app_path('Services/Purchasing/PurchasePayableAsOf.php'),
            app_path('Services/Purchasing/PurchasingDocumentBuilder.php'),
        ];
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
                if (! in_array($file, $sqlReaders, true)) {
                    $violations[] = $file.' bypasses economic models';
                } elseif (preg_match('/->(?:insert|insertGetId|insertOrIgnore|upsert|update|updateOrInsert|delete|truncate|increment|decrement|incrementEach|decrementEach)\s*\(/', $source)
                    || preg_match('/DB::(?:statement|unprepared|affectingStatement|insert|update|delete)\s*\(/', $source)) {
                    $violations[] = $file.' mutates SQL from a read-only boundary';
                }
            }
        }
        $this->assertSame([], $violations);
        $action = file_get_contents(app_path('Actions/Purchasing/PostPurchaseAction.php'));
        $this->assertStringContainsString('InventoryMovementService::class', $action);
        $this->assertStringContainsString('AccountingPostingService::class', $action);
    }
}
