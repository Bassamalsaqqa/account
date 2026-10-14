<?php

declare(strict_types=1);

namespace App\Services\Inventory;

interface StockEventOwner
{
    public function ownsStockEvent(StockEventScope $scope): bool;
}
