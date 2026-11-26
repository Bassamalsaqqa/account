<?php

declare(strict_types=1);

namespace App\Http\Controllers\Diagnostics;

use App\Application\Diagnostics\SubmitBugReport;
use App\Http\Controllers\Controller;
use App\Support\Diagnostics\RequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class BugReportController extends Controller
{
    public function store(Request $request, SubmitBugReport $submit): JsonResponse
    {
        $requestId = RequestId::forRequest($request);
        $headers = [
            RequestId::HEADER => $requestId,
            'Cache-Control' => 'no-store, private',
        ];

        $contentLength = $request->header('Content-Length');
        if ($contentLength !== null) {
            $trimmedLength = trim($contentLength);
            if ($trimmedLength !== '' && (! ctype_digit($trimmedLength) || (int) $trimmedLength > SubmitBugReport::MAX_BODY_BYTES)) {
                return response()->json([
                    'message' => __('diagnostics.payload_too_large'),
                ], 413, $headers);
            }
        }

        $rawBody = (string) $request->getContent();
        if (strlen($rawBody) > SubmitBugReport::MAX_BODY_BYTES) {
            return response()->json([
                'message' => __('diagnostics.payload_too_large'),
            ], 413, $headers);
        }

        $contentType = strtolower((string) $request->header('Content-Type'));
        if (! str_contains($contentType, 'application/json')) {
            return response()->json([
                'message' => __('diagnostics.unsupported_media_type'),
            ], 415, $headers);
        }

        try {
            $decoded = json_decode($rawBody, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json([
                'message' => __('diagnostics.invalid_payload'),
                'errors' => ['payload' => [__('diagnostics.invalid_payload')]],
            ], 422, $headers);
        }

        if (! is_array($decoded)) {
            return response()->json([
                'message' => __('diagnostics.invalid_payload'),
                'errors' => ['payload' => [__('diagnostics.invalid_payload')]],
            ], 422, $headers);
        }

        try {
            $result = $submit->execute($request, $decoded);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => __('diagnostics.validation_failed'),
                'errors' => $e->errors(),
            ], 422, $headers);
        } catch (ConflictHttpException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409, $headers);
        } catch (TooManyRequestsHttpException $e) {
            $retryHeaders = $headers;
            $exceptionHeaders = $e->getHeaders();
            if (isset($exceptionHeaders['Retry-After'])) {
                $retryHeaders['Retry-After'] = (string) $exceptionHeaders['Retry-After'];
            }

            return response()->json([
                'message' => $e->getMessage(),
            ], 429, $retryHeaders);
        }

        return response()->json([
            'public_ref' => $result['report']->public_ref,
            'status' => 'received',
            'idempotent_replay' => $result['replay'],
        ], $result['replay'] ? 200 : 201, $headers);
    }
}
