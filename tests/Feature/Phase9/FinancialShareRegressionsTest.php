<?php

declare(strict_types=1);

namespace Tests\Feature\Phase9;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Sales\ApplyCustomerPaymentCreditAction;
use App\Actions\Sales\CreateQuotationAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Actions\Sales\ReverseCustomerPaymentAction;
use App\Actions\Sales\UpdateQuotationAction;
use App\Domain\Sales\Documents\DocumentData;
use App\Domain\Sales\Queries\CustomerStatementQuery;
use App\Models\Customer;
use App\Models\PublicShare;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Sales\DocumentDataBuilder;
use App\Services\Sales\IssuedDocumentContent;
use App\Services\Sales\IssuedFinancialShares;
use App\Services\Sales\ManagedPublicUrl;
use App\Services\Sales\PdfRendererService;
use App\Services\Sales\PublicShareService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Mockery;
use RuntimeException;
use Tests\Feature\Phase5E\Phase5ETestCase;

class FinancialShareRegressionsTest extends Phase5ETestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('financial-ip:'.hash('sha256', '127.0.0.1'));
    }

    private function invoice(?Customer $customer = null, string $quantity = '2'): SalesInvoice
    {
        $customer ??= Customer::create(['company_id' => $this->company->id, 'name_ar' => 'عميل خاص', 'name_en' => 'Disclosure customer', 'active' => true, 'created_by' => $this->owner->id]);
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id, 'currency_code' => 'USD', 'exchange_rate' => '3.5', 'issue_date' => '2026-10-01',
            'lines' => [['item_description' => 'Private mixed وصف', 'quantity' => $quantity, 'unit_price' => '100']],
        ]);

        return app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
    }

    /** @return array{share:PublicShare,raw_token:string,url:string} */
    private function issue(string $type, int $id, ?string $key = null): array
    {
        return app(PublicShareService::class)->createShare($this->company, $this->owner, $type, $id,
            password: $type === PublicShare::SUBJECT_CUSTOMER_STATEMENT ? 'statement-pass' : null,
            requestKey: $key, scope: ['locale' => 'en']);
    }

    private function quote(Customer $customer): Quotation
    {
        $quote = app(CreateQuotationAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id, 'currency_code' => 'USD', 'exchange_rate' => '3.5', 'issue_date' => '2026-10-01',
            'lines' => [['item_description' => 'Issued quote only', 'quantity' => '1', 'unit_price' => '123']],
        ]);
        $quote->transition(Quotation::STATUS_SENT, $this->owner);

        return $quote;
    }

    public function test_all_five_subjects_have_neutral_first_get_head_and_alternate_formats(): void
    {
        $invoice = $this->invoice();
        $quote = $this->quote($invoice->customer);
        $return = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $invoice->id, 'issue_date' => '2026-10-02',
            'lines' => [['sales_invoice_line_id' => $invoice->lines()->firstOrFail()->id, 'quantity' => '1']],
        ]);
        $return = app(PostSalesReturnAction::class)->execute($return, $this->owner);
        $payment = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $invoice->customer_id, 'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-03', 'payment_method' => 'cash', 'amount' => '330', 'exchange_rate' => '1',
            'idempotency_key' => 'all-subject-receipt',
            'allocations' => [['sales_invoice_id' => $invoice->id, 'allocated_amount' => '100', 'payment_currency_amount' => '330']],
        ]);
        $subjects = [
            [PublicShare::SUBJECT_QUOTATION, $quote->id, $quote->quotation_number],
            [PublicShare::SUBJECT_SALES_INVOICE, $invoice->id, $invoice->invoice_number],
            [PublicShare::SUBJECT_SALES_RETURN, $return->id, $return->return_number],
            [PublicShare::SUBJECT_CUSTOMER_PAYMENT, $payment->id, $payment->payment_number],
            [PublicShare::SUBJECT_CUSTOMER_STATEMENT, $invoice->customer_id, $invoice->customer->code],
        ];
        foreach ($subjects as [$type, $id, $number]) {
            $issued = $this->issue($type, $id);
            auth()->logout();
            foreach (['', '?format=pdf', '?format=print', '?format=json'] as $suffix) {
                $response = $this->withHeader('User-Agent', 'WhatsApp link preview')->get($issued['url'].$suffix)->assertOk();
                foreach ([$number, 'Disclosure customer', 'Private mixed', 'Issued quote only', '/storage/', 'application/pdf'] as $secret) {
                    if (is_string($secret) && $secret !== '') {
                        $response->assertDontSee($secret, false);
                    }
                }
                $this->head($issued['url'].$suffix)->assertOk()->assertContent('');
            }
            $this->assertSame(0, $issued['share']->fresh()->view_count);
            $this->activate($this->owner);
        }
    }

    public function test_shared_receipt_keeps_original_cross_currency_scope_after_later_application_and_denies_reversal(): void
    {
        $invoice = $this->invoice(quantity: '1');
        $payment = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $invoice->customer_id, 'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-03', 'payment_method' => 'cash', 'amount' => '660', 'exchange_rate' => '1',
            'idempotency_key' => 'frozen-receipt',
            'allocations' => [['sales_invoice_id' => $invoice->id, 'allocated_amount' => '100', 'payment_currency_amount' => '330']],
        ]);
        $issued = $this->issue(PublicShare::SUBJECT_CUSTOMER_PAYMENT, $payment->id);
        $this->post($issued['url'])->assertRedirect();
        $original = $this->get($issued['url'].'?format=json')->assertOk()->json();
        $this->assertSame('USD', $original['lines'][0]['currency_code']);
        $this->assertSame('100.000000', $original['lines'][0]['total']);
        $this->assertSame('ILS', $original['lines'][0]['payment_currency_code']);
        $this->assertSame('330.000000', $original['lines'][0]['payment_currency_amount']);
        $this->assertSame('330.000000', $original['lines'][0]['settlement_base_value']);
        $second = $this->invoice($invoice->customer, '1');
        app(ApplyCustomerPaymentCreditAction::class)->execute($payment, $this->owner, [
            'application_date' => '2026-10-04', 'idempotency_key' => 'later-disclosure-leg',
            'allocations' => [['sales_invoice_id' => $second->id, 'allocated_amount' => '100', 'payment_currency_amount' => '330']],
        ]);
        $this->assertSame($original, $this->get($issued['url'].'?format=json')->assertOk()->json());
        $this->get($issued['url'])->assertSee($invoice->invoice_number)->assertDontSee($second->invoice_number);
        $renderer = new class extends PdfRendererService
        {
            public ?DocumentData $captured = null;

            public function renderDocument(DocumentData $data, ?string $qrUrl = null, bool $guest = false): string
            {
                $this->captured = $data;

                return parent::renderDocument($data, $qrUrl, $guest);
            }
        };
        $this->app->instance(PdfRendererService::class, $renderer);
        $economics = $this->economicFingerprint();
        $this->get($issued['url'].'?format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame($original, $renderer->captured?->toArray());
        $this->assertSame([], $renderer->captured?->applications);
        $this->assertSame($economics, $this->economicFingerprint());
        $private = app(DocumentDataBuilder::class)->build($payment->fresh(), includeApplications: true);
        $this->assertCount(1, $private->applications);
        $this->assertSame($second->invoice_number, $private->applications[0]['item_description']);
        app(ReverseCustomerPaymentAction::class)->execute($payment->fresh(), $this->owner, 'Reverse issued receipt', '2026-10-05');
        foreach (['', '?format=json', '?format=print', '?format=pdf'] as $suffix) {
            $this->get($issued['url'].$suffix)->assertNotFound()->assertDontSee($invoice->invoice_number);
        }
    }

    public function test_quotation_return_to_draft_edit_and_resend_requires_new_grant(): void
    {
        $quote = $this->quote($this->invoice()->customer);
        $issued = $this->issue(PublicShare::SUBJECT_QUOTATION, $quote->id);
        $this->post($issued['url'])->assertRedirect();
        $this->get($issued['url'].'?format=json')->assertJsonPath('lines.0.total', '123.000000');
        $quote->transition(Quotation::STATUS_DRAFT, $this->owner);
        $this->get($issued['url'])->assertNotFound();
        $quote = app(UpdateQuotationAction::class)->execute($quote, $this->owner, ['lines' => [['item_description' => 'Fresh approved revision', 'quantity' => '1', 'unit_price' => '456']]]);
        $quote->transition(Quotation::STATUS_SENT, $this->owner);
        foreach (['', '?format=json', '?format=print', '?format=pdf'] as $suffix) {
            $this->get($issued['url'].$suffix)->assertNotFound()->assertDontSee('Fresh approved revision');
        }
        $fresh = $this->issue(PublicShare::SUBJECT_QUOTATION, $quote->id);
        $this->assertNotSame($issued['raw_token'], $fresh['raw_token']);
        $this->get($fresh['url'])->assertDontSee('Fresh approved revision');
        $this->post($fresh['url'])->assertRedirect();
        $this->get($fresh['url'].'?format=json')->assertJsonPath('lines.0.total', '456.000000');
    }

    public function test_foreign_source_management_and_inactive_membership_are_denied(): void
    {
        $invoice = $this->invoice();
        $ownShare = $this->issue(PublicShare::SUBJECT_SALES_INVOICE, $invoice->id);
        app(CompanyContext::class)->clear();
        $foreignOwner = User::factory()->create();
        $foreignCompany = app(CreateCompanyAction::class)->execute($foreignOwner, ['name_ar' => 'شركة أخرى', 'name_en' => 'Foreign Company', 'base_currency_code' => 'ILS']);
        $this->actingAs($foreignOwner);
        app(CompanyContext::class)->setCompany($foreignCompany, $foreignOwner);
        $foreignCustomer = Customer::create(['company_id' => $foreignCompany->id, 'name_ar' => 'عميل آخر', 'name_en' => 'Foreign private customer', 'active' => true]);
        $foreignShare = app(PublicShareService::class)->createShare($foreignCompany, $foreignOwner, PublicShare::SUBJECT_CUSTOMER_STATEMENT, $foreignCustomer->id, password: 'foreign-secret');
        $this->activate($this->owner);
        try {
            $this->issue(PublicShare::SUBJECT_CUSTOMER_STATEMENT, $foreignCustomer->id);
            $this->fail('Foreign source was accepted.');
        } catch (ModelNotFoundException) {
            $this->assertTrue($foreignShare['share']->is_active);
        }
        foreach (['recover', 'revoke'] as $operation) {
            try {
                $operation === 'recover'
                    ? app(PublicShareService::class)->urlFor($foreignShare['share'], $this->owner)
                    : app(PublicShareService::class)->revokeShare($foreignShare['share'], $this->owner);
                $this->fail('Foreign grant management was accepted.');
            } catch (AuthorizationException) {
                $this->assertTrue(PublicShare::withoutGlobalScopes()->findOrFail($foreignShare['share']->id)->is_active);
            }
        }
        $actor = $this->customActor(['sales.document.share', 'sales.invoice.view']);
        $this->activate($actor);
        $actor->load('roles', 'permissions');
        DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $actor->id)->update(['status' => 'inactive']);
        foreach (['create', 'recover', 'revoke'] as $operation) {
            try {
                match ($operation) {
                    'create' => app(PublicShareService::class)->createShare($this->company, $actor, PublicShare::SUBJECT_SALES_INVOICE, $invoice->id),
                    'recover' => app(PublicShareService::class)->urlFor($ownShare['share'], $actor),
                    'revoke' => app(PublicShareService::class)->revokeShare($ownShare['share'], $actor),
                };
                $this->fail('Inactive membership was accepted.');
            } catch (AuthorizationException) {
                $this->assertTrue($ownShare['share']->fresh()->is_active);
            }
        }
    }

    public function test_failed_encryption_and_post_insert_interruption_leave_no_active_orphan_grant(): void
    {
        $invoice = $this->invoice();
        $originalCrypt = Crypt::getFacadeRoot();
        Crypt::swap(Mockery::mock($originalCrypt)->shouldReceive('encryptString')->once()->andThrow(new RuntimeException('Injected encryption failure'))->getMock());
        try {
            $this->issue(PublicShare::SUBJECT_SALES_INVOICE, $invoice->id, 'encrypt-failure');
            $this->fail('Encryption failure was ignored.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected encryption failure', $exception->getMessage());
            $this->assertSame(0, PublicShare::count());
            $this->assertSame(0, DB::table('audit_events')->where('event_key', 'share.issued')->count());
        } finally {
            Crypt::swap($originalCrypt);
        }
        $originalAudit = app(AuditService::class);
        $this->app->instance(AuditService::class, Mockery::mock(AuditService::class)->shouldReceive('log')->once()->andReturnUsing(function () {
            $this->assertSame(1, PublicShare::count()); // Fault is after the share/snapshot insert, before commit.
            throw new RuntimeException('Injected post-insert interruption');
        })->getMock());
        try {
            $this->issue(PublicShare::SUBJECT_SALES_INVOICE, $invoice->id, 'interrupted-insert');
            $this->fail('Post-insert failure was ignored.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected post-insert interruption', $exception->getMessage());
            $this->assertSame(0, PublicShare::count());
            $this->assertSame(0, DB::table('audit_events')->where('event_key', 'share.issued')->count());
        } finally {
            $this->app->instance(AuditService::class, $originalAudit);
        }
        $issued = $this->issue(PublicShare::SUBJECT_SALES_INVOICE, $invoice->id, 'interrupted-insert');
        $this->assertTrue($issued['share']->is_active);
        $this->assertSame(1, PublicShare::count());
    }

    public function test_statement_entry_and_byte_caps_are_independent_and_exact(): void
    {
        $invoice = $this->invoice();
        $codec = app(IssuedDocumentContent::class);
        $data = app(DocumentDataBuilder::class)->statement(app(CustomerStatementQuery::class)->execute($invoice->customer, '2026-10-01', '2026-10-09'), 'en')->toArray();
        $entry = $data['statement']['currencies']['USD']['entries'][0];
        // Capacity fixture repeats a canonical DTO entry; it creates no economic records.
        $data['statement']['currencies']['USD']['entries'] = array_fill(0, 1000, $entry);
        $sealed = $codec->seal($codec->document($data));
        $this->assertSame($codec->canonical($data), $codec->canonical($codec->open($sealed['encrypted_snapshot'], $sealed['content_hash'], 1)->toArray()));
        $data['statement']['currencies']['USD']['entries'][] = $entry;
        try {
            $codec->seal($codec->document($data));
            $this->fail('1001 entries were accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, PublicShare::count());
        }
        $base = app(DocumentDataBuilder::class)->build($invoice, forPdf: true)->toArray();
        // Synthetic image bytes exercise the codec's byte envelope only, never a renderer or stored media.
        $base['presentation']['logo'] = 'data:image/png;base64,';
        $base['presentation']['logo'] .= str_repeat('A', IssuedDocumentContent::MAX_PLAINTEXT - strlen($codec->canonical($base)) - 64);
        $this->assertSame(IssuedDocumentContent::MAX_PLAINTEXT - 64, strlen($codec->canonical($base)));
        $near = $codec->seal($codec->document($base));
        $this->assertLessThan(IssuedDocumentContent::MAX_CIPHERTEXT, strlen($near['encrypted_snapshot']));
        $this->assertSame($codec->canonical($base), $codec->canonical($codec->open($near['encrypted_snapshot'], $near['content_hash'], 1)->toArray()));
        $base['presentation']['logo'] = 'data:image/png;base64,'.str_repeat('A', IssuedDocumentContent::MAX_PLAINTEXT + 1);
        try {
            $codec->seal($codec->document($base));
            $this->fail('Oversized plaintext was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('plaintext limit', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $codec->open(str_repeat('A', IssuedDocumentContent::MAX_CIPHERTEXT + 1), str_repeat('0', 64), 1);
    }

    public function test_forward_down_forward_preserves_original_legacy_and_issued_ciphertext(): void
    {
        $invoice = $this->invoice();
        $issued = $this->issue(PublicShare::SUBJECT_SALES_INVOICE, $invoice->id);
        $raw = str_repeat('Z', 40);
        PublicShare::create(['company_id' => $this->company->id, 'subject_type' => PublicShare::SUBJECT_SALES_INVOICE, 'subject_id' => $invoice->id,
            'token_lookup_hash' => hash('sha256', $raw), 'encrypted_token' => Crypt::encryptString($raw), 'password_hash' => password_hash('legacy-secret', PASSWORD_BCRYPT),
            'is_active' => true, 'expires_at' => now()->addDays(2)]);
        $before = DB::table('public_shares')->orderBy('id')->get()->toJson();
        $migration = require database_path('migrations/2026_10_09_000001_add_issued_content_to_public_shares.php');
        $migration->down();
        $migration->up();
        $this->assertSame($before, DB::table('public_shares')->orderBy('id')->get()->toJson());
        $this->assertSame($invoice->invoice_number, app(IssuedFinancialShares::class)->content($issued['share'])->document['number']);
        $this->assertNotNull(DB::selectOne("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='public_shares' AND INDEX_NAME='public_share_company_request_unique'"));
    }

    public function test_near_cap_ciphertext_roundtrips_through_mediumtext_under_strict_mariadb(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertMatchesRegularExpression('/^accounting_p8_tmp_[a-f0-9]{12}$/D', DB::connection()->getDatabaseName());
        $invoice = $this->invoice();
        $issued = $this->issue(PublicShare::SUBJECT_CUSTOMER_STATEMENT, $invoice->customer_id);
        $codec = app(IssuedDocumentContent::class);
        $real = app(IssuedFinancialShares::class)->content($issued['share'])->toArray();
        $data = $real;
        // Synthetic boundary envelope on an actual canonical Statement DTO. This is not issued image/render evidence.
        $data['presentation']['logo'] = 'data:image/png;base64,';
        $data['presentation']['logo'] .= str_repeat('A', IssuedDocumentContent::MAX_PLAINTEXT - strlen($codec->canonical($data)) - 128);
        $this->assertSame(IssuedDocumentContent::MAX_PLAINTEXT - 128, strlen($codec->canonical($data)));
        $sealed = $codec->seal($codec->document($data));
        $this->assertGreaterThan(3 * 1024 * 1024, strlen($sealed['encrypted_snapshot']));
        $this->assertLessThanOrEqual(IssuedDocumentContent::MAX_CIPHERTEXT, strlen($sealed['encrypted_snapshot']));
        $nearCap = $sealed;
        $packet = (int) DB::selectOne('SELECT @@SESSION.max_allowed_packet AS bytes')->bytes;
        $this->assertGreaterThan(128 * 1024, $packet);
        // MEDIUMTEXT capacity and the codec cap do not guarantee the connection's transport budget.
        // Use a single bounded write; never raise global settings or append chunks to simulate issuance.
        $data['presentation']['logo'] = 'data:image/png;base64,';
        $plainBudget = min(IssuedDocumentContent::MAX_PLAINTEXT - 128, intdiv($packet - 64 * 1024, 2) - 512);
        $data['presentation']['logo'] .= str_repeat('A', $plainBudget - strlen($codec->canonical($data)));
        $sealed = $codec->seal($codec->document($data));
        $this->assertGreaterThan(65535, strlen($sealed['encrypted_snapshot']));
        $this->assertLessThanOrEqual($packet - 64 * 1024, strlen($sealed['encrypted_snapshot']));
        $column = DB::selectOne("SELECT DATA_TYPE AS kind FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='public_shares' AND COLUMN_NAME='encrypted_snapshot'");
        $this->assertSame('mediumtext', $column->kind);
        $originalMode = (string) DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        $modes = array_filter(explode(',', $originalMode));
        $modes[] = 'STRICT_ALL_TABLES';
        DB::statement('SET SESSION sql_mode = ?', [implode(',', array_unique($modes))]);
        $economics = $this->economicFingerprint();
        try {
            $this->assertStringContainsString('STRICT_ALL_TABLES', (string) DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode);
            DB::table('public_shares')->where('id', $issued['share']->id)->update($sealed + ['subject_revision' => $sealed['content_hash']]);
            $this->assertCount(0, DB::select('SHOW WARNINGS'));
            $stored = PublicShare::findOrFail($issued['share']->id);
            $storedLength = DB::table('public_shares')->where('id', $stored->id)->selectRaw('OCTET_LENGTH(encrypted_snapshot) AS bytes')->first()->bytes;
            $this->assertSame(strlen($sealed['encrypted_snapshot']), (int) $storedLength);
            $this->assertSame($sealed['encrypted_snapshot'], $stored->encrypted_snapshot);
            $opened = app(IssuedFinancialShares::class)->content($stored)->toArray();
            $this->assertSame($sealed['content_hash'], hash('sha256', $codec->canonical($opened)));
            foreach (['statement', 'company', 'customer'] as $scope) {
                $this->assertSame(hash('sha256', $codec->canonical($real[$scope])), hash('sha256', $codec->canonical($opened[$scope])));
            }
            $this->assertSame($economics, $this->economicFingerprint());
            fwrite(STDOUT, sprintf("\nC5 storage metrics: packet=%d; plaintext=%d; stored_ciphertext=%d; codec_near_cap_ciphertext=%d; MEDIUMTEXT; STRICT_ALL_TABLES.\n", $packet, $plainBudget, $storedLength, strlen($nearCap['encrypted_snapshot'])));
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$originalMode]);
        }
        if (strlen($nearCap['encrypted_snapshot']) + 64 * 1024 > $packet) {
            $shares = PublicShare::count();
            $audits = DB::table('audit_events')->where('event_key', 'share.issued')->count();
            $proxy = Mockery::mock($codec);
            $proxy->shouldReceive('seal')->once()->andReturnUsing(function (DocumentData $actual) use ($codec, $real, $nearCap): array {
                // The service built a real canonical fixture; only its sealed byte size is fault-injected.
                $this->assertSame(PublicShare::SUBJECT_CUSTOMER_STATEMENT, $actual->type);
                $this->assertSame(hash('sha256', $codec->canonical($real['statement'])), hash('sha256', $codec->canonical($actual->statement)));
                $this->assertSame(hash('sha256', $codec->canonical($real['customer'])), hash('sha256', $codec->canonical($actual->customer)));

                return $nearCap;
            });
            $this->app->instance(IssuedDocumentContent::class, $proxy);
            try {
                $this->issue(PublicShare::SUBJECT_CUSTOMER_STATEMENT, $invoice->customer_id, 'oversized-transport');
                $this->fail('Oversized database transport payload granted an active token.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('database', $exception->getMessage());
                $this->assertSame($shares, PublicShare::count());
                $this->assertSame($audits, DB::table('audit_events')->where('event_key', 'share.issued')->count());
                $this->assertSame($economics, $this->economicFingerprint());
            } finally {
                $this->app->instance(IssuedDocumentContent::class, $codec);
            }
        }
    }

    public function test_encrypted_backup_and_previous_key_recovery_restore_the_original_issued_statement_without_live_query(): void
    {
        $invoice = $this->invoice();
        $issued = $this->issue(PublicShare::SUBJECT_CUSTOMER_STATEMENT, $invoice->customer_id);
        $codec = app(IssuedDocumentContent::class);
        $original = app(IssuedFinancialShares::class)->content($issued['share'])->toArray();
        $copy = $issued['share']->only(['content_version', 'content_hash', 'encrypted_snapshot', 'subject_revision', 'encrypted_token', 'token_lookup_hash']);
        // A separately encrypted backup envelope; no key, token or financial DTO is written to disk/logs.
        $backupCipher = new Encrypter(random_bytes(32), 'AES-256-CBC');
        $backup = $backupCipher->encryptString($codec->canonical($copy));
        $this->assertStringNotContainsString($invoice->invoice_number, $backup);
        $this->assertStringNotContainsString($original['customer']['name'], $backup);
        $originalCrypt = Crypt::getFacadeRoot();
        $originalQuery = app(CustomerStatementQuery::class);
        $query = Mockery::mock(CustomerStatementQuery::class);
        $query->shouldNotReceive('execute');
        $query->shouldNotReceive('executeForShare');
        $this->app->instance(CustomerStatementQuery::class, $query);
        $economics = $this->economicFingerprint();
        try {
            DB::table('public_shares')->where('id', $issued['share']->id)->update(['encrypted_snapshot' => null]);
            $this->post($issued['url'], ['password' => 'statement-pass'])->assertNotFound();
            try {
                (new Encrypter(random_bytes(32), 'AES-256-CBC'))->decryptString($backup);
                $this->fail('An unrelated backup key decrypted the backup.');
            } catch (DecryptException) {
                $this->assertNull(PublicShare::findOrFail($issued['share']->id)->encrypted_snapshot);
            }
            $restored = json_decode($backupCipher->decryptString($backup), true, 16, JSON_THROW_ON_ERROR);
            $this->assertSame(hash('sha256', $codec->canonical($copy)), hash('sha256', $codec->canonical($restored)));
            DB::table('public_shares')->where('id', $issued['share']->id)->update($restored);
            $rotatedKey = random_bytes(32);
            Crypt::swap(new Encrypter($rotatedKey, 'AES-256-CBC')); // Historical key deliberately absent.
            $this->post($issued['url'], ['password' => 'statement-pass'])->assertNotFound();
            try {
                app(IssuedFinancialShares::class)->content($issued['share']);
                $this->fail('Missing historical key triggered a successful content read.');
            } catch (DecryptException) {
                $this->assertSame($copy['content_hash'], PublicShare::findOrFail($issued['share']->id)->content_hash);
            }
            $recoverable = new Encrypter($rotatedKey, 'AES-256-CBC');
            $recoverable->previousKeys([$originalCrypt->getKey()]);
            Crypt::swap($recoverable);
            $invoice->customer->update(['name_en' => 'Changed after backup']);
            $this->company->update(['name_en' => 'Changed after backup']);
            $opened = app(IssuedFinancialShares::class)->content($issued['share'])->toArray();
            $this->assertSame(hash('sha256', $codec->canonical($original)), hash('sha256', $codec->canonical($opened)));
            $this->assertSame($copy['content_hash'], hash('sha256', $codec->canonical($opened)));
            $this->assertSame($issued['raw_token'], Crypt::decryptString(PublicShare::findOrFail($issued['share']->id)->encrypted_token));
            $this->post($issued['url'], ['password' => 'statement-pass'])->assertRedirect();
            $this->assertSame(hash('sha256', $codec->canonical($original)), hash('sha256', $codec->canonical($this->get($issued['url'].'?format=json')->assertOk()->json())));
            $this->assertSame($economics, $this->economicFingerprint());
        } finally {
            Crypt::swap($originalCrypt);
            $this->app->instance(CustomerStatementQuery::class, $originalQuery);
        }
    }

    public function test_guest_pdf_limits_and_revocation_during_render_discard_prepared_bytes(): void
    {
        $invoice = $this->invoice();
        $issued = $this->issue(PublicShare::SUBJECT_SALES_INVOICE, $invoice->id);
        $this->post($issued['url'])->assertRedirect();
        $renderer = new class($issued['share']->id) extends PdfRendererService
        {
            public bool $guestUsed = false;

            public function __construct(private readonly int $shareId) {}

            public function renderDocument(DocumentData $data, ?string $qrUrl = null, bool $guest = false): string
            {
                $this->guestUsed = $guest;
                // Deterministic concurrent-revocation checkpoint, touching only grant metadata.
                DB::table('public_shares')->where('id', $this->shareId)->update(['is_active' => false, 'revoked_at' => now()]);

                return '%PDF-prepared-sensitive-'.$data->document['number'];
            }
        };
        $this->app->instance(PdfRendererService::class, $renderer);
        $this->get($issued['url'].'?format=pdf')->assertNotFound()->assertDontSee('%PDF-prepared-sensitive')->assertDontSee($invoice->invoice_number);
        $this->assertTrue($renderer->guestUsed);
        $this->assertSame(0, $issued['share']->fresh()->view_count);
        $data = app(DocumentDataBuilder::class)->build($invoice, forPdf: true)->toArray();
        $data['lines'] = array_fill(0, 101, $data['lines'][0]);
        $this->expectException(InvalidArgumentException::class);
        (new PdfRendererService)->renderDocument(app(IssuedDocumentContent::class)->document($data), guest: true);
    }

    public function test_application_url_controls_issued_links_and_token_change_invalidates_old_unlock(): void
    {
        config(['app.url' => 'https://documents.example']);
        $invoice = $this->invoice();
        $originalRequest = $this->app->make('request');
        $this->app->instance('request', Request::create('https://attacker.invalid/'));
        try {
            $issued = $this->issue(PublicShare::SUBJECT_SALES_INVOICE, $invoice->id);
            $this->assertSame('https://documents.example/share/'.$issued['raw_token'], $issued['url']);
            $this->assertSame($issued['url'], app(PublicShareService::class)->urlFor($issued['share'], $this->owner));
        } finally {
            $this->app->instance('request', $originalRequest);
        }
        $this->post($issued['url'])->assertRedirect();
        $this->get($issued['url'].'?format=json')->assertOk();
        $newToken = str_repeat('T', 40);
        $issued['share']->update(['token_lookup_hash' => hash('sha256', $newToken), 'encrypted_token' => Crypt::encryptString($newToken)]);
        $newUrl = app(ManagedPublicUrl::class)->make('share', $newToken);
        $this->get($issued['url'])->assertNotFound();
        $this->get($newUrl.'?format=json')->assertOk()->assertDontSee($invoice->invoice_number);
        $this->post($newUrl)->assertRedirect();
        $this->get($newUrl.'?format=json')->assertJsonPath('document.number', $invoice->invoice_number);
        foreach (['https://user@documents.example', 'https://documents.example?tracking=1', 'ftp://documents.example'] as $base) {
            config(['app.url' => $base]);
            try {
                app(ManagedPublicUrl::class)->make('share', $newToken);
                $this->fail('Malformed application URL was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    /** @return array<string,string> */
    private function economicFingerprint(): array
    {
        $result = [];
        foreach (['posting_batches', 'posting_lines', 'stock_movements', 'sales_invoices', 'sales_invoice_lines', 'customer_payments', 'customer_payment_allocations', 'document_sequences'] as $table) {
            $result[$table] = hash('sha256', DB::table($table)->where('company_id', $this->company->id)->orderBy('id')->get()->toJson());
        }

        return $result;
    }
}
