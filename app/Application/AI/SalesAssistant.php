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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SalesAssistant
{
    /** @return array<string, mixed> */
    public function interpret(Company $company, User $actor, string $text, string $key, bool $consent): array
    {
        app(AssistantAccess::class)->authorize($company, $actor);
        $this->text($text);
        if (! $consent) {
            throw new AssistantUnavailable('consent');
        }
        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';
        $hash = hash_hmac('sha256', $locale.'|'.$text, (string) config('app.key'));
        $request = app(AssistantBudget::class)->reserve($company, $actor, $key, 'intent', $hash);
        if (! $request->wasRecentlyCreated) {
            return $this->replay($request);
        }
        try {
            $reply = app(StructuredIntentProvider::class)->extract($text, $locale);
            app(AssistantBudget::class)->finish($request, $reply['usage']);
            $this->confidence($reply['confidence']);
            $proposal = app(SalesProposalSchema::class)->validate($reply['proposal']);
            // Provider calls hold no database locks. Recheck membership after they return.
            $resolved = app(SalesProposalResolver::class)->resolve($company, $actor, $proposal);
            $payloadHash = $resolved['payload'] === null ? null : app(SalesDraftIntent::class)->canonicalHash($resolved['payload']);
            $request->update(['status' => 'resolved', 'resolved_hash' => $payloadHash]);
            $token = $payloadHash === null ? null : Crypt::encryptString(json_encode([
                'version' => 1, 'company_id' => (int) $company->id, 'actor_id' => (int) $actor->id,
                'request_id' => (int) $request->id, 'expires_at' => now()->timestamp + (int) config('ai_assistant.proposal_ttl_seconds'),
                'proposal' => $proposal, 'resolved_hash' => $payloadHash,
            ], JSON_THROW_ON_ERROR));
            $result = ['request_id' => (int) $request->id, 'token' => $token, 'questions' => $resolved['questions'], 'summary' => $resolved['summary']];
            // No original text, recording or transcript is retained. Only this encrypted
            // minimized structured response is cached for bounded request replay.
            Cache::put($this->cacheKey($request), Crypt::encryptString(json_encode($result, JSON_THROW_ON_ERROR)), (int) config('ai_assistant.proposal_ttl_seconds'));

            return $result;
        } catch (Throwable $error) {
            $this->fail($request, $error);
            throw $error instanceof AuthorizationException ? $error : new AssistantUnavailable($error instanceof AssistantUnavailable ? $error->reason : 'proposal_invalid');
        }
    }

    /** @return array{text: string, request_id: int} */
    public function transcribe(Company $company, User $actor, string $wav, string $key, bool $consent): array
    {
        app(AssistantAccess::class)->authorize($company, $actor, true);
        if (! $consent) {
            throw new AssistantUnavailable('consent');
        }
        $seconds = app(PcmWave::class)->seconds($wav);
        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';
        $request = app(AssistantBudget::class)->reserve($company, $actor, $key, 'transcription', hash_hmac('sha256', $locale.'|'.$wav, (string) config('app.key')));
        // Never cache raw transcript for retries: a used key cannot send audio again.
        if (! $request->wasRecentlyCreated) {
            throw new AssistantUnavailable('request_processed');
        }
        $request->update(['audio_seconds' => $seconds]);
        try {
            $reply = app(SpeechTranscriptionProvider::class)->transcribe($wav, $locale);
            app(AssistantBudget::class)->finish($request, $reply['usage']);
            $this->confidence($reply['confidence']);
            $this->text($reply['text']);
            app(AssistantAccess::class)->authorize($company, $actor, true);
            $request->update(['status' => 'transcribed']);

            return ['text' => $reply['text'], 'request_id' => (int) $request->id];
        } catch (Throwable $error) {
            $this->fail($request, $error);
            throw $error instanceof AuthorizationException ? $error : new AssistantUnavailable($error instanceof AssistantUnavailable ? $error->reason : 'provider_failure');
        }
    }

    /** @return array{request_id: int, payload: array<string, mixed>} */
    public function apply(Company $company, User $actor, string $token): array
    {
        app(AssistantAccess::class)->authorize($company, $actor);
        if (strlen($token) > 65536) {
            throw new AssistantUnavailable('proposal_expired');
        }
        try {
            $data = json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new AssistantUnavailable('proposal_expired');
        }
        if (! is_array($data) || ($data['version'] ?? null) !== 1
            || ($data['company_id'] ?? null) !== (int) $company->id || ($data['actor_id'] ?? null) !== (int) $actor->id
            || ! is_int($data['expires_at'] ?? null) || $data['expires_at'] <= now()->timestamp
            || ! is_int($data['request_id'] ?? null) || ! is_array($data['proposal'] ?? null)) {
            throw new AssistantUnavailable('proposal_expired');
        }

        return DB::transaction(function () use ($company, $actor, $data): array {
            app(AssistantAccess::class)->authorize($company, $actor);
            $request = AssistantRequest::where('company_id', $company->id)->where('user_id', $actor->id)->whereKey($data['request_id'])->lockForUpdate()->first();
            if ($request === null || $request->status !== 'resolved' || $request->resolved_hash !== ($data['resolved_hash'] ?? null)) {
                throw new AssistantUnavailable('proposal_expired');
            }
            $resolved = app(SalesProposalResolver::class)->resolve($company, $actor, $data['proposal']);
            if ($resolved['payload'] === null || app(SalesDraftIntent::class)->canonicalHash($resolved['payload']) !== $request->resolved_hash) {
                throw new AssistantUnavailable('proposal_changed');
            }

            return ['request_id' => (int) $request->id, 'payload' => $resolved['payload']];
        });
    }

    /** Must participate in the same outer transaction as the canonical draft command.
     * @param  array<string, mixed>  $payload
     */
    public function recordDraft(Company $company, User $actor, int $requestId, SalesInvoice $invoice, array $payload, string $commandKey): void
    {
        if (DB::transactionLevel() === 0 || (int) $invoice->company_id !== (int) $company->id) {
            throw new AssistantUnavailable('invalid_request');
        }
        // An already-applied suggestion remains ordinary editable manual input if
        // the provider is subsequently disabled. Canonical commands still authorize.
        app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $actor, 'sales.invoice.create');
        $canonical = app(SalesDraftIntent::class)->canonicalize($payload);
        $approvedHash = app(SalesDraftIntent::class)->canonicalHash($canonical);
        $receipt = CommandReceipt::where('company_id', $company->id)->where('user_id', $actor->id)
            ->where('action', 'sales_invoice.create')->where('idempotency_key', $commandKey)
            ->where('sales_invoice_id', $invoice->id)->where('request_hash', $approvedHash)->lockForUpdate()->first();
        if ($receipt === null) {
            throw new AssistantUnavailable('invalid_request');
        }
        $request = AssistantRequest::where('company_id', $company->id)->where('user_id', $actor->id)->whereKey($requestId)->lockForUpdate()->firstOrFail();
        if ($request->status === 'accepted' && (int) $request->sales_invoice_id === (int) $invoice->id) {
            return; // Preserve the first approved command audit; later edits have P11 receipts.
        }
        if (! in_array($request->status, ['resolved', 'expired'], true) || $request->purpose !== 'intent' || $request->resolved_hash === null) {
            throw new AssistantUnavailable('proposal_expired');
        }
        $request->update([
            'status' => 'accepted', 'approved_hash' => $approvedHash,
            'approved_command' => $canonical,
            'command_key' => $commandKey, 'sales_invoice_id' => $invoice->id,
        ]);
        DB::afterCommit(fn () => Cache::forget($this->cacheKey($request)));
    }

    public function cancel(Company $company, User $actor, int $requestId): void
    {
        app(AssistantAccess::class)->authorize($company, $actor);
        DB::transaction(function () use ($company, $actor, $requestId): void {
            app(AssistantAccess::class)->authorize($company, $actor);
            $request = AssistantRequest::where('company_id', $company->id)->where('user_id', $actor->id)->whereKey($requestId)->lockForUpdate()->first();
            if ($request !== null && in_array($request->status, ['resolved', 'failed', 'transcribed'], true)) {
                $request->update(['status' => 'canceled']);
                Cache::forget($this->cacheKey($request));
            }
        });
    }

    public function cacheKey(AssistantRequest $request): string
    {
        return 'ai-sales-proposal:'.$request->company_id.':'.$request->user_id.':'.$request->request_key;
    }

    /** @return array<string, mixed> */
    private function replay(AssistantRequest $request): array
    {
        if ($request->status !== 'resolved') {
            throw new AssistantUnavailable('request_processed');
        }
        $cipher = Cache::get($this->cacheKey($request));
        if (! is_string($cipher)) {
            throw new AssistantUnavailable('proposal_expired');
        }
        try {
            $data = json_decode(Crypt::decryptString($cipher), true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                throw new AssistantUnavailable('proposal_expired');
            }

            return $data;
        } catch (Throwable) {
            throw new AssistantUnavailable('proposal_expired');
        }
    }

    private function confidence(int $confidence): void
    {
        if ($confidence < (int) config('ai_assistant.minimum_confidence') || $confidence > 100) {
            throw new AssistantUnavailable('low_confidence');
        }
    }

    private function text(string $text): void
    {
        if (trim($text) === '' || mb_strlen($text) > (int) config('ai_assistant.max_text_characters') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $text)) {
            throw new AssistantUnavailable('text_invalid');
        }
    }

    private function fail(AssistantRequest $request, Throwable $error): void
    {
        $code = $error instanceof AssistantUnavailable ? $error->reason : 'validation';
        $allowed = ['budget', 'provider_invalid', 'provider_failure', 'provider_configuration', 'proposal_invalid', 'low_confidence', 'text_invalid', 'disabled'];
        $request->update(['status' => 'failed', 'failure_code' => in_array($code, $allowed, true) ? $code : 'validation']);
        Cache::forget($this->cacheKey($request));
    }
}
