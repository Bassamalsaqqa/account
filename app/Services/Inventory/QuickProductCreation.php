<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Exceptions\IdempotencyConflictException;
use App\Models\Company;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Sales\SalesActorGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class QuickProductCreation
{
    /**
     * Valid form permissions authorized to trigger quick product creation.
     */
    private const array VALID_FORM_PERMISSIONS = [
        'sales.invoice.create',
        'sales.invoice.edit_draft',
        'sales.quote.create',
        'sales.quote.edit',
        'purchasing.purchase.create',
        'purchasing.purchase.edit_draft',
    ];

    /**
     * Allowed payload keys for quick product creation.
     */
    private const array ALLOWED_PAYLOAD_KEYS = [
        'name_ar',
        'name_en',
        'sku',
        'base_unit_id',
        'track_expiry',
    ];

    /**
     * Execute quick product creation under strict idempotency and business-event boundaries.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException
     * @throws ValidationException
     * @throws IdempotencyConflictException
     */
    public function execute(
        Company $company,
        User $actor,
        string $requestKey,
        array $data,
        string $formPermission
    ): Product {
        if (! Str::isUuid($requestKey)) {
            throw new InvalidArgumentException('The request key must be a valid UUID.');
        }

        if (! in_array($formPermission, self::VALID_FORM_PERMISSIONS, true)) {
            throw new InvalidArgumentException("Invalid form permission: {$formPermission}.");
        }

        $unexpectedKeys = array_diff(array_keys($data), self::ALLOWED_PAYLOAD_KEYS);
        if ($unexpectedKeys !== []) {
            throw ValidationException::withMessages([
                'payload' => ['Unexpected payload keys detected: '.implode(', ', $unexpectedKeys)],
            ]);
        }

        $nameAr = isset($data['name_ar']) && is_string($data['name_ar'])
            ? trim($data['name_ar'])
            : ($data['name_ar'] ?? null);

        $nameEn = isset($data['name_en']) && is_string($data['name_en'])
            ? trim($data['name_en'])
            : ($data['name_en'] ?? null);

        if ($nameAr === '') {
            $nameAr = null;
        }

        if ($nameEn === '') {
            $nameEn = null;
        }

        $sku = isset($data['sku']) && is_string($data['sku'])
            ? trim($data['sku'])
            : ($data['sku'] ?? null);

        $baseUnitId = $data['base_unit_id'] ?? null;
        $trackExpiry = $data['track_expiry'] ?? false;

        $normalized = [
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'sku' => $sku,
            'base_unit_id' => $baseUnitId,
            'track_expiry' => $trackExpiry,
        ];

        $validator = Validator::make($normalized, [
            'name_ar' => ['nullable', 'string', 'max:255', 'required_without:name_en'],
            'name_en' => ['nullable', 'string', 'max:255', 'required_without:name_ar'],
            'sku' => ['required', 'string', 'max:64'],
            'base_unit_id' => ['required', 'integer'],
            'track_expiry' => ['boolean'],
        ], [
            'name_ar.required_without' => __('inventory.product_name_required'),
            'name_en.required_without' => __('inventory.product_name_required'),
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $normalized['base_unit_id'] = (int) $normalized['base_unit_id'];
        $normalized['track_expiry'] = (bool) $normalized['track_expiry'];
        $normalized['product_type'] = Product::TYPE_STOCK;
        $normalized['track_stock'] = true;
        $normalized['active'] = true;

        $payloadToHash = $normalized;
        ksort($payloadToHash);

        $requestHash = hash('sha256', (string) json_encode($payloadToHash, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($company, $actor, $requestKey, $requestHash, $normalized, $formPermission): Product {
            $guard = app(SalesActorGuard::class);
            $company = $guard->lockAndAuthorize((int) $company->id, $actor, $formPermission);
            $guard->lockAndAuthorize((int) $company->id, $actor, 'inventory.product.manage');

            if (str_starts_with($formPermission, 'purchasing.')) {
                $guard->lockAndAuthorize((int) $company->id, $actor, 'purchasing.cost.view');
            }

            $receipt = DB::table('product_creation_requests')
                ->where('company_id', $company->id)
                ->where('user_id', $actor->id)
                ->where('request_key', $requestKey)
                ->lockForUpdate()
                ->first();

            if ($receipt !== null) {
                if (! hash_equals($receipt->request_hash, $requestHash)) {
                    throw new IdempotencyConflictException('Mismatched request hash for idempotent product creation request.');
                }

                return Product::where('company_id', $company->id)
                    ->where('active', true)
                    ->findOrFail($receipt->product_id);
            }

            $errors = [];

            $unit = Unit::where('company_id', $company->id)
                ->where('active', true)
                ->where('id', $normalized['base_unit_id'])
                ->lockForUpdate()
                ->first();

            if ($unit === null) {
                $errors['base_unit_id'] = [__('quick_product.invalid_unit')];
            }

            $skuExists = Product::withTrashed()
                ->where('company_id', $company->id)
                ->where('sku', $normalized['sku'])
                ->lockForUpdate()
                ->exists();

            if ($skuExists) {
                $errors['sku'] = [__('quick_product.sku_taken')];
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $product = app(ProductCatalogService::class)->createProduct($company, $normalized, (int) $actor->id);

            DB::table('product_creation_requests')->insert([
                'company_id' => $company->id,
                'user_id' => $actor->id,
                'request_key' => $requestKey,
                'request_hash' => $requestHash,
                'product_id' => $product->id,
                'created_at' => now(),
            ]);

            app(AuditService::class)->log(
                (int) $company->id,
                'catalog.quick_created',
                'Product created from an unsaved trade form',
                (int) $actor->id,
                $product
            );

            return $product;
        });
    }
}
