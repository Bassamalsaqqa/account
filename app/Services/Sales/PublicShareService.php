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
use App\Services\Audit\AuditService;
use App\Support\Tenancy\CompanyScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

class PublicShareService
{
    /**
     * Create a public share for an eligible document.
     *
     * @param  array<string,mixed>  $scope
     * @return array{share: PublicShare, raw_token: string, url: string}
     */
    public function createShare(
        Company $company,
        User $user,
        string $subjectType,
        int $subjectId,
        ?Carbon $expiresAt = null,
        ?string $password = null,
        ?string $requestKey = null,
        array $scope = [],
    ): array {
        return app(IssuedFinancialShares::class)->create($company, $user, $subjectType, $subjectId, $expiresAt, $password, $requestKey, $scope);
    }

    /**
     * Resolve and validate public share from raw token.
     */
    public function resolveShare(string $rawToken, ?string $password = null): PublicShare
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/D', $rawToken)) {
            throw new InvalidArgumentException('Invalid share token.');
        }
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
        app(IssuedFinancialShares::class)->recordView($share);

        return $share;
    }

    /**
     * Resolve public share and return structured status array for web view.
     *
     * @return array{status: string, data?: array<string, mixed>, error?: string}
     */
    public function resolvePublicShare(string $rawToken, ?string $password = null): array
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/D', $rawToken)) {
            throw new InvalidArgumentException('Invalid share token.');
        }
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

        try {
            $data = $this->buildWhitelistedData($share);
            app(IssuedFinancialShares::class)->recordView($share);

            return ['status' => 'success', 'data' => $data];
        } catch (\Throwable $exception) {
            // Public failures never log tokens, secret material or financial content.

            return ['status' => 'invalid'];
        }
    }

    /**
     * Revoke a public share immediately.
     */
    public function revokeShare(PublicShare $share, User $user): void
    {
        DB::transaction(function () use ($share, $user): void {
            app(FinancialSharePolicy::class)->authorize((int) $share->company_id, $user, $share->subject_type);
            $locked = PublicShare::where('company_id', $share->company_id)->whereKey($share->id)->lockForUpdate()->firstOrFail();
            $locked->is_active = false;
            $locked->revoked_at = Carbon::now();
            $locked->revoked_by = $user->id;
            $locked->save();
            app(AuditService::class)->log(companyId: (int) $share->company_id, eventKey: 'share.revoked', summary: 'Financial share revoked', actorUserId: $user->id, subject: $locked);
        });
    }

    public function urlFor(PublicShare $share, User $user): string
    {
        return DB::transaction(function () use ($share, $user): string {
            app(FinancialSharePolicy::class)->authorize((int) $share->company_id, $user, $share->subject_type);
            $locked = PublicShare::where('company_id', $share->company_id)->whereKey($share->id)->firstOrFail();
            if (! $locked->is_active || $locked->isExpired()) {
                throw new InvalidArgumentException('Share is no longer active.');
            }
            app(IssuedFinancialShares::class)->valid($locked);
            app(AuditService::class)->log(companyId: (int) $locked->company_id, eventKey: 'share.recovered', summary: 'Financial share link recovered', actorUserId: $user->id, subject: $locked);

            return app(ManagedPublicUrl::class)->make('share', Crypt::decryptString($locked->encrypted_token));
        });
    }

    /**
     * Build an explicit whitelisted DTO array. Never leaks internal cost, COGS, profit, or private audit info.
     *
     * @return array<string, mixed>
     */
    public function buildWhitelistedData(PublicShare $share): array
    {
        if ($share->access_profile === IssuedFinancialShares::PROFILE) {
            $issued = app(IssuedFinancialShares::class);
            $fresh = $issued->authorized($share, request());

            return $issued->content($fresh)->toArray();
        }
        $fresh = app(IssuedFinancialShares::class)->valid($share);
        if (! $fresh->is_active || $fresh->isExpired()) {
            throw new InvalidArgumentException('Share is no longer active.');
        }

        return CompanyScope::executeWithoutScope(function () use ($fresh): array {
            $type = $fresh->subject_type;
            if ($type === PublicShare::SUBJECT_CUSTOMER_STATEMENT) {
                $customer = Customer::where('company_id', $fresh->company_id)->whereKey($fresh->subject_id)->firstOrFail();

                app(DocumentRenderLimits::class)->assertStatementSource((int) $fresh->company_id, (int) $customer->id);

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
