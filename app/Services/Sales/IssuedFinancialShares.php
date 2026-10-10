<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Domain\Sales\Documents\DocumentData;
use App\Domain\Sales\Queries\CustomerStatementQuery;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PublicShare;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Tenancy\CompanyScope;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class IssuedFinancialShares
{
    public const PROFILE = 'deliberate_v1';

    /** @param array<string,mixed> $scope
     * @return array{share:PublicShare,raw_token:string,url:string}
     */
    public function create(Company $company, User $actor, string $type, int $id, ?Carbon $expires, ?string $password, ?string $requestKey, array $scope, ?int $lifetimeDays = null): array
    {
        $options = Validator::make($scope, ['locale' => ['nullable', 'in:ar,en'], 'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => array_filter(['nullable', 'date_format:Y-m-d', isset($scope['from']) ? 'after_or_equal:from' : null])])->validate();
        if (array_diff(array_keys($scope), ['locale', 'from', 'to']) !== []) {
            throw new InvalidArgumentException('Unsupported issuance scope.');
        }
        $requestKey ??= (string) Str::uuid();
        if (! preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D', $requestKey) || ($password !== null && (strlen($password) < 8 || strlen($password) > 128))) {
            throw new InvalidArgumentException('Invalid issuance request or password length.');
        }
        if ($type === PublicShare::SUBJECT_CUSTOMER_STATEMENT && $password === null) {
            throw new InvalidArgumentException('Statements require a password.');
        }
        $statement = $type === PublicShare::SUBJECT_CUSTOMER_STATEMENT;
        $expiryPolicy = app(ShareExpiry::class);
        if ($expires !== null) {
            $expiryPolicy->assertFinancialInstant($expires, $statement, now('UTC'));
        }
        if ($lifetimeDays !== null) {
            if ($expires !== null) {
                throw new InvalidArgumentException('Specify a lifetime or an expiry instant, not both.');
            }
            $expiryPolicy->financialLifetime($lifetimeDays, $statement);
        }
        $intent = ['subject' => $type, 'id' => $id, 'scope' => $options, 'expiry' => $expires?->toIso8601String(), 'has_password' => $password !== null];
        if ($lifetimeDays !== null) {
            $intent['duration_days'] = $lifetimeDays; // Stable retry intent; never hash a freshly calculated clock instant.
        }
        $intentHash = hash('sha256', app(IssuedDocumentContent::class)->canonical($intent));

        return DB::transaction(function () use ($company, $actor, $type, $id, $expires, $password, $requestKey, $options, $intentHash, $statement, $lifetimeDays, $expiryPolicy): array {
            $policy = app(FinancialSharePolicy::class);
            $policy->authorize((int) $company->id, $actor, $type); // Company-first lock serializes retries/issuance/lifecycle.
            $company = Company::findOrFail($company->id);
            $source = $policy->source((int) $company->id, $type, $id);
            $existing = PublicShare::where('company_id', $company->id)->where('request_key', $requestKey)->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->request_hash, $intentHash) || ! $existing->isValid()
                    || ($existing->password_hash !== null && ($password === null || ! Hash::check($password, $existing->password_hash)))) {
                    throw new InvalidArgumentException('Issuance retry conflicts with the original intent.');
                }
                $this->content($existing); // Integrity/key loss never produces a replacement live payload.

                return $this->result($existing);
            }
            $issuedAt = now('UTC');
            $expiry = $expires?->copy()->utc() ?? $expiryPolicy->financialLifetime($lifetimeDays ?? ($statement ? 7 : 30), $statement, $issuedAt);
            $expiryPolicy->assertFinancialInstant($expiry, $statement, $issuedAt);
            if ($source instanceof Customer) {
                app(DocumentRenderLimits::class)->assertStatementSource((int) $company->id, (int) $source->id);
                $to = $options['to'] ?? now($company->timezone)->format('Y-m-d');
                $data = app(DocumentDataBuilder::class)->statement(app(CustomerStatementQuery::class)->execute($source, $options['from'] ?? null, $to), $options['locale'] ?? null);
            } else {
                $data = app(DocumentDataBuilder::class)->build($source, forPdf: true, locale: $options['locale'] ?? null);
            }
            $data = new DocumentData($data->type, $data->locale, $data->company, $data->customer,
                $data->document + ['issued_at' => $issuedAt->toIso8601String(), 'timezone' => $company->timezone],
                $data->lines, $data->statement, $data->presentation);
            $sealed = app(IssuedDocumentContent::class)->seal($data);
            $packet = DB::selectOne('SELECT @@max_allowed_packet AS packet_limit');
            // Include token, metadata, SQL and protocol overhead before attempting a large INSERT.
            // Application byte caps are ceilings; the deployment may impose a smaller transport budget.
            if ($packet === null || strlen($sealed['encrypted_snapshot']) + 65536 > (int) $packet->packet_limit) {
                throw new InvalidArgumentException('Issued content exceeds the database transport budget.');
            }
            $raw = Str::random(40);
            $share = PublicShare::create($sealed + [
                'company_id' => $company->id, 'subject_type' => $type, 'subject_id' => $id, 'token_lookup_hash' => hash('sha256', $raw),
                'encrypted_token' => Crypt::encryptString($raw), 'is_active' => true, 'expires_at' => $expiry,
                'password_hash' => $password === null ? null : Hash::make($password), 'created_by' => $actor->id,
                'access_profile' => self::PROFILE, 'subject_revision' => $source instanceof Quotation ? $policy->quotationRevision($source) : $sealed['content_hash'],
                'request_key' => $requestKey, 'request_hash' => $intentHash, 'issued_at' => $issuedAt,
            ]);
            $this->content($share);
            $policy->authorize((int) $company->id, $actor, $type);
            app(AuditService::class)->log(companyId: (int) $company->id, eventKey: 'share.issued', summary: 'Authorized financial disclosure issued', actorUserId: $actor->id, subject: $share, meta: ['profile' => self::PROFILE, 'subject_type' => $type]);

            return ['share' => $share, 'raw_token' => $raw, 'url' => app(ManagedPublicUrl::class)->make('share', $raw)];
        });
    }

    /** @return array{share:PublicShare,raw_token:string,url:string} */
    private function result(PublicShare $share): array
    {
        $raw = Crypt::decryptString($share->encrypted_token);

        return ['share' => $share, 'raw_token' => $raw, 'url' => app(ManagedPublicUrl::class)->make('share', $raw)];
    }

    public function lookup(string $raw): ?PublicShare
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/D', $raw)) {
            return null;
        }

        return PublicShare::withoutGlobalScopes()->where('token_lookup_hash', hash('sha256', $raw))->first();
    }

    public function valid(PublicShare $share): PublicShare
    {
        $fresh = PublicShare::withoutGlobalScopes()->findOrFail($share->id);
        if (! $fresh->isValid()) {
            throw new InvalidArgumentException('Unavailable share.');
        }
        $source = app(FinancialSharePolicy::class)->source((int) $fresh->company_id, $fresh->subject_type, (int) $fresh->subject_id);
        if ($fresh->access_profile === self::PROFILE && $source instanceof Quotation
            && ! hash_equals((string) $fresh->subject_revision, app(FinancialSharePolicy::class)->quotationRevision($source))) {
            throw new InvalidArgumentException('Issued quotation revision is no longer current.');
        }

        return $fresh;
    }

    public function content(PublicShare $share): DocumentData
    {
        $fresh = $this->valid($share);
        if ($fresh->access_profile !== self::PROFILE || ! is_string($fresh->encrypted_snapshot)
            || ! is_string($fresh->content_hash) || ! is_int($fresh->content_version)) {
            throw new InvalidArgumentException('Issued content is missing.');
        }

        return app(IssuedDocumentContent::class)->open($fresh->encrypted_snapshot, $fresh->content_hash, $fresh->content_version);
    }

    private function state(PublicShare $share): string
    {
        return hash('sha256', implode('|', [$share->public_id, $share->token_lookup_hash, $share->subject_type, $share->subject_id, $share->subject_revision, $share->content_hash, $share->password_hash, $share->expires_at?->timestamp]));
    }

    public function unlock(PublicShare $share, Request $request, ?string $password): void
    {
        if (! $request->isMethod('POST') || ! $request->hasSession()) {
            throw new InvalidArgumentException('Deliberate session confirmation is required.');
        }
        $fresh = $this->valid($share);
        if ($fresh->password_hash !== null && ($password === null || ! Hash::check($password, $fresh->password_hash))) {
            throw new InvalidArgumentException('Unable to open this link.');
        }
        $this->content($fresh);
        $request->session()->put('financial_unlock.'.$fresh->public_id, ['state' => $this->state($fresh), 'until' => min(now()->addMinutes(15)->timestamp, $fresh->expires_at->timestamp ?? PHP_INT_MAX)]);
    }

    public function authorized(PublicShare $share, Request $request): PublicShare
    {
        $fresh = $this->valid($share);
        $unlock = $request->hasSession() ? $request->session()->get('financial_unlock.'.$fresh->public_id) : null;
        if (! is_array($unlock) || ! is_string($unlock['state'] ?? null) || ($unlock['until'] ?? 0) <= now()->timestamp
            || ! hash_equals($this->state($fresh), $unlock['state'])) {
            throw new InvalidArgumentException('Deliberate document view is required.');
        }

        return $fresh;
    }

    public function recordView(PublicShare $share): void
    {
        CompanyScope::executeWithoutScope(fn () => PublicShare::whereKey($share->id)->where('is_active', true)->increment('view_count', 1, ['last_viewed_at' => now()]));
    }
}
