<?php

declare(strict_types=1);

namespace Tests\Feature\Phase3;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

class InventoryExactDecimalArchitectureTest extends TestCase
{
    /**
     * Verifies that no float casts or float conversion functions are used
     * in authoritative inventory domains, services, actions, and models.
     */
    public function test_authoritative_inventory_code_contains_no_float_operations(): void
    {
        $scanPaths = [
            app_path('Domain/Inventory'),
            app_path('Services/Inventory'),
            app_path('Actions/Inventory'),
            app_path('Models/StockMovement.php'),
            app_path('Models/InventoryBalance.php'),
            app_path('Models/InventoryCostState.php'),
            app_path('Models/InventoryOperation.php'),
            app_path('Models/ProductUnit.php'),
            app_path('Models/ProductBarcode.php'),
            app_path('Models/Lot.php'),
        ];

        $filesToScan = [];

        foreach ($scanPaths as $path) {
            if (is_file($path)) {
                $filesToScan[] = $path;
            } elseif (is_dir($path)) {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
                /** @var SplFileInfo $file */
                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getExtension() === 'php') {
                        $filesToScan[] = $file->getPathname();
                    }
                }
            }
        }

        $violations = [];

        foreach ($filesToScan as $filePath) {
            $content = file_get_contents($filePath);
            if ($content === false) {
                continue;
            }

            $tokens = token_get_all($content);
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];

                if (is_array($token)) {
                    $tokenId = $token[0];
                    $tokenValue = $token[1];
                    $tokenLine = $token[2];

                    // Ignore comments and docblocks
                    if ($tokenId === T_COMMENT || $tokenId === T_DOC_COMMENT) {
                        continue;
                    }

                    // Check for (float) / (double) / (real) cast
                    if ($tokenId === \T_DOUBLE_CAST) {
                        $violations[] = sprintf(
                            '%s:%d - Forbidden float cast: %s',
                            $filePath,
                            $tokenLine,
                            trim($tokenValue)
                        );
                    }

                    // Check for floatval() or doubleval() calls
                    if ($tokenId === T_STRING && in_array(strtolower($tokenValue), ['floatval', 'doubleval'], true)) {
                        $violations[] = sprintf(
                            '%s:%d - Forbidden float function: %s()',
                            $filePath,
                            $tokenLine,
                            $tokenValue
                        );
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Authoritative inventory code must never use float operations:\n".implode("\n", $violations)
        );
    }
}
