<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Application\Commands\ImmediatePurchaseCommands;
use App\Models\Purchase;
use App\Models\PurchaseConfirmationReceipt;
use App\Models\User;
use App\Services\Sales\SalesActorGuard;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PurchaseFormConfirmation
{
    public function identity(int $companyId, User $actor, ?string $source): string
    {
        return Crypt::encryptString(json_encode([
            'company_id' => $companyId,
            'actor_id' => (int) $actor->id,
            'source' => $source,
            'key' => (string) Str::uuid(),
        ], JSON_THROW_ON_ERROR));
    }

    public function completed(int $companyId, User $actor, ?string $source, string $identity): bool
    {
        $key = $this->key($companyId, $actor, $source, $identity);

        return PurchaseConfirmationReceipt::query()->where('company_id', $companyId)
            ->where('request_key', $key)->where('actor_id', $actor->id)
            ->where('source_public_id', $source)->exists();
    }

    /**
     * @param  callable(): ?array{public_id: string, token: string, key: string, digest: string, paid: bool}  $confirm
     * @return array{public_id: string, token: string, key: string, digest: string, paid: bool}|null
     */
    public function execute(int $companyId, User $actor, ?string $source, string $identity, string $digest, callable $confirm): ?array
    {
        $key = $this->key($companyId, $actor, $source, $identity);

        return DB::transaction(function () use ($companyId, $actor, $source, $key, $digest, $confirm): ?array {
            $guard = app(SalesActorGuard::class);
            $guard->lockAndAuthorize($companyId, $actor, $source === null ? 'purchasing.purchase.create' : 'purchasing.purchase.edit_draft');
            $guard->lockAndAuthorize($companyId, $actor, 'purchasing.cost.view');
            $guard->lockAndAuthorize($companyId, $actor, 'purchasing.purchase.post');
            $receipt = PurchaseConfirmationReceipt::query()->where('company_id', $companyId)->where('request_key', $key)->first();
            if ($receipt !== null) {
                if ((int) $receipt->actor_id !== (int) $actor->id || $receipt->source_public_id !== $source || ! hash_equals($receipt->payload_digest, $digest)) {
                    throw new HttpException(409, 'Purchase confirmation request changed.');
                }
                $confirmation = json_decode(Crypt::decryptString($receipt->confirmation), true, flags: JSON_THROW_ON_ERROR);
                $purchase = Purchase::where('company_id', $companyId)->findOrFail($receipt->purchase_id);
                abort_unless($purchase->public_id === $confirmation['public_id'], 409);
                app(ImmediatePurchaseCommands::class)->execute($purchase, $actor, $confirmation['token'], $confirmation['key']);

                return $confirmation;
            }

            $confirmation = $confirm();
            if ($confirmation === null) {
                return null;
            }
            $purchase = Purchase::where('company_id', $companyId)->where('public_id', $confirmation['public_id'])->firstOrFail();
            PurchaseConfirmationReceipt::create([
                'company_id' => $companyId,
                'actor_id' => $actor->id,
                'purchase_id' => $purchase->id,
                'source_public_id' => $source,
                'request_key' => $key,
                'payload_digest' => $digest,
                'confirmation' => Crypt::encryptString(json_encode($confirmation, JSON_THROW_ON_ERROR)),
                'created_at' => now(),
            ]);

            return $confirmation;
        });
    }

    private function key(int $companyId, User $actor, ?string $source, string $identity): string
    {
        try {
            $payload = json_decode(Crypt::decryptString($identity), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new HttpException(409, 'Invalid purchase confirmation identity.', $exception);
        }
        abort_unless(($payload['company_id'] ?? null) === $companyId && ($payload['actor_id'] ?? null) === (int) $actor->id
            && array_key_exists('source', $payload) && $payload['source'] === $source && Str::isUuid($payload['key'] ?? ''), 403);

        return $payload['key'];
    }
}
