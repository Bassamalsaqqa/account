<?php

declare(strict_types=1);

namespace App\Services\Money;

interface MoneyEventOwner
{
    public function ownsScope(MoneyEventScope $scope): bool;
}
