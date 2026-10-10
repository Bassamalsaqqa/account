<?php

declare(strict_types=1);

namespace Tests\Feature\Phase9;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Models\Catalog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PublicShare;
use App\Models\Unit;
use App\Models\User;
use App\Services\Catalogs\CatalogService;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Sales\IssuedDocumentContent;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

/** Real independent connections; no RefreshDatabase transaction hides fixtures from children. */
class PublicationConcurrencyTest extends TestCase
{
    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());
        app(CompanyContext::class)->clear();
        $this->owner = User::factory()->create();
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'Publication race company', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        auth()->login($this->owner);
        setPermissionsTeamId($this->company->id);
        $this->assertSame(0, DB::transactionLevel());
    }

    private function customer(): Customer
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'Issued statement customer', 'active' => true, 'created_by' => $this->owner->id]);
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id, 'currency_code' => 'USD', 'exchange_rate' => '3.5', 'issue_date' => '2026-10-01',
            'lines' => [['item_description' => 'Canonical race fixture', 'quantity' => '2', 'unit_price' => '80']],
        ]);
        app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);

        return $customer;
    }

    public function test_simultaneous_statement_retries_return_one_verified_snapshot_and_same_token(): void
    {
        $customer = $this->customer();
        $before = $this->economicFingerprint();
        $intent = ['operation' => 'statement', 'subject_id' => $customer->id, 'key' => 'same-statement', 'scope' => ['locale' => 'ar', 'to' => '2026-10-09']];
        [$first, $second] = $this->race($intent, $intent);
        $this->assertSame('ok', $first['status']);
        $this->assertSame($first, $second);
        $shares = PublicShare::where('company_id', $this->company->id)->where('request_key', $intent['key'])->get();
        $this->assertCount(1, $shares);
        $share = $shares->sole();
        $this->assertTrue($share->is_active);
        $this->assertSame($first['token_hash'], hash('sha256', Crypt::decryptString($share->encrypted_token)));
        $this->assertSame($first['content_hash'], $share->content_hash);
        $content = app(IssuedDocumentContent::class)->open($share->encrypted_snapshot, $share->content_hash, $share->content_version);
        $this->assertSame('customer_statement', $content->type);
        $this->assertNotEmpty($content->statement);
        $this->assertSame(1, DB::table('audit_events')->where('company_id', $this->company->id)->where('event_key', 'share.issued')->count());
        $this->assertSame($before, $this->economicFingerprint());
    }

    public function test_simultaneous_changed_statement_scope_has_one_winner_and_one_conflict(): void
    {
        $customer = $this->customer();
        $before = $this->economicFingerprint();
        $first = ['operation' => 'statement', 'subject_id' => $customer->id, 'key' => 'changed-statement', 'scope' => ['locale' => 'ar', 'to' => '2026-10-09']];
        $second = array_replace($first, ['scope' => ['locale' => 'en', 'to' => '2026-10-09']]);
        $results = $this->race($first, $second);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['conflict', 'ok'], $statuses);
        $shares = PublicShare::where('company_id', $this->company->id)->where('request_key', $first['key'])->get();
        $this->assertCount(1, $shares);
        $winner = $results[0]['status'] === 'ok' ? $results[0] : $results[1];
        $share = $shares->sole();
        $this->assertSame($winner['content_hash'], $share->content_hash);
        $this->assertSame($winner['ciphertext_hash'], hash('sha256', $share->encrypted_snapshot));
        $this->assertSame($results[0]['status'] === 'ok' ? 'ar' : 'en', app(IssuedDocumentContent::class)->open($share->encrypted_snapshot, $share->content_hash, $share->content_version)->locale);
        $this->assertSame(1, DB::table('audit_events')->where('company_id', $this->company->id)->where('event_key', 'share.issued')->count());
        $this->assertSame($before, $this->economicFingerprint());
    }

    public function test_simultaneous_catalog_publication_retries_create_one_revision_and_one_receipt(): void
    {
        $product = app(ProductCatalogService::class)->createProduct($this->company, ['name_ar' => 'Selected Product', 'sku' => 'RACE-1',
            'product_type' => Product::TYPE_STOCK, 'track_stock' => true, 'track_expiry' => false,
            'base_unit_id' => Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail()->id], $this->owner->id);
        $service = app(CatalogService::class);
        $catalog = $service->save(null, ['name_ar' => 'Published once', 'locale' => 'ar', 'show_prices' => false,
            'show_sku' => true, 'show_description' => false, 'show_images' => false], [['product_id' => $product->id, 'unit_id' => $product->base_unit_id]]);
        $preview = $service->preview($catalog->id);
        $before = $this->economicFingerprint();
        $intent = ['operation' => 'catalog', 'subject_id' => $catalog->id, 'key' => 'same-catalog', 'revision' => $preview['revision'], 'preview_hash' => $preview['hash']];
        [$first, $second] = $this->race($intent, $intent);
        $this->assertSame('ok', $first['status']);
        $this->assertSame($first, $second);
        $fresh = $catalog->fresh();
        $this->assertSame(1, $fresh->published_revision);
        $this->assertSame($preview['hash'], $fresh->published_hash);
        $this->assertSame(1, DB::table('catalog_publications')->where('company_id', $this->company->id)->where('request_key', $intent['key'])->count());
        $this->assertSame(1, DB::table('audit_events')->where('company_id', $this->company->id)->where('event_key', 'catalog.published')->count());
        $this->assertSame($before, $this->economicFingerprint());
    }

    private function publishedCatalog(): Catalog
    {
        $product = app(ProductCatalogService::class)->createProduct($this->company, ['name_ar' => 'منتج السباق', 'name_en' => 'Race catalog product', 'sku' => 'LINK-RACE',
            'product_type' => Product::TYPE_STOCK, 'track_stock' => true, 'track_expiry' => false,
            'base_unit_id' => Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail()->id], $this->owner->id);
        $service = app(CatalogService::class);
        $catalog = $service->save(null, ['name_ar' => 'كتالوج السباق', 'locale' => 'ar', 'show_prices' => false,
            'show_sku' => true, 'show_description' => false, 'show_images' => false], [['product_id' => $product->id, 'unit_id' => $product->base_unit_id]]);
        $preview = $service->preview($catalog->id);

        return $service->publish($catalog->id, $preview['revision'], $preview['hash'], 'link-race-publication');
    }

    public function test_concurrent_new_link_retries_converge_and_conflicting_intent_cannot_rotate_again(): void
    {
        $catalog = $this->publishedCatalog();
        $service = app(CatalogService::class);
        $old = $service->share($catalog->id);
        $service->state($catalog->id, 'revoked');
        $before = $this->economicFingerprint();
        $intent = ['operation' => 'catalog-new-link', 'subject_id' => $catalog->id, 'key' => 'new-link-retry'];
        [$first, $second] = $this->race($intent, $intent);
        $this->assertSame('ok', $first['status']);
        $this->assertSame($first, $second);
        $this->assertNotSame($old['share']->token_lookup_hash, $first['token_hash']);
        $this->assertFalse($old['share']->fresh()->isValid());
        $this->assertSame(2, PublicShare::where('company_id', $this->company->id)->where('subject_id', $catalog->id)->where('subject_type', CatalogService::SUBJECT)->count());
        $results = $this->race($intent, $intent + ['password' => 'Different-password']);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['conflict', 'ok'], $statuses);
        $this->assertSame(1, PublicShare::where('company_id', $this->company->id)->where('subject_type', CatalogService::SUBJECT)->where('is_active', true)->count());
        $this->assertSame(1, DB::table('catalog_publications')->where('catalog_id', $catalog->id)->count());
        $this->assertSame($before, $this->economicFingerprint());
    }

    public function test_concurrent_revoke_and_new_link_never_resurrect_the_original_token(): void
    {
        $catalog = $this->publishedCatalog();
        $old = app(CatalogService::class)->share($catalog->id);
        $before = $this->economicFingerprint();
        [$revocation, $issuance] = $this->race(['operation' => 'catalog-revoke', 'subject_id' => $catalog->id],
            ['operation' => 'catalog-new-link', 'subject_id' => $catalog->id, 'key' => 'race-new-link']);
        $this->assertSame('ok', $revocation['status']);
        $this->assertSame('ok', $issuance['status']);
        $this->assertFalse($old['share']->fresh()->is_active);
        $this->assertNotNull($old['share']->fresh()->revoked_at);
        $this->assertNotSame($old['share']->token_lookup_hash, $issuance['token_hash']);
        $current = PublicShare::where('company_id', $this->company->id)->findOrFail($issuance['id']);
        $this->assertSame($catalog->fresh()->status === 'active', $current->isValid());
        $this->assertSame(1, DB::table('catalog_publications')->where('catalog_id', $catalog->id)->count());
        $this->assertSame($before, $this->economicFingerprint());
    }

    public function test_revoke_committed_during_public_render_denies_the_in_flight_response(): void
    {
        $catalog = $this->publishedCatalog();
        $grant = app(CatalogService::class)->share($catalog->id)['share'];
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phase9-render-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $paths = array_map(fn ($name) => $directory.DIRECTORY_SEPARATOR.$name, ['payload.json', 'ready', 'release', 'attempting', 'rendering', 'finish']);
        [$payloadPath, $ready, $release, $attempting, $rendering, $finish] = $paths;
        $environment = ['APP_KEY' => (string) config('app.key'), 'APP_URL' => (string) config('app.url'), 'DB_URL' => '',
            'APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            $environment['DB_'.strtoupper($key)] = (string) config('database.connections.mysql.'.$key);
        }
        file_put_contents($payloadPath, json_encode(['company_id' => $this->company->id, 'actor_id' => $this->owner->id,
            'operation' => 'catalog-read', 'grant_id' => $grant->id, 'ready' => $ready, 'release' => $release,
            'attempting' => $attempting, 'rendering' => $rendering, 'finish_render' => $finish], JSON_THROW_ON_ERROR));
        chmod($payloadPath, 0600);
        $process = new Process([PHP_BINARY, base_path('tests/Support/phase9-publication-worker.php'), $payloadPath], base_path(), $environment);
        $before = $this->economicFingerprint();
        try {
            $process->setTimeout(45)->start();
            $this->waitForFiles([$ready], [$process]);
            file_put_contents($release, 'release');
            $this->waitForFiles([$rendering], [$process]);
            app(CatalogService::class)->state($catalog->id, 'revoked');
            file_put_contents($finish, 'finish');
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(404, $result['http_status']);
            $this->assertFalse($result['disclosed']);
            $this->assertFalse($grant->fresh()->isValid());
            $this->assertSame($before, $this->economicFingerprint());
        } finally {
            if ($process->isRunning()) {
                $process->stop();
            }
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    /** @param array<string,mixed> $first
     * @param  array<string,mixed>  $second
     * @return array{array<string,mixed>,array<string,mixed>}
     */
    private function race(array $first, array $second): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phase9-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $release = $directory.DIRECTORY_SEPARATOR.'release';
        $processes = [];
        $files = [];
        $environment = ['APP_KEY' => (string) config('app.key'), 'APP_URL' => (string) config('app.url'),
            'DB_URL' => '', 'APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            $environment['DB_'.strtoupper($key)] = (string) config('database.connections.mysql.'.$key);
        }
        $locked = false;
        try {
            DB::beginTransaction();
            $locked = true;
            DB::table('companies')->where('id', $this->company->id)->lockForUpdate()->first();
            foreach ([$first, $second] as $index => $intent) {
                $payloadPath = $directory.DIRECTORY_SEPARATOR.'payload-'.$index.'.json';
                $ready = $directory.DIRECTORY_SEPARATOR.'ready-'.$index;
                $attempting = $directory.DIRECTORY_SEPARATOR.'attempting-'.$index;
                $files = array_merge($files, [$payloadPath, $ready, $attempting]);
                file_put_contents($payloadPath, json_encode($intent + ['company_id' => $this->company->id, 'actor_id' => $this->owner->id,
                    'ready' => $ready, 'attempting' => $attempting, 'release' => $release], JSON_THROW_ON_ERROR));
                chmod($payloadPath, 0600);
                $process = new Process([PHP_BINARY, base_path('tests/Support/phase9-publication-worker.php'), $payloadPath], base_path(), $environment);
                $process->setTimeout(45)->start();
                $processes[] = $process;
            }
            $this->waitForFiles([$files[1], $files[4]], $processes);
            file_put_contents($release, 'release');
            $this->waitForFiles([$files[2], $files[5]], $processes);
            usleep(200000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Both independent publication attempts must overlap while Company lock is held.');
                $this->assertSame('', $process->getOutput());
            }
            DB::commit();
            $locked = false;
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return [$results[0], $results[1]];
        } finally {
            if ($locked) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (array_merge($files, [$release]) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    /** @param list<string> $files
     * @param  list<Process>  $processes
     */
    private function waitForFiles(array $files, array $processes): void
    {
        $deadline = microtime(true) + 20;
        do {
            if (count(array_filter($files, 'is_file')) === count($files)) {
                return;
            }
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    $this->fail('Child publication process exited before the barrier: '.$process->getErrorOutput());
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Independent publication process barrier timed out.');
    }

    private function economicFingerprint(): string
    {
        $rows = [];
        foreach (['posting_batches', 'posting_lines', 'stock_movements', 'sales_invoices', 'sales_invoice_lines'] as $table) {
            $rows[$table] = DB::table($table)->where('company_id', $this->company->id)->orderBy('id')->get()->toArray();
        }

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
