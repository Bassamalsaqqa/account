<?php

declare(strict_types=1);

namespace App\Support\Mobile\Receipt;

use App\Models\Company;
use App\Models\MobileDevice;
use App\Models\User;

interface FinancialExecutionAdapterInterface
{
    /**
     * Authorize operation ('execute', 'replay', 'lookup') for the authenticated actor and device.
     * Throws FinancialExecutionDeniedException or AuthorizationException if unauthorized.
     *
     * @param  string  $operation  'execute', 'replay', or 'lookup'
     * @param  array<string, mixed>|null  $payload  Decoded economic payload if available
     */
    public function authorize(
        Company $company,
        User $actor,
        MobileDevice $device,
        string $operation,
        ?array $payload = null
    ): void;

    /**
     * Execute canonical business operations inside the atomic write transaction under company locks.
     *
     * @param  Company  $company  Locked company model
     * @param  User  $actor  Authenticated staff user
     * @param  MobileDevice  $device  Authenticated mobile device
     * @param  array<string, mixed>  $payload  Decoded economic payload
     * @param  string  $idempotencySuffix  Deterministic idempotency suffix
     * @return array{
     *     sales_invoice_id: int|null,
     *     sales_invoice_public_id: string|null,
     *     customer_payment_id: int|null,
     *     customer_payment_public_id: string|null,
     *     command_receipt_id: int|null,
     *     terminal_canonical_result: array<string, mixed>
     * }
     */
    public function execute(
        Company $company,
        User $actor,
        MobileDevice $device,
        array $payload,
        string $idempotencySuffix
    ): array;
}
