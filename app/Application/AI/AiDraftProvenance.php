<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Application\Commands\SalesDraftIntent;
use App\Models\AssistantRequest;
use App\Models\CommandReceipt;
use App\Models\Company;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Sales\SalesActorGuard;
use Illuminate\Support\Facades\DB;

final class AiDraftProvenance
{
    /** Fresh authorization and encrypted, session-bound memory are mandatory on AI Save.
     * @return array<string, mixed>
     */
    public function source(Company $company, User $actor, string $token, string $sessionId, string $formKey, string $intent, int $expectedRevision): array
    {
        if (DB::transactionLevel() === 0) {
            throw new AssistantUnavailable('invalid_request');
        }
        // Keep the same company-first locking order as canonical commands and inference completion.
        $guard = app(SalesActorGuard::class);
        foreach ($intent === 'purchase'
            ? ['purchasing.purchase.create', 'purchasing.purchase.view', 'purchasing.cost.view']
            : ['sales.invoice.create', 'sales.invoice.view'] as $permission) {
            $guard->lockAndAuthorize((int) $company->id, $actor, $permission);
        }
        app(AiGovernance::class)->authorizeUse($company, $actor, $intent);
        $state = app(AiFormState::class)->read($company, $actor, $token, $sessionId, $formKey);
        $request = AssistantRequest::where('company_id', $company->id)->where('user_id', $actor->id)
            ->whereKey($state['data']['request_id'] ?? 0)->lockForUpdate()->first();
        if ($state['revision'] !== $expectedRevision || $state['intent'] !== $intent || ($state['data']['lifecycle'] ?? null) !== 'editing'
            || $request === null || $request->status !== 'form_ready' || $request->intent_kind !== $intent
            || $request->form_key !== $formKey || (int) $request->policy_revision !== $state['policy_revision']) {
            throw new AssistantUnavailable('proposal_stale');
        }

        return $state;
    }

    /** Canonical P11 receipt, human-final fields and provenance must commit together.
     * @param  array<string, mixed>  $payload
     */
    public function recordSales(Company $company, User $actor, int $requestId, SalesInvoice $invoice, array $payload, string $commandKey): void
    {
        if (DB::transactionLevel() === 0 || (int) $invoice->company_id !== (int) $company->id) {
            throw new AssistantUnavailable('invalid_request');
        }
        app(AiGovernance::class)->authorizeUse($company, $actor, 'sales');
        $canonical = app(SalesDraftIntent::class)->canonicalize($payload);
        $hash = app(SalesDraftIntent::class)->canonicalHash($canonical);
        $receipt = CommandReceipt::where('company_id', $company->id)->where('user_id', $actor->id)
            ->where('action', 'sales_invoice.create')->where('idempotency_key', $commandKey)
            ->where('sales_invoice_id', $invoice->id)->where('request_hash', $hash)->lockForUpdate()->first();
        $request = AssistantRequest::where('company_id', $company->id)->where('user_id', $actor->id)
            ->whereKey($requestId)->lockForUpdate()->firstOrFail();
        if ($receipt === null || $request->status !== 'form_ready' || $request->intent_kind !== 'sales') {
            throw new AssistantUnavailable('proposal_stale');
        }
        $request->update(['status' => 'accepted', 'sales_invoice_id' => $invoice->id, 'command_key' => $commandKey,
            'approved_hash' => $hash, 'approved_command' => $canonical]);
    }
}
