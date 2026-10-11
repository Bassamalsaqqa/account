<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Models\AiProviderConnection;
use App\Models\AiPurchaseDraftReceipt;
use App\Models\AssistantRequest;
use App\Models\Company;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Sales\SalesActorGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PurchaseDraftCommands
{
    /** @param array<string, mixed> $payload */
    public function create(Company $company, User $actor, array $payload, string $formKey, ?int $sourceRequestId = null): Purchase
    {
        if (! Str::isUuid($formKey)) {
            throw new InvalidArgumentException('A unique purchase form identity is required.');
        }
        $intent = app(PurchaseDraftPayload::class);
        $canonical = $intent->canonicalize($payload);
        $digest = $intent->hash($canonical);

        return DB::transaction(function () use ($company, $actor, $canonical, $digest, $formKey, $sourceRequestId): Purchase {
            $guard = app(SalesActorGuard::class);
            $guard->lockAndAuthorize((int) $company->id, $actor, 'purchasing.purchase.create');
            $guard->lockAndAuthorize((int) $company->id, $actor, 'purchasing.purchase.view');
            $guard->lockAndAuthorize((int) $company->id, $actor, 'purchasing.cost.view');
            $receipt = AiPurchaseDraftReceipt::where('company_id', $company->id)->where('user_id', $actor->id)
                ->where('form_key', $formKey)->lockForUpdate()->first();
            if ($receipt !== null) {
                if (! hash_equals($receipt->payload_hash, $digest)) {
                    throw new HttpException(409, 'Purchase form already saved with different fields.');
                }
                $purchase = Purchase::where('company_id', $company->id)->whereKey($receipt->purchase_id)->first();
                if ($purchase === null) {
                    throw new HttpException(409, 'The original purchase draft is unavailable.');
                }

                return $purchase->load('lines.lots');
            }
            $source = null;
            if ($sourceRequestId !== null) {
                $policy = app(AiGovernance::class)->authorizeUse($company, $actor, 'purchase');
                $source = AssistantRequest::where('company_id', $company->id)->where('user_id', $actor->id)
                    ->whereKey($sourceRequestId)->lockForUpdate()->first();
                if ($source === null || $source->intent_kind !== 'purchase' || $source->form_key !== $formKey
                    || $source->status !== 'form_ready' || (int) $source->policy_revision !== (int) $policy->revision
                    || $source->created_at->addSeconds((int) config('ai_assistant.proposal_ttl_seconds'))->isPast()) {
                    throw new AssistantUnavailable('proposal_stale');
                }
                if ($source->connection_id !== null) {
                    $connection = AiProviderConnection::where('company_id', $company->id)->whereKey($source->connection_id)->first();
                    if ($connection === null) {
                        throw new AssistantUnavailable('credential_revoked');
                    }
                    app(AiGovernance::class)->dispatchConnection($company, $actor, $connection->public_id, 'purchase', 'text_structured', (int) $source->connection_revision);
                }
            }
            // Canonical action owns draft snapshots/calculations; this facade never posts.
            $purchase = app(CreatePurchaseDraftAction::class)->execute($company, $actor, $canonical);
            AiPurchaseDraftReceipt::create([
                'company_id' => $company->id, 'user_id' => $actor->id, 'form_key' => $formKey,
                'payload_hash' => $digest, 'purchase_id' => $purchase->id,
            ]);
            if ($source !== null) {
                // Final human fields, receipt and AI provenance are one atomic transaction.
                $source->update(['status' => 'accepted', 'purchase_id' => $purchase->id,
                    'command_key' => $formKey, 'approved_hash' => $digest, 'approved_command' => $canonical]);
            }

            return $purchase;
        });
    }
}
