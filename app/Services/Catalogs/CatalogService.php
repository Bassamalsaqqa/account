<?php

declare(strict_types=1);

namespace App\Services\Catalogs;

use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PublicShare;
use App\Models\Unit;
use App\Services\Audit\AuditService;
use App\Services\Sales\IssuedDocumentContent;
use App\Services\Sales\ManagedPublicUrl;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CatalogService
{
    public const SUBJECT = 'product_catalog';

    public const MAX_ITEMS = 250;

    public function authorize(string $permission): Company
    {
        $companyId = app(CompanyContext::class)->companyId();
        abort_unless(auth()->check(), 403);
        $company = app(SalesActorGuard::class)->lockAndAuthorize($companyId, auth()->user(), 'catalogs.view');
        if ($permission !== 'catalogs.view') {
            app(SalesActorGuard::class)->lockAndAuthorize($companyId, auth()->user(), $permission);
        }

        return $company;
    }

    /** @param array<string,mixed> $fields
     * @param  array<array-key,array<string,mixed>>  $items
     */
    public function save(?int $id, array $fields, array $items, ?int $expectedRevision = null): Catalog
    {
        $allowed = ['name_ar', 'name_en', 'description_ar', 'description_en', 'locale', 'show_prices', 'show_sku', 'show_description', 'show_images', 'currency_code', 'tax_basis'];
        if (array_diff(array_keys($fields), $allowed) !== [] || count($items) > self::MAX_ITEMS || ! array_is_list($items)) {
            throw new InvalidArgumentException('Invalid bounded catalog draft.');
        }
        $fields = Validator::make($fields, ['name_ar' => 'required|string|max:160', 'name_en' => 'nullable|string|max:160',
            'description_ar' => 'nullable|string|max:2000', 'description_en' => 'nullable|string|max:2000', 'locale' => 'required|in:ar,en',
            'show_prices' => 'required|boolean', 'show_sku' => 'required|boolean', 'show_description' => 'required|boolean', 'show_images' => 'required|boolean',
            'currency_code' => 'nullable|string|size:3', 'tax_basis' => 'nullable|string|max:160'])->validate();

        return DB::transaction(function () use ($id, $fields, $items, $expectedRevision): Catalog {
            $company = $this->authorize('catalogs.manage');
            abort_unless(auth()->user()->hasAnyPermission(['inventory.stock.view', 'inventory.product.manage']), 403);
            $catalog = $id === null ? null : Catalog::where('company_id', $company->id)->lockForUpdate()->findOrFail($id);
            if ($catalog !== null && $catalog->draft_revision !== $expectedRevision) {
                throw new InvalidArgumentException('Catalog draft changed; reload before saving.');
            }
            if ($fields['show_prices'] || ($catalog !== null && $catalog->show_prices)) {
                $this->authorize('catalogs.show_prices');
            }
            $normalized = [];
            $seen = [];
            foreach ($items as $position => $item) {
                if (array_diff(array_keys($item), ['product_id', 'unit_id', 'image_id', 'name_ar', 'name_en', 'description_ar', 'description_en', 'custom_price']) !== []) {
                    throw new InvalidArgumentException('Unsupported catalog item field.');
                }
                $item = Validator::make($item, ['product_id' => 'required|integer|min:1', 'unit_id' => 'required|integer|min:1', 'image_id' => 'nullable|integer|min:1',
                    'name_ar' => 'nullable|string|max:160', 'name_en' => 'nullable|string|max:160', 'description_ar' => 'nullable|string|max:2000', 'description_en' => 'nullable|string|max:2000',
                    'custom_price' => ['nullable', 'string', 'regex:/^\d{1,14}(?:\.\d{1,6})?$/D']])->validate();
                $product = $this->product((int) $company->id, (int) $item['product_id']);
                if (isset($seen[$product->id])) {
                    throw new InvalidArgumentException('A Product may appear only once in a catalog.');
                }
                $seen[$product->id] = true;
                $this->unit((int) $company->id, (int) $product->id, (int) $item['unit_id']);
                if (isset($item['image_id'])) {
                    app(ApprovedCatalogMedia::class)->approve((int) $company->id, (int) $product->id, (int) $item['image_id']);
                }
                $normalized[] = $item + ['position' => $position + 1];
            }
            $catalog ??= new Catalog(['company_id' => $company->id, 'created_by' => auth()->id()]);
            $revision = $catalog->exists ? $catalog->draft_revision + 1 : 1;
            $catalog->fill($fields + ['draft_revision' => $revision, 'updated_by' => auth()->id()]);
            if (! $fields['show_prices']) {
                $catalog->currency_code = null;
                $catalog->tax_basis = null;
            }
            $catalog->save();
            $catalog->items()->where('company_id', $company->id)->delete(); // Draft metadata only, never published projection.
            foreach ($normalized as $item) {
                if (! $fields['show_prices']) {
                    $item['custom_price'] = null;
                }
                CatalogItem::create($item + ['catalog_id' => $catalog->id, 'company_id' => $company->id]);
            }
            $this->audit($catalog, 'catalog.draft.saved');

            return $catalog;
        });
    }

    private function product(int $companyId, int $productId): Product
    {
        return Product::where('company_id', $companyId)->where('active', true)->findOrFail($productId);
    }

    private function unit(int $companyId, int $productId, int $unitId): Unit
    {
        if (! ProductUnit::where('company_id', $companyId)->where('product_id', $productId)->where('unit_id', $unitId)->where('active', true)->exists()) {
            throw new InvalidArgumentException('Catalog Unit is not configured for this Product.');
        }

        return Unit::where('company_id', $companyId)->where('active', true)->findOrFail($unitId);
    }

    private function currency(int $companyId, ?string $code): Currency
    {
        if ($code === null || ! CompanyCurrency::where('company_id', $companyId)->where('currency_code', $code)->where('enabled', true)->exists()) {
            throw new InvalidArgumentException('Priced catalogs require an enabled Company currency.');
        }

        return Currency::where('code', $code)->firstOrFail();
    }

    /** @return array{revision:int,hash:string,payload:array<string,mixed>} */
    public function preview(int $id): array
    {
        return DB::transaction(function () use ($id): array {
            $company = $this->authorize('catalogs.publish');
            $catalog = Catalog::where('company_id', $company->id)->findOrFail($id);
            $payload = $this->candidate($company, $catalog);

            return ['revision' => $catalog->draft_revision, 'hash' => $this->fingerprint($payload), 'payload' => $payload];
        });
    }

    /** @return array<string,mixed> */
    private function candidate(Company $company, Catalog $catalog): array
    {
        $items = $catalog->items()->where('company_id', $company->id)->orderBy('position')->limit(self::MAX_ITEMS + 1)->get();
        if ($items->isEmpty() || $items->count() > self::MAX_ITEMS) {
            throw new InvalidArgumentException('Select between one and 250 catalog Products.');
        }
        $currency = null;
        if ($catalog->show_prices) {
            $this->authorize('catalogs.show_prices');
            $currency = $this->currency((int) $company->id, $catalog->currency_code);
            if (! is_string($catalog->tax_basis) || trim($catalog->tax_basis) === '') {
                throw new InvalidArgumentException('Specify the price tax basis before publishing.');
            }
        }
        $payload = ['version' => 1, 'locale' => $catalog->locale, 'name_ar' => $catalog->name_ar, 'name_en' => $catalog->name_en,
            'description_ar' => $catalog->description_ar, 'description_en' => $catalog->description_en,
            'company' => ['name_ar' => $company->name_ar, 'name_en' => $company->name_en, 'phone' => $company->phone, 'email' => $company->email],
            'items' => []];
        if ($currency !== null) {
            $payload['currency_code'] = $currency->code;
            $payload['tax_basis'] = $catalog->tax_basis;
        }
        foreach ($items as $item) {
            $product = $this->product((int) $company->id, (int) $item->product_id);
            $unit = $this->unit((int) $company->id, (int) $product->id, (int) $item->unit_id);
            $row = ['product_id' => (int) $product->id, 'unit_id' => (int) $unit->id,
                'name_ar' => $item->name_ar ?: $product->name_ar, 'name_en' => $item->name_en ?: $product->name_en,
                'unit_ar' => $unit->name_ar, 'unit_en' => $unit->name_en,
                'unit_ratio' => ProductUnit::where('company_id', $company->id)->where('product_id', $product->id)->where('unit_id', $unit->id)->value('conversion_to_base')];
            if ($catalog->show_sku) {
                $row['sku'] = $product->sku;
            }
            if ($catalog->show_description) {
                $row['description_ar'] = $item->description_ar ?: $product->description_ar;
                $row['description_en'] = $item->description_en ?: $product->description_en;
            }
            if ($catalog->show_images && $item->image_id !== null) {
                $row['media'] = app(ApprovedCatalogMedia::class)->approve((int) $company->id, (int) $product->id, (int) $item->image_id);
            }
            if ($currency !== null) {
                $price = $item->custom_price;
                if ($price !== null) {
                    BigDecimal::of($price)->toScale((int) $currency->minor_units); // Reject fractional precision, never round silently.
                }
                $row['price'] = $price;
            }
            $payload['items'][] = $row;
        }
        if (strlen(app(IssuedDocumentContent::class)->canonical($payload)) > 2 * 1024 * 1024) {
            throw new InvalidArgumentException('Catalog exceeds the publication budget.');
        }

        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function fingerprint(array $payload): string
    {
        return hash('sha256', app(IssuedDocumentContent::class)->canonical($payload));
    }

    public function publish(int $id, int $revision, string $previewHash, string $requestKey): Catalog
    {
        if (! preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D', $requestKey) || ! preg_match('/^[a-f0-9]{64}$/D', $previewHash)) {
            throw new InvalidArgumentException('Invalid publication confirmation.');
        }

        return DB::transaction(function () use ($id, $revision, $previewHash, $requestKey): Catalog {
            $company = $this->authorize('catalogs.publish');
            $catalog = Catalog::where('company_id', $company->id)->lockForUpdate()->findOrFail($id);
            $intent = hash('sha256', $id.'|'.$revision.'|'.$previewHash);
            $receipt = DB::table('catalog_publications')->where('company_id', $company->id)->where('request_key', $requestKey)->first();
            if ($receipt !== null) {
                if (! hash_equals($receipt->intent_hash, $intent)) {
                    throw new InvalidArgumentException('Publication retry conflicts.');
                }
                $approved = json_decode($receipt->payload, true, 64, JSON_THROW_ON_ERROR);
                if (array_key_exists('currency_code', $approved)) {
                    $this->authorize('catalogs.show_prices');
                }

                return $catalog;
            }
            if ($catalog->draft_revision !== $revision) {
                throw new InvalidArgumentException('Catalog preview is stale.');
            }
            $payload = $this->candidate($company, $catalog);
            if (! hash_equals($this->fingerprint($payload), $previewHash)) {
                throw new InvalidArgumentException('Catalog content or media changed since preview.');
            }
            $catalog->fill(['published_payload' => $payload, 'published_hash' => $previewHash, 'published_revision' => $catalog->published_revision + 1,
                'publication_key' => $requestKey, 'publication_intent_hash' => $intent, 'published_at' => now(), 'updated_by' => auth()->id()]);
            // Initial publication is active; pause/revocation remains until deliberate resume.
            if ($catalog->status === 'draft') {
                $catalog->status = 'active';
            }
            $catalog->save();
            DB::table('catalog_publications')->insert(['company_id' => $company->id, 'catalog_id' => $id, 'request_key' => $requestKey,
                'intent_hash' => $intent, 'content_hash' => $previewHash, 'revision' => $catalog->published_revision,
                'payload' => app(IssuedDocumentContent::class)->canonical($payload), 'created_by' => auth()->id(), 'created_at' => now()]);
            $this->audit($catalog, 'catalog.published');

            return $catalog;
        });
    }

    /** @return array{share:PublicShare,url:string} */
    public function share(int $id, ?string $password = null, ?string $expires = null): array
    {
        if ($password !== null && (strlen($password) < 8 || strlen($password) > 128)) {
            throw new InvalidArgumentException('Invalid catalog password length.');
        }
        Validator::make(['expires' => $expires], ['expires' => 'nullable|date_format:Y-m-d|after:today'])->validate();

        return DB::transaction(function () use ($id, $password, $expires): array {
            $company = $this->authorize('catalogs.share');
            $catalog = Catalog::where('company_id', $company->id)->lockForUpdate()->findOrFail($id);
            if ($catalog->status !== 'active' || $catalog->published_payload === null) {
                throw new InvalidArgumentException('Publish and activate before creating a link.');
            }
            if (array_key_exists('currency_code', $catalog->published_payload)) {
                $this->authorize('catalogs.show_prices');
            }
            $share = PublicShare::where('company_id', $company->id)->where('subject_type', self::SUBJECT)->where('subject_id', $id)->first();
            if ($share === null) {
                $raw = Str::random(40);
                $share = PublicShare::create(['company_id' => $company->id, 'subject_type' => self::SUBJECT, 'subject_id' => $id,
                    'token_lookup_hash' => hash('sha256', $raw), 'encrypted_token' => Crypt::encryptString($raw), 'is_active' => true,
                    'password_hash' => $password === null ? null : Hash::make($password), 'expires_at' => $expires, 'created_by' => auth()->id(),
                    'access_profile' => 'catalog_v1', 'request_key' => 'catalog:'.$catalog->public_id]);
                $this->audit($catalog, 'catalog.link.created');
            } elseif (($password !== null && ($share->password_hash === null || ! Hash::check($password, $share->password_hash)))
                || ($expires !== null && $share->expires_at?->format('Y-m-d') !== $expires)) {
                throw new InvalidArgumentException('Recovery cannot change existing catalog access settings.');
            }
            if (! $share->isValid()) {
                throw new InvalidArgumentException('Catalog link is unavailable; explicitly update its access settings.');
            }
            $raw = Crypt::decryptString($share->encrypted_token);

            return ['share' => $share, 'url' => app(ManagedPublicUrl::class)->make('catalog', $raw)];
        });
    }

    /** @return array{expires:?string,has_password:bool,state:string} */
    public function linkSettings(int $id): array
    {
        return DB::transaction(function () use ($id): array {
            $company = $this->authorize('catalogs.share');
            $catalog = Catalog::where('company_id', $company->id)->whereKey($id)->firstOrFail();
            if (array_key_exists('currency_code', $catalog->published_payload ?? [])) {
                $this->authorize('catalogs.show_prices');
            }
            $grant = PublicShare::where('company_id', $company->id)->where('subject_type', self::SUBJECT)->where('subject_id', $id)->firstOrFail();

            return ['expires' => $grant->expires_at?->format('Y-m-d'), 'has_password' => $grant->password_hash !== null, 'state' => $this->linkState($grant)];
        });
    }

    private function linkState(PublicShare $grant): string
    {
        return hash('sha256', implode('|', [$grant->token_lookup_hash, $grant->password_hash, $grant->expires_at?->timestamp, $grant->is_active ? '1' : '0']));
    }

    /** Deliberate access edit; blank password preserves protection and the managed URL stays stable. */
    public function updateLinkAccess(int $id, string $expectedState, ?string $expires, ?string $password = null): string
    {
        Validator::make(['expires' => $expires, 'password' => $password], ['expires' => 'nullable|date_format:Y-m-d|after:today', 'password' => 'nullable|string|min:8|max:128'])->validate();

        return DB::transaction(function () use ($id, $expectedState, $expires, $password): string {
            $company = $this->authorize('catalogs.share');
            $catalog = Catalog::where('company_id', $company->id)->lockForUpdate()->findOrFail($id);
            if ($catalog->status !== 'active' || $catalog->published_payload === null) {
                throw new InvalidArgumentException('An active published catalog is required.');
            }
            if (array_key_exists('currency_code', $catalog->published_payload)) {
                $this->authorize('catalogs.show_prices');
            }
            $grant = PublicShare::where('company_id', $company->id)->where('subject_type', self::SUBJECT)->where('subject_id', $id)->lockForUpdate()->firstOrFail();
            if (! $grant->is_active || $grant->access_profile !== 'catalog_v1') {
                throw new InvalidArgumentException('Catalog grant is retired.');
            }
            $samePassword = $password === null || ($grant->password_hash !== null && Hash::check($password, $grant->password_hash));
            $sameExpiry = $expires === $grant->expires_at?->format('Y-m-d');
            if (! hash_equals($this->linkState($grant), $expectedState) && (! $samePassword || ! $sameExpiry)) {
                throw new InvalidArgumentException('Catalog access settings changed. Reload before saving.');
            }
            if (! $samePassword || ! $sameExpiry) {
                $grant->expires_at = $expires;
                if ($password !== null && ! $samePassword) {
                    $grant->password_hash = Hash::make($password);
                }
                $grant->save();
                $this->audit($catalog, 'catalog.link.updated');
            }
            if (! $grant->isValid()) {
                throw new InvalidArgumentException('Catalog link remains unavailable.');
            }

            return app(ManagedPublicUrl::class)->make('catalog', Crypt::decryptString($grant->encrypted_token));
        });
    }

    public function state(int $id, string $state): void
    {
        if (! in_array($state, ['active', 'paused', 'revoked'], true)) {
            throw new InvalidArgumentException('Unsupported catalog lifecycle.');
        }
        DB::transaction(function () use ($id, $state): void {
            $company = $this->authorize($state === 'active' ? 'catalogs.publish' : 'catalogs.revoke');
            $catalog = Catalog::where('company_id', $company->id)->lockForUpdate()->findOrFail($id);
            if ($state === 'active' && $catalog->published_payload === null) {
                throw new InvalidArgumentException('Publish before resuming.');
            }
            if ($state === 'active' && array_key_exists('currency_code', $catalog->published_payload ?? [])) {
                $this->authorize('catalogs.show_prices');
            }
            $catalog->status = $state;
            $catalog->updated_by = auth()->id();
            $catalog->save();
            $this->audit($catalog, 'catalog.'.$state);
        });
    }

    private function audit(Catalog $catalog, string $event): void
    {
        app(AuditService::class)->log(companyId: (int) $catalog->company_id, eventKey: $event, summary: 'Catalog management action', actorUserId: auth()->id(), subject: $catalog,
            meta: ['draft_revision' => $catalog->draft_revision, 'published_revision' => $catalog->published_revision]);
    }

    /** Guest access always begins from the exact hashed grant and explicit tenant. */
    public function resolve(string $token): PublicShare
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/D', $token)) {
            throw new InvalidArgumentException('Unavailable catalog.');
        }
        $share = PublicShare::withoutGlobalScopes()->where('subject_type', self::SUBJECT)->where('access_profile', 'catalog_v1')->where('token_lookup_hash', hash('sha256', $token))->firstOrFail();
        if (! $share->isValid() || ! Company::whereKey($share->company_id)->where('status', 'active')->exists()) {
            throw new InvalidArgumentException('Unavailable catalog.');
        }
        if (! Catalog::withoutGlobalScopes()->where('company_id', $share->company_id)->whereKey($share->subject_id)->where('status', 'active')->whereNotNull('published_payload')->exists()) {
            throw new InvalidArgumentException('Unavailable catalog.');
        }

        return $share;
    }

    /** @return array<string,mixed> */
    public function publicData(PublicShare $grant, string $locale, int $page = 1, bool $embedded = false): array
    {
        if (! in_array($locale, ['ar', 'en'], true) || $page < 1 || $page > 11) {
            throw new InvalidArgumentException('Invalid catalog page.');
        }

        return CompanyScope::executeWithoutScope(function () use ($grant, $locale, $page, $embedded): array {
            $fresh = PublicShare::where('company_id', $grant->company_id)->where('subject_type', self::SUBJECT)->where('access_profile', 'catalog_v1')->findOrFail($grant->id);
            if (! app(CatalogAccess::class)->allows($fresh, request())) {
                throw new InvalidArgumentException('Catalog password confirmation is required.');
            }
            $company = Company::whereKey($fresh->company_id)->where('status', 'active')->firstOrFail();
            $catalog = Catalog::where('company_id', $company->id)->where('status', 'active')->findOrFail($fresh->subject_id);
            if (! $fresh->isValid() || ! is_array($catalog->published_payload) || ! hash_equals((string) $catalog->published_hash, $this->fingerprint($catalog->published_payload))) {
                throw new InvalidArgumentException('Unavailable catalog revision.');
            }
            $payload = $catalog->published_payload;
            if (($payload['version'] ?? null) !== 1 || count($payload['items'] ?? []) > self::MAX_ITEMS) {
                throw new InvalidArgumentException('Invalid published catalog.');
            }
            $priced = array_key_exists('currency_code', $payload);
            if ($priced) {
                $this->currency((int) $company->id, $payload['currency_code']);
            }
            $eligible = [];
            foreach ($payload['items'] as $item) {
                if (! Product::where('company_id', $company->id)->where('active', true)->whereKey($item['product_id'])->exists()) {
                    continue;
                }
                if ($priced) {
                    $this->unit((int) $company->id, $item['product_id'], $item['unit_id']);
                    $ratio = ProductUnit::where('company_id', $company->id)->where('product_id', $item['product_id'])->where('unit_id', $item['unit_id'])->value('conversion_to_base');
                    if (! BigDecimal::of($ratio)->isEqualTo($item['unit_ratio'])) {
                        throw new InvalidArgumentException('Published price Unit configuration is no longer valid.');
                    }
                }
                $eligible[] = $item;
            }
            $dto = ['locale' => $locale, 'title' => $payload['name_'.$locale] ?: $payload['name_ar'], 'description' => $payload['description_'.$locale] ?: $payload['description_ar'],
                'company' => ['name' => $payload['company']['name_'.$locale] ?: $payload['company']['name_ar'], 'phone' => $payload['company']['phone'], 'email' => $payload['company']['email']],
                'revision' => $catalog->published_revision, 'page' => $page, 'pages' => max(1, intdiv(count($eligible) + 23, 24)), 'items' => []];
            if ($priced) {
                $dto['currency_code'] = $payload['currency_code'];
                $dto['tax_basis'] = $payload['tax_basis'];
            }
            $rows = $embedded ? $eligible : array_slice($eligible, ($page - 1) * 24, 24);
            $imageBytes = 0;
            foreach ($rows as $item) {
                $row = ['name' => $item['name_'.$locale] ?: $item['name_ar'], 'unit' => $item['unit_'.$locale] ?: $item['unit_ar']];
                if (isset($item['sku'])) {
                    $row['sku'] = $item['sku'];
                }
                if (array_key_exists('description_ar', $item)) {
                    $row['description'] = $item['description_'.$locale] ?: $item['description_ar'];
                }
                $row['image'] = isset($item['media']) ? app(ApprovedCatalogMedia::class)->render($item['media'], (int) $company->id, $item['product_id'], $embedded) : null;
                if ($embedded && is_string($row['image'])) {
                    $imageBytes += strlen($row['image']);
                    if ($imageBytes > 4 * 1024 * 1024) {
                        throw new InvalidArgumentException('Catalog PDF exceeds the aggregate image budget.');
                    }
                }
                if ($priced) {
                    $row['price'] = $item['price'];
                }
                $dto['items'][] = $row;
            }

            return $dto;
        });
    }
}
