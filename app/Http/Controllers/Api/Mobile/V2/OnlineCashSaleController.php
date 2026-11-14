<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V2;

use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileV2ApiBoundary;
use App\Models\Company;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\User;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Support\Mobile\Contract\Revision3\OnlineSaleIntentProfile;
use App\Support\Mobile\Receipt\Exceptions\FinancialExecutionDeniedException;
use App\Support\Mobile\Receipt\Exceptions\MobileEventDuplicatePayloadException;
use App\Support\Mobile\Receipt\MobileEventReceiptManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

final class OnlineCashSaleController extends Controller
{
    public function store(
        Request $request,
        string $companyId,
        MobileEventReceiptManager $manager,
        CurrencyMinorUnitsResolver $currencyResolver
    ): JsonResponse {
        if (! (bool) config('mobile.v2_cash_slice_enabled', false)) {
            return MobileV2ApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested resource was not found.',
                status: 404
            );
        }

        /** @var Company|null $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User|null $user */
        $user = $request->attributes->get('mobile_user');
        /** @var MobileDevice|null $device */
        $device = $request->attributes->get('mobile_device');
        /** @var MobileAccessToken|null $token */
        $token = $request->attributes->get('mobile_token');

        if ($company === null || $user === null || $device === null || $token === null) {
            return MobileV2ApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'Authentication required or token expired.',
                status: 401
            );
        }

        $rawBody = (string) $request->getContent();
        if ($rawBody === '') {
            return MobileV2ApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Empty request body.',
                status: 422
            );
        }

        // Peek currency to resolve minor units
        $peek = json_decode($rawBody, true);
        $currency = is_array($peek) && isset($peek['currency']) && is_string($peek['currency'])
            ? $peek['currency']
            : (string) $company->base_currency_code;

        try {
            $minorUnits = $currencyResolver->resolveForCompany((int) $company->id, $currency);
        } catch (Throwable $e) {
            return MobileV2ApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: $e->getMessage(),
                status: 422
            );
        }

        $serverContext = [
            'actor_id' => (string) $user->public_id,
            'company_id' => (string) $company->public_id,
            'currency_minor_units' => $minorUnits,
        ];

        try {
            // Construct and validate normative profile from raw wire JSON string directly.
            // StrictJsonParser parses AST, validates duplicate keys, and constructs normalized profile.
            $profile = OnlineSaleIntentProfile::construct($serverContext, $rawBody);
            $canonicalBytes = OnlineSaleIntentProfile::serialize($profile);
        } catch (Throwable $e) {
            return MobileV2ApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: $e->getMessage(),
                status: 422
            );
        }

        $clientEventId = (string) $profile['client_event_id'];

        try {
            $result = $manager->recordOrReplay(
                (int) $company->id,
                $clientEventId,
                (string) $token->token_hash,
                2,
                'v2_online_sale_intent',
                $canonicalBytes
            );
        } catch (MobileEventDuplicatePayloadException) {
            return MobileV2ApiBoundary::buildErrorResponse(
                code: 'CONFLICT',
                message: 'Resource state conflict.',
                status: 409
            );
        } catch (InvalidArgumentException|InvalidInventoryMovementException $e) {
            return MobileV2ApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: $e->getMessage(),
                status: 422
            );
        } catch (AuthorizationException|FinancialExecutionDeniedException $e) {
            return MobileV2ApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: $e->getMessage(),
                status: 403
            );
        }

        $outcome = $result['outcome'] === 'replay' ? 'replayed' : 'created';
        $httpStatus = $result['outcome'] === 'replay' ? 200 : 201;

        return response()->json([
            'outcome' => $outcome,
            ...$result['terminal_canonical_result'],
        ], $httpStatus);
    }
}
