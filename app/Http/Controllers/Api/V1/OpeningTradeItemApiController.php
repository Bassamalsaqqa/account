<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Accounting\PostOpeningTradeItemAction;
use App\Actions\Accounting\ReverseOpeningTradeItemAction;
use App\Domain\Accounting\Exceptions\ActiveSettlementBlockedException;
use App\Domain\Accounting\Exceptions\LegacyGlOpeningOverlapException;
use App\Domain\Posting\Exceptions\IdempotencyConflictException;
use App\Models\OpeningTradeItem;
use App\Models\User;
use App\Services\Accounting\OpeningTradeCutoverAuditService;
use App\Services\Security\CommandSessionGuard;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class OpeningTradeItemApiController extends ApiController
{
    /**
     * POST /api/v1/companies/{companyId}/accounting/opening-trade-items/preview
     * Preflight calculation, carrying value, and GL overlap check.
     */
    public function preview(Request $request, string $companyId): JsonResponse
    {
        $traceId = $this->resolveTraceId($request);
        $context = app(CompanyContext::class);
        $company = $context->company();
        /** @var User $actor */
        $actor = auth()->user();

        $payload = $request->json()->all();

        foreach (['amount', 'exchange_rate'] as $field) {
            if (isset($payload[$field]) && ! is_string($payload[$field])) {
                return $this->problem('Unprocessable Content', 422, "Field [{$field}] requires an exact decimal string.", $traceId);
            }
        }

        $side = (string) ($payload['side'] ?? '');
        if (! in_array($side, [OpeningTradeItem::SIDE_CUSTOMER, OpeningTradeItem::SIDE_VENDOR], true)) {
            return $this->problem('Unprocessable Content', 422, "Field [side] must be either 'customer' or 'vendor'.", $traceId);
        }

        $partyId = (int) ($payload['party_id'] ?? ($side === OpeningTradeItem::SIDE_CUSTOMER ? ($payload['customer_id'] ?? 0) : ($payload['vendor_id'] ?? 0)));
        if ($partyId <= 0) {
            return $this->problem('Unprocessable Content', 422, 'A valid party_id must be provided.', $traceId);
        }

        $amountRaw = (string) ($payload['amount'] ?? '');
        try {
            $amountDec = BigDecimal::of($amountRaw);
            if ($amountDec->isNegative() || $amountDec->isZero()) {
                return $this->problem('Unprocessable Content', 422, 'Amount must be strictly positive.', $traceId);
            }
        } catch (Throwable) {
            return $this->problem('Unprocessable Content', 422, 'Amount must be a valid positive decimal.', $traceId);
        }

        $currencyCode = strtoupper(trim((string) ($payload['currency_code'] ?? '')));
        if ($currencyCode === '') {
            return $this->problem('Unprocessable Content', 422, 'Field [currency_code] is required.', $traceId);
        }

        $companyBase = strtoupper((string) $company->base_currency_code);
        $rateRaw = $payload['exchange_rate'] ?? ($currencyCode === $companyBase ? '1.0000000000' : null);
        if ($rateRaw === null) {
            return $this->problem('Unprocessable Content', 422, 'Exchange rate is required for non-base currency.', $traceId);
        }

        try {
            $rateDec = BigDecimal::of((string) $rateRaw)->toScale(10, RoundingMode::HALF_UP);
            if ($rateDec->isNegative() || $rateDec->isZero()) {
                return $this->problem('Unprocessable Content', 422, 'Exchange rate must be strictly positive.', $traceId);
            }
        } catch (Throwable) {
            return $this->problem('Unprocessable Content', 422, 'Exchange rate must be a valid positive decimal.', $traceId);
        }

        $hasGlOverlap = PostOpeningTradeItemAction::hasLegacyOverlap((int) $company->id, $side);
        $carryingBase = $amountDec->multipliedBy($rateDec)->toScale(6, RoundingMode::HALF_UP);

        return response()->json([
            'data' => [
                'side' => $side,
                'party_id' => $partyId,
                'amount' => (string) $amountDec->toScale(6),
                'currency_code' => $currencyCode,
                'exchange_rate' => (string) $rateDec->toScale(10),
                'carrying_value_base' => (string) $carryingBase,
                'base_carrying_value' => (string) $carryingBase,
                'has_gl_overlap' => $hasGlOverlap,
                'gl_overlap_message' => $hasGlOverlap
                    ? __('accounting.gl_overlap_rejected', [], 'ar')
                    : null,
            ],
        ], 200, $this->securityHeaders($traceId));
    }

    /**
     * POST /api/v1/companies/{companyId}/accounting/opening-trade-items/execute
     * Confirmed idempotent post calling PostOpeningTradeItemAction.
     */
    public function execute(Request $request, string $companyId): JsonResponse
    {
        $traceId = $this->resolveTraceId($request);
        $key = $this->resolveIdempotencyKey($request) ?? (string) $request->input('idempotency_key');
        if (trim($key) === '') {
            return $this->problem(
                title: 'Unprocessable Content',
                status: 422,
                detail: 'The Idempotency-Key header is required.',
                traceId: $traceId
            );
        }

        $context = app(CompanyContext::class);
        $company = $context->company();
        /** @var User $actor */
        $actor = auth()->user();

        $payload = $request->json()->all();

        foreach (['amount', 'exchange_rate'] as $field) {
            if (isset($payload[$field]) && ! is_string($payload[$field])) {
                return $this->problem('Unprocessable Content', 422, "Field [{$field}] requires an exact decimal string.", $traceId);
            }
        }

        $side = (string) ($payload['side'] ?? '');
        $partyId = (int) ($payload['party_id'] ?? ($side === OpeningTradeItem::SIDE_CUSTOMER ? ($payload['customer_id'] ?? 0) : ($payload['vendor_id'] ?? 0)));
        $openingDate = (string) ($payload['opening_date'] ?? '');
        $amount = (string) ($payload['amount'] ?? '');
        $currencyCode = (string) ($payload['currency_code'] ?? '');
        $exchangeRate = isset($payload['exchange_rate']) ? (string) $payload['exchange_rate'] : null;
        $originalRef = isset($payload['original_reference']) ? (string) $payload['original_reference'] : ($payload['reference_number'] ?? null);
        $dueDate = isset($payload['due_date']) ? (string) $payload['due_date'] : null;
        $notes = isset($payload['notes']) ? (string) $payload['notes'] : null;
        $sourceIdentity = (string) ($payload['source_identity'] ?? 'manual_api');

        try {
            app(CommandSessionGuard::class)->authorize($actor);

            $action = app(PostOpeningTradeItemAction::class);
            $item = $action->execute(
                company: $company,
                side: $side,
                partyId: $partyId,
                openingDate: $openingDate,
                amount: $amount,
                currencyCode: $currencyCode,
                exchangeRate: $exchangeRate,
                actor: $actor,
                idempotencyKey: $key,
                originalReference: $originalRef,
                dueDate: $dueDate,
                notes: $notes,
                sourceIdentity: $sourceIdentity
            );

            return response()->json([
                'data' => $this->formatItem($item),
            ], 201, $this->securityHeaders($traceId));
        } catch (LegacyGlOpeningOverlapException $e) {
            return response()->json([
                'error' => 'GL_OVERLAP_REJECTED',
                'title' => 'Legacy GL Overlap Rejection',
                'detail' => $e->getMessage(),
                'status' => 422,
                'trace_id' => $traceId,
            ], 422, $this->securityHeaders($traceId));
        } catch (IdempotencyConflictException $e) {
            return $this->problem('Conflict', 409, $e->getMessage(), $traceId);
        } catch (AuthorizationException $e) {
            return $this->problem('Forbidden', 403, $e->getMessage(), $traceId);
        } catch (AuthenticationException) {
            return $this->problem('Unauthorized', 401, 'Authentication required.', $traceId);
        } catch (InvalidArgumentException|MathException $e) {
            return $this->problem('Unprocessable Content', 422, $e->getMessage(), $traceId);
        } catch (Throwable $e) {
            Log::error(get_class($e).': '.$e->getMessage(), ['trace_id' => $traceId]);

            return $this->problem('Internal Server Error', 500, 'An unexpected server error occurred.', $traceId);
        }
    }

    /**
     * POST /api/v1/companies/{companyId}/accounting/opening-trade-items/{publicId}/reverse
     * Reversal calling ReverseOpeningTradeItemAction.
     */
    public function reverse(Request $request, string $companyId, string $publicId): JsonResponse
    {
        $traceId = $this->resolveTraceId($request);
        $context = app(CompanyContext::class);
        $company = $context->company();
        /** @var User $actor */
        $actor = auth()->user();

        $reason = trim((string) $request->input('reason', ''));
        if ($reason === '') {
            return $this->problem('Unprocessable Content', 422, 'Reversal reason is required.', $traceId);
        }

        try {
            app(CommandSessionGuard::class)->authorize($actor);

            /** @var OpeningTradeItem $item */
            $item = OpeningTradeItem::where('company_id', $company->id)
                ->where('public_id', $publicId)
                ->firstOrFail();

            $reversalAction = app(ReverseOpeningTradeItemAction::class);
            $reversed = $reversalAction->execute($item, $actor, $reason);

            return response()->json([
                'data' => $this->formatItem($reversed),
            ], 200, $this->securityHeaders($traceId));
        } catch (ActiveSettlementBlockedException $e) {
            return response()->json([
                'error' => 'ACTIVE_SETTLEMENT_BLOCKED',
                'title' => 'Active Settlement Blocked',
                'detail' => $e->getMessage(),
                'status' => 422,
                'trace_id' => $traceId,
            ], 422, $this->securityHeaders($traceId));
        } catch (ModelNotFoundException) {
            return $this->problem('Not Found', 404, "Opening trade item [{$publicId}] not found.", $traceId);
        } catch (AuthorizationException $e) {
            return $this->problem('Forbidden', 403, $e->getMessage(), $traceId);
        } catch (InvalidArgumentException|MathException $e) {
            return $this->problem('Unprocessable Content', 422, $e->getMessage(), $traceId);
        } catch (Throwable $e) {
            Log::error(get_class($e).': '.$e->getMessage(), ['trace_id' => $traceId]);

            return $this->problem('Internal Server Error', 500, 'An unexpected server error occurred.', $traceId);
        }
    }

    /**
     * GET /api/v1/companies/{companyId}/accounting/opening-trade-items/cutover-audit
     * Invoking OpeningTradeCutoverAuditService.
     */
    public function cutoverAudit(Request $request, string $companyId): JsonResponse
    {
        $traceId = $this->resolveTraceId($request);
        $context = app(CompanyContext::class);
        $company = $context->company();
        /** @var User $actor */
        $actor = auth()->user();

        try {
            app(CommandSessionGuard::class)->authorize($actor);

            if (! $actor->hasPermissionTo('settings.accounting.manage') && ! $actor->hasPermissionTo('accounting.reports.view')) {
                throw new AuthorizationException('Permission denied for cutover audit.');
            }

            $auditService = app(OpeningTradeCutoverAuditService::class);
            $report = $auditService->audit((int) $company->id);

            return response()->json([
                'data' => $report,
            ], 200, $this->securityHeaders($traceId));
        } catch (AuthorizationException $e) {
            return $this->problem('Forbidden', 403, $e->getMessage(), $traceId);
        } catch (Throwable $e) {
            Log::error(get_class($e).': '.$e->getMessage(), ['trace_id' => $traceId]);

            return $this->problem('Internal Server Error', 500, 'An unexpected server error occurred.', $traceId);
        }
    }

    /** @return array<string, mixed> */
    private function formatItem(OpeningTradeItem $item): array
    {
        return [
            'public_id' => $item->public_id,
            'side' => $item->side,
            'party_id' => $item->side === OpeningTradeItem::SIDE_CUSTOMER ? (int) $item->customer_id : (int) $item->vendor_id,
            'customer_id' => $item->customer_id ? (int) $item->customer_id : null,
            'vendor_id' => $item->vendor_id ? (int) $item->vendor_id : null,
            'opening_date' => Carbon::parse($item->opening_date)->toDateString(),
            'due_date' => $item->due_date ? Carbon::parse($item->due_date)->toDateString() : null,
            'original_reference' => $item->reference_number,
            'amount' => (string) $item->amount,
            'currency_code' => $item->currency_code,
            'exchange_rate' => (string) $item->exchange_rate,
            'carrying_value_base' => (string) $item->carrying_value_base,
            'status' => $item->status,
            'reversed_at' => $item->reversed_at?->toIso8601String(),
            'reversal_reason' => $item->reversal_reason,
            'posting_batch_id' => $item->posting_batch_id ? (int) $item->posting_batch_id : null,
            'reversal_posting_batch_id' => $item->reversal_posting_batch_id ? (int) $item->reversal_posting_batch_id : null,
            'party_snapshot' => $item->party_snapshot,
            'created_at' => $item->created_at?->toIso8601String(),
        ];
    }

    protected function resolveIdempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        return $key !== null && trim($key) !== '' && strlen(trim($key)) <= 128 ? trim($key) : null;
    }
}
