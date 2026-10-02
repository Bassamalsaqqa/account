<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Domain\Sales\Queries\CustomerStatementQuery;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PublicShare;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PublicShareService
{
    /**
     * Create a public share for an eligible document.
     *
     * @return array{share: PublicShare, raw_token: string, url: string}
     */
    public function createShare(
        Company $company,
        User $user,
        string $subjectType,
        int $subjectId,
        ?Carbon $expiresAt = null,
        ?string $password = null
    ): array {
        return DB::transaction(function () use ($company, $user, $subjectType, $subjectId, $expiresAt, $password): array {
            app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $user, 'sales.document.share');
            $allowedTypes = [
                PublicShare::SUBJECT_QUOTATION,
                PublicShare::SUBJECT_SALES_INVOICE,
                PublicShare::SUBJECT_SALES_RETURN,
                PublicShare::SUBJECT_CUSTOMER_STATEMENT,
            ];

            if (! in_array($subjectType, $allowedTypes, true)) {
                throw new InvalidArgumentException("Subject type [{$subjectType}] is not eligible for public sharing.");
            }

            // Validate subject exists and belongs to company
            if ($subjectType === PublicShare::SUBJECT_QUOTATION) {
                $quote = Quotation::where('company_id', $company->id)->where('id', $subjectId)->firstOrFail();
                if ($quote->isDraft()) {
                    throw new InvalidArgumentException('Draft quotations cannot be publicly shared.');
                }
            } elseif ($subjectType === PublicShare::SUBJECT_SALES_INVOICE) {
                $inv = SalesInvoice::where('company_id', $company->id)->where('id', $subjectId)->firstOrFail();
                if (! $inv->isPosted()) {
                    throw new InvalidArgumentException('Only posted sales invoices can be publicly shared.');
                }
            } elseif ($subjectType === PublicShare::SUBJECT_SALES_RETURN) {
                $ret = SalesReturn::where('company_id', $company->id)->where('id', $subjectId)->firstOrFail();
                if (! $ret->isPosted()) {
                    throw new InvalidArgumentException('Only posted sales returns can be publicly shared.');
                }
            } elseif ($subjectType === PublicShare::SUBJECT_CUSTOMER_STATEMENT) {
                Customer::where('company_id', $company->id)->where('id', $subjectId)->firstOrFail();
            }

            $rawToken = Str::random(40);
            $tokenHash = hash('sha256', $rawToken);
            $encryptedToken = Crypt::encryptString($rawToken);

            $passwordHash = $password !== null && trim($password) !== ''
                ? Hash::make($password)
                : null;

            $share = PublicShare::create([
                'public_id' => (string) Str::ulid(),
                'company_id' => $company->id,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'token_lookup_hash' => $tokenHash,
                'encrypted_token' => $encryptedToken,
                'is_active' => true,
                'expires_at' => $expiresAt,
                'password_hash' => $passwordHash,
                'view_count' => 0,
                'created_by' => $user->id,
            ]);

            $url = route('public.share', ['token' => $rawToken]);

            return [
                'share' => $share,
                'raw_token' => $rawToken,
                'url' => $url,
            ];
        });
    }

    /**
     * Resolve and validate public share from raw token.
     */
    public function resolveShare(string $rawToken, ?string $password = null): PublicShare
    {
        $tokenHash = hash('sha256', $rawToken);

        /** @var PublicShare|null $share */
        $share = PublicShare::withoutGlobalScopes()->where('token_lookup_hash', $tokenHash)->first();

        if ($share === null || ! $share->is_active) {
            throw new InvalidArgumentException('The requested document link is invalid or has been revoked.');
        }

        if ($share->isExpired()) {
            throw new InvalidArgumentException('The requested document link has expired.');
        }

        if ($share->password_hash !== null) {
            if ($password === null || ! Hash::check($password, $share->password_hash)) {
                throw new InvalidArgumentException('Invalid or missing password for protected document.');
            }
        }

        $this->buildWhitelistedData($share);

        // Increment view count and update last viewed
        $share->view_count = $share->view_count + 1;
        $share->last_viewed_at = Carbon::now();
        $share->save();

        return $share;
    }

    /**
     * Resolve public share and return structured status array for web view.
     *
     * @return array{status: string, data?: array<string, mixed>, error?: string}
     */
    public function resolvePublicShare(string $rawToken, ?string $password = null): array
    {
        $tokenHash = hash('sha256', $rawToken);

        /** @var PublicShare|null $share */
        $share = PublicShare::withoutGlobalScopes()->where('token_lookup_hash', $tokenHash)->first();

        if ($share === null) {
            return ['status' => 'invalid'];
        }

        if (! $share->is_active) {
            return ['status' => 'revoked'];
        }

        if ($share->isExpired()) {
            return ['status' => 'expired'];
        }

        if ($share->password_hash !== null) {
            if ($password === null) {
                return ['status' => 'password_required'];
            }
            if (! Hash::check($password, $share->password_hash)) {
                return ['status' => 'password_required', 'error' => 'incorrect'];
            }
        }

        // Increment view count and update last viewed
        $share->view_count = $share->view_count + 1;
        $share->last_viewed_at = Carbon::now();
        $share->save();

        try {
            return ['status' => 'success', 'data' => $this->buildWhitelistedData($share)];
        } catch (\Throwable $exception) {
            report($exception);

            return ['status' => 'invalid'];
        }
    }

    /**
     * Revoke a public share immediately.
     */
    public function revokeShare(PublicShare $share, User $user): void
    {
        DB::transaction(function () use ($share, $user): void {
            app(SalesActorGuard::class)->lockAndAuthorize((int) $share->company_id, $user, 'sales.document.share');
            $locked = PublicShare::whereKey($share->id)->lockForUpdate()->firstOrFail();
            $locked->is_active = false;
            $locked->revoked_at = Carbon::now();
            $locked->revoked_by = $user->id;
            $locked->save();
        });
    }

    public function urlFor(PublicShare $share, User $user): string
    {
        return DB::transaction(function () use ($share, $user): string {
            app(SalesActorGuard::class)->lockAndAuthorize((int) $share->company_id, $user, 'sales.document.share');
            $locked = PublicShare::whereKey($share->id)->firstOrFail();
            if (! $locked->is_active || $locked->isExpired()) {
                throw new InvalidArgumentException('Share is no longer active.');
            }

            return route('public.share', ['token' => Crypt::decryptString($locked->encrypted_token)]);
        });
    }

    /**
     * Build an explicit whitelisted DTO array. Never leaks internal cost, COGS, profit, or private audit info.
     *
     * @return array<string, mixed>
     */
    public function buildWhitelistedData(PublicShare $share): array
    {
        $fresh = PublicShare::withoutGlobalScopes()->findOrFail($share->id);
        if (! $fresh->is_active || $fresh->isExpired()) {
            throw new InvalidArgumentException('Share is no longer active.');
        }

        return CompanyScope::executeWithoutScope(function () use ($fresh): array {
            $type = $fresh->subject_type;
            if ($type === PublicShare::SUBJECT_CUSTOMER_STATEMENT) {
                $customer = Customer::where('company_id', $fresh->company_id)->whereKey($fresh->subject_id)->firstOrFail();

                return app(DocumentDataBuilder::class)->statement(app(CustomerStatementQuery::class)->executeForShare($fresh, $customer))->toArray();
            }
            $class = match ($type) {
                PublicShare::SUBJECT_SALES_INVOICE => SalesInvoice::class,
                PublicShare::SUBJECT_QUOTATION => Quotation::class,
                PublicShare::SUBJECT_SALES_RETURN => SalesReturn::class,
                default => throw new InvalidArgumentException('Unsupported shared subject.'),
            };
            $source = $class::where('company_id', $fresh->company_id)->whereKey($fresh->subject_id)->firstOrFail();
            if (($source instanceof Quotation && $source->isDraft()) || (! $source instanceof Quotation && ! $source->isPosted())) {
                throw new InvalidArgumentException('Document is no longer eligible for public sharing.');
            }

            return app(DocumentDataBuilder::class)->build($source)->toArray();
        });
    }
}
