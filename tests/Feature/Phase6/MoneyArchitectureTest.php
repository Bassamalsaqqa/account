<?php

declare(strict_types=1);

namespace Tests\Feature\Phase6;

use Tests\TestCase;

final class MoneyArchitectureTest extends TestCase
{
    public function test_money_code_uses_exact_values_and_only_canonical_gl_writers(): void
    {
        $files = array_merge(glob(app_path('Actions/Money/*.php')), glob(app_path('Services/Money/*.php')), glob(app_path('Domain/Money/Queries/*.php')));
        $violations = [];
        foreach ($files as $file) {
            $source = file_get_contents($file);
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && ($token[0] === T_DNUMBER || $token[0] === T_DOUBLE_CAST || ($token[0] === T_STRING && in_array(strtolower($token[1]), ['floatval', 'doubleval'], true)))) {
                    $violations[] = $file.':'.$token[2].' floating point';
                }
            }
            if (preg_match('/(?:PostingBatch|PostingLine|StockMovement|InventoryBalance)::(?:create|insert|upsert)\s*\(/', $source)) {
                $violations[] = $file.' direct economic writer';
            }
        }
        $this->assertSame([], $violations);
    }

    public function test_only_concrete_money_actions_activate_the_runtime_boundary(): void
    {
        $callers = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if (preg_match('/\$scope->within\(/', file_get_contents($file->getPathname()))) {
                $callers[] = str_replace('\\', '/', substr($file->getPathname(), strlen(app_path()) + 1));
            }
        }
        sort($callers);
        $this->assertSame(['Actions/Money/IssueCheckAction.php', 'Actions/Money/PostMoneyTransferAction.php', 'Actions/Money/ReceiveCheckAction.php', 'Actions/Money/ReverseMoneyTransferAction.php', 'Actions/Money/TransitionCheckAction.php'], $callers);
        foreach (glob(app_path('Livewire/Pages/Money/*.php')) as $file) {
            $this->assertDoesNotMatchRegularExpression('/(?:AccountingPostingService|PostingLine|PostingBatch)::|->multipliedBy\(/', file_get_contents($file));
        }
    }
}
