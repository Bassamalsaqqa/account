<?php

declare(strict_types=1);

namespace App\Support\Mobile\Receipt;

use App\Models\Company;
use App\Models\MobileDevice;
use App\Models\User;
use App\Support\Mobile\Receipt\Exceptions\FinancialExecutionDeniedException;

final class DenyFinancialExecutionAdapter implements FinancialExecutionAdapterInterface
{
    /**
     * {@inheritDoc}
     */
    public function authorize(
        Company $company,
        User $actor,
        MobileDevice $device,
        string $operation,
        ?array $payload = null
    ): void {
        throw new FinancialExecutionDeniedException(
            'Financial authority is unresolved on Mobile V2. Bearer token proves staff/device identity only, never financial posting permission or financial read authority.'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function execute(
        Company $company,
        User $actor,
        MobileDevice $device,
        array $payload,
        string $idempotencySuffix
    ): array {
        $this->authorize($company, $actor, $device, 'execute', $payload);

        throw new FinancialExecutionDeniedException(
            'Financial authority is unresolved on Mobile V2. Bearer token proves staff/device identity only, never financial posting permission.'
        );
    }
}
