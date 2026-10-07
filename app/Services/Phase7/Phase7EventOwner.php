<?php

declare(strict_types=1);

namespace App\Services\Phase7;

interface Phase7EventOwner
{
    public function ownsPhase7Scope(Phase7EventScope $scope): bool;
}
