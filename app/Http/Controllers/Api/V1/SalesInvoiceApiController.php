<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Commands\SalesInvoiceCommands;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Exceptions\IdempotencyConflictException;
use App\Models\Company;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Sales\SalesActorGuard;
use App\Services\Security\CommandSessionGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class SalesInvoiceApiController extends ApiController
{
    public function __construct(
        protected ?SalesInvoiceCommands $commands = null,
    ) {}

    /**
     * POST /api/v1/companies/{companyId}/sales/invoices/draft
     * Create a new draft sales invoice.
     */
    public function storeDraft(Request $request, string $companyId): JsonResponse
    {
        $traceId = $this->resolveTraceId($request);
        $key = $this->resolveIdempotencyKey($request);
        if ($key === null) {
            return $this->problem(
                title: 'Unprocessable Content',
                status: 422,
                detail: 'The Idempotency-Key header is required and must be between 1 and 128 characters.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.1'
            );
        }

        $context = app(CompanyContext::class);
        $company = $context->company();
        /** @var User $actor */
        $actor = auth()->user();

        $payload = $request->json()->all();

        if (empty($payload['lines']) || ! is_array($payload['lines'])) {
            return $this->problem(
                title: 'Unprocessable Content',
                status: 422,
                detail: 'The sales invoice must contain at least one line item in [lines].',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.1'
            );
        }

        try {
            $invoice = $this->getCommands()->create($company, $actor, $payload, $key);
        } catch (AuthorizationException) {
            return $this->problem('Forbidden', 403, 'Action is unauthorized.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.3');
        } catch (AuthenticationException) {
            return $this->problem('Unauthorized', 401, 'Authentication is required.', $traceId, 'https://tools.ietf.org/html/rfc7235#section-3.1');
        } catch (ConflictHttpException) {
            return $this->problem('Conflict', 409, 'The requested operation conflicts with the current resource state.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.8');
        } catch (InvalidArgumentException $e) {
            if ($this->isStateConflictException($e)) {
                return $this->problem('Conflict', 409, 'The requested operation conflicts with the current resource state.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.8');
            }

            return $this->problem('Unprocessable Content', 422, 'The request data violates invoice domain validation rules.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.1');
        } catch (ModelNotFoundException) {
            return $this->problem('Not Found', 404, 'The requested company resource was not found.', $traceId);
        } catch (Throwable $e) {
            if ($this->isStateConflictException($e)) {
                return $this->problem('Conflict', 409, 'The requested operation conflicts with the current resource state.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.8');
            }

            Log::error(get_class($e), ['trace_id' => $traceId]);

            return $this->problem('Internal Server Error', 500, 'An unexpected server error occurred.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.6.1');
        }

        return response()->json($this->formatInvoice($invoice), 201, $this->securityHeaders($traceId));
    }

    /**
     * PUT /api/v1/companies/{companyId}/sales/invoices/{publicId}
     * Update an existing draft sales invoice.
     */
    public function updateDraft(Request $request, string $companyId, string $publicId): JsonResponse
    {
        $traceId = $this->resolveTraceId($request);
        $key = $this->resolveIdempotencyKey($request);
        if ($key === null) {
            return $this->problem(
                title: 'Unprocessable Content',
                status: 422,
                detail: 'The Idempotency-Key header is required and must be between 1 and 128 characters.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.1'
            );
        }

        $context = app(CompanyContext::class);
        $company = $context->company();
        /** @var User $actor */
        $actor = auth()->user();

        $invoice = $this->resolveInvoice($company, $publicId);
        if ($invoice === null) {
            return $this->problem(
                title: 'Not Found',
                status: 404,
                detail: 'The requested sales invoice was not found.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.4'
            );
        }

        $payload = $request->json()->all();

        try {
            $updated = $this->getCommands()->update($invoice, $actor, $payload, $key);
        } catch (AuthorizationException) {
            return $this->problem('Forbidden', 403, 'Action is unauthorized.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.3');
        } catch (AuthenticationException) {
            return $this->problem('Unauthorized', 401, 'Authentication is required.', $traceId, 'https://tools.ietf.org/html/rfc7235#section-3.1');
        } catch (ConflictHttpException) {
            return $this->problem('Conflict', 409, 'The requested operation conflicts with the current resource state.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.8');
        } catch (InvalidArgumentException $e) {
            if ($this->isStateConflictException($e)) {
                return $this->problem('Conflict', 409, 'The requested operation conflicts with the current resource state.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.8');
            }

            return $this->problem('Unprocessable Content', 422, 'The request data violates invoice domain validation rules.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.1');
        } catch (ModelNotFoundException) {
            return $this->problem('Not Found', 404, 'The requested company resource was not found.', $traceId);
        } catch (Throwable $e) {
            if ($this->isStateConflictException($e)) {
                return $this->problem('Conflict', 409, 'The requested operation conflicts with the current resource state.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.8');
            }

            Log::error(get_class($e), ['trace_id' => $traceId]);

            return $this->problem('Internal Server Error', 500, 'An unexpected server error occurred.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.6.1');
        }

        return response()->json($this->formatInvoice($updated), 200, $this->securityHeaders($traceId));
    }

    /**
     * GET /api/v1/companies/{companyId}/sales/invoices/{publicId}
     * Retrieve whitelisted view of a sales invoice inside a single Company-first transaction.
     */
    public function show(Request $request, string $companyId, string $publicId): JsonResponse
    {
        $traceId = $this->resolveTraceId($request);
        $context = app(CompanyContext::class);
        $company = $context->company();
        /** @var User $actor */
        $actor = auth()->user();

        try {
            $invoiceData = DB::transaction(function () use ($company, $actor, $publicId) {
                app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $actor, 'sales.invoice.view');
                app(CommandSessionGuard::class)->authorize($actor);

                $invoice = SalesInvoice::where('company_id', $company->id)
                    ->where('public_id', $publicId)
                    ->with('lines')
                    ->first();

                if ($invoice === null) {
                    return null;
                }

                return $this->formatInvoice($invoice);
            });
        } catch (AuthorizationException) {
            return $this->problem('Forbidden', 403, 'User does not possess permission to view sales invoices.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.3');
        } catch (AuthenticationException) {
            return $this->problem('Unauthorized', 401, 'Session authentication verification failed.', $traceId, 'https://tools.ietf.org/html/rfc7235#section-3.1');
        } catch (Throwable $e) {
            Log::error(get_class($e), ['trace_id' => $traceId]);

            return $this->problem('Internal Server Error', 500, 'An unexpected error occurred while retrieving sales invoice.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.6.1');
        }

        if ($invoiceData === null) {
            return $this->problem(
                title: 'Not Found',
                status: 404,
                detail: 'The requested sales invoice was not found.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.4'
            );
        }

        return response()->json($invoiceData, 200, $this->securityHeaders($traceId));
    }

    /**
     * POST /api/v1/companies/{companyId}/sales/invoices/{publicId}/preview
     * Calculate and return totals preview without committing financial state.
     */
    public function preview(Request $request, string $companyId, string $publicId): JsonResponse
    {
        $traceId = $this->resolveTraceId($request);
        $context = app(CompanyContext::class);
        $company = $context->company();
        /** @var User $actor */
        $actor = auth()->user();

        $invoice = $this->resolveInvoice($company, $publicId);
        if ($invoice === null) {
            return $this->problem(
                title: 'Not Found',
                status: 404,
                detail: 'The requested sales invoice was not found.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.4'
            );
        }

        try {
            $previewResult = $this->getCommands()->preview($invoice, $actor);
        } catch (AuthorizationException) {
            return $this->problem('Forbidden', 403, 'Action is unauthorized.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.3');
        } catch (AuthenticationException) {
            return $this->problem('Unauthorized', 401, 'Authentication is required.', $traceId, 'https://tools.ietf.org/html/rfc7235#section-3.1');
        } catch (ConflictHttpException) {
            return $this->problem('Conflict', 409, 'Unable to compute invoice preview due to resource state conflict.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.8');
        } catch (InvalidArgumentException $e) {
            if ($this->isStateConflictException($e)) {
                return $this->problem('Conflict', 409, 'Unable to compute invoice preview due to resource state conflict.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.8');
            }

            return $this->problem('Unprocessable Content', 422, 'The request data violates preview rules.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.1');
        } catch (ModelNotFoundException) {
            return $this->problem('Not Found', 404, 'The requested company resource was not found.', $traceId);
        } catch (Throwable $e) {
            if ($this->isStateConflictException($e)) {
                return $this->problem('Conflict', 409, 'Unable to compute invoice preview due to resource state conflict.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.8');
            }

            Log::error(get_class($e), ['trace_id' => $traceId]);

            return $this->problem('Internal Server Error', 500, 'An unexpected server error occurred during preview.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.6.1');
        }

        return response()->json($this->sanitizePreview($previewResult), 200, $this->securityHeaders($traceId));
    }

    /**
     * POST /api/v1/companies/{companyId}/sales/invoices/{publicId}/execute
     * Post/execute a draft sales invoice to canonical immutable state.
     */
    public function execute(Request $request, string $companyId, string $publicId): JsonResponse
    {
        $traceId = $this->resolveTraceId($request);
        $key = $this->resolveIdempotencyKey($request);
        if ($key === null) {
            return $this->problem(
                title: 'Unprocessable Content',
                status: 422,
                detail: 'The Idempotency-Key header is required and must be between 1 and 128 characters.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.1'
            );
        }

        $context = app(CompanyContext::class);
        $company = $context->company();
        /** @var User $actor */
        $actor = auth()->user();

        $invoice = $this->resolveInvoice($company, $publicId);
        if ($invoice === null) {
            return $this->problem(
                title: 'Not Found',
                status: 404,
                detail: 'The requested sales invoice was not found.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.4'
            );
        }

        $payload = $request->json()->all();
        $allowedKeys = ['confirmation_token', 'confirmed'];
        $extraKeys = array_diff(array_keys($payload), $allowedKeys);
        if ($extraKeys !== []) {
            return $this->problem(
                title: 'Unprocessable Content',
                status: 422,
                detail: 'Execution request must only contain confirmation_token and confirmed=true.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.1'
            );
        }

        if (! isset($payload['confirmation_token']) || ! is_string($payload['confirmation_token']) || trim($payload['confirmation_token']) === '') {
            return $this->problem(
                title: 'Unprocessable Content',
                status: 422,
                detail: 'The [confirmation_token] is required and must be a non-empty string.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.1'
            );
        }

        if (($payload['confirmed'] ?? null) !== true) {
            return $this->problem(
                title: 'Unprocessable Content',
                status: 422,
                detail: 'The [confirmed] field must be strictly boolean true.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.1'
            );
        }

        $token = trim($payload['confirmation_token']);

        try {
            $posted = $this->getCommands()->execute($invoice, $actor, $token, $key);
        } catch (AuthorizationException) {
            return $this->problem('Forbidden', 403, 'Action is unauthorized.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.3');
        } catch (AuthenticationException) {
            return $this->problem('Unauthorized', 401, 'Authentication is required.', $traceId, 'https://tools.ietf.org/html/rfc7235#section-3.1');
        } catch (ConflictHttpException) {
            return $this->problem('Conflict', 409, 'Invoice posting failed due to state conflict.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.8');
        } catch (InvalidArgumentException $e) {
            if ($this->isStateConflictException($e)) {
                return $this->problem('Conflict', 409, 'Invoice posting failed due to state conflict.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.8');
            }

            return $this->problem('Unprocessable Content', 422, 'Invoice execution validation failed.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.1');
        } catch (ModelNotFoundException) {
            return $this->problem('Not Found', 404, 'The requested company resource was not found.', $traceId);
        } catch (Throwable $e) {
            if ($this->isStateConflictException($e)) {
                return $this->problem('Conflict', 409, 'Invoice posting failed due to state conflict.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.5.8');
            }

            Log::error(get_class($e), ['trace_id' => $traceId]);

            return $this->problem('Internal Server Error', 500, 'An unexpected server error occurred during invoice execution.', $traceId, 'https://tools.ietf.org/html/rfc7231#section-6.6.1');
        }

        return response()->json($this->formatInvoice($posted), 200, $this->securityHeaders($traceId));
    }

    /**
     * Determine if an exception represents an expected domain/lifecycle state conflict.
     */
    protected function isStateConflictException(Throwable $e): bool
    {
        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 409) {
            return true;
        }
        if ($e instanceof IdempotencyConflictException
            || $e instanceof InsufficientStockException) {
            return true;
        }

        return $e instanceof InvalidArgumentException
            && preg_match('/^Cannot (?:edit|post|preview) sales invoice in .*status\./D', $e->getMessage()) === 1;
    }

    /**
     * Resolve Idempotency-Key from headers, ensuring 1..128 length.
     */
    protected function resolveIdempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null || trim($key) === '' || strlen(trim($key)) > 128) {
            return null;
        }

        return trim($key);
    }

    /**
     * Locate a sales invoice scoped strictly to the authenticated active company.
     */
    protected function resolveInvoice(Company $company, string $publicId): ?SalesInvoice
    {
        return SalesInvoice::where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->first();
    }

    /**
     * Format a SalesInvoice into a strictly whitelisted response without internal costs or PII.
     *
     * @return array<string, mixed>
     */
    protected function formatInvoice(SalesInvoice $invoice): array
    {
        $invoice->loadMissing('lines');

        $issueDate = $invoice->issue_date;
        $dueDate = $invoice->due_date;

        return [
            'id' => $invoice->public_id,
            'public_id' => $invoice->public_id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            'issue_date' => $issueDate->format('Y-m-d'),
            'due_date' => $dueDate ? (is_string($dueDate) ? $dueDate : $dueDate->format('Y-m-d')) : null,
            'currency_code' => $invoice->currency_code,
            'exchange_rate' => (string) $invoice->exchange_rate,
            'notes' => $invoice->notes,
            'terms' => $invoice->terms,
            'totals' => [
                'subtotal_currency' => (string) $invoice->subtotal_currency,
                'discount_total_currency' => (string) $invoice->discount_total_currency,
                'tax_total_currency' => (string) $invoice->tax_total_currency,
                'grand_total_currency' => (string) $invoice->grand_total_currency,
                'subtotal_base' => (string) $invoice->subtotal_base,
                'discount_total_base' => (string) $invoice->discount_total_base,
                'tax_total_base' => (string) $invoice->tax_total_base,
                'grand_total_base' => (string) $invoice->grand_total_base,
            ],
            'lines' => $invoice->lines->map(function ($line) {
                return [
                    'public_id' => $line->public_id,
                    'line_number' => (int) $line->line_number,
                    'product_id' => $line->product_id,
                    'product_unit_id' => $line->product_unit_id,
                    'item_description' => $line->item_description,
                    'quantity' => (string) $line->quantity,
                    'unit_price' => (string) $line->unit_price,
                    'discount_type' => $line->discount_type,
                    'discount_value' => (string) $line->discount_value,
                    'tax_rate_id' => $line->tax_rate_id,
                    'line_subtotal' => (string) $line->line_subtotal,
                    'line_discount' => (string) $line->line_discount,
                    'line_tax' => (string) $line->line_tax,
                    'line_total' => (string) $line->line_total,
                ];
            })->values()->all(),
        ];
    }

    /**
     * Redact sensitive internal cost/cogs keys from calculation preview.
     *
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    protected function sanitizePreview(array $preview): array
    {
        unset(
            $preview['cogs_total_base'],
            $preview['cogs_unit_base'],
            $preview['cost'],
            $preview['unit_cost'],
            $preview['total_cost']
        );

        if (isset($preview['lines']) && is_array($preview['lines'])) {
            foreach ($preview['lines'] as &$line) {
                if (is_array($line)) {
                    unset(
                        $line['cogs_total_base'],
                        $line['cogs_unit_base'],
                        $line['cost'],
                        $line['unit_cost'],
                        $line['total_cost']
                    );
                }
            }
        }

        return $preview;
    }

    /**
     * Resolve the SalesInvoiceCommands implementation.
     */
    protected function getCommands(): SalesInvoiceCommands
    {
        if ($this->commands !== null) {
            return $this->commands;
        }

        if (app()->bound(SalesInvoiceCommands::class)) {
            /** @var SalesInvoiceCommands $service */
            $service = app(SalesInvoiceCommands::class);

            return $service;
        }

        if (class_exists(SalesInvoiceCommands::class)) {
            /** @var SalesInvoiceCommands $service */
            $service = app(SalesInvoiceCommands::class);

            return $service;
        }

        throw new \RuntimeException('SalesInvoiceCommands service is not available in container.');
    }
}
