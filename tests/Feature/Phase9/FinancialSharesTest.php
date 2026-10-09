<?php

declare(strict_types=1);

namespace Tests\Feature\Phase9;

use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\VoidSalesInvoiceAction;
use App\Livewire\FinancialShareManager;
use App\Models\Customer;
use App\Models\PublicShare;
use App\Models\SalesInvoice;
use App\Services\Sales\DocumentDataBuilder;
use App\Services\Sales\IssuedDocumentContent;
use App\Services\Sales\PublicShareService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Feature\Phase5E\Phase5ETestCase;

class FinancialSharesTest extends Phase5ETestCase
{
    private function invoice(): SalesInvoice
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'عميل سري', 'name_en' => 'Secret customer', 'active' => true, 'created_by' => $this->owner->id]);
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, ['customer_id' => $customer->id, 'currency_code' => 'USD', 'exchange_rate' => '3.5', 'issue_date' => '2026-10-01', 'lines' => [['item_description' => 'Private line', 'quantity' => '2', 'unit_price' => '80']]]);

        return app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
    }

    /** @return array{share:PublicShare,raw_token:string,url:string} */
    private function share(SalesInvoice $invoice, ?string $password = null, ?string $key = null): array
    {
        return app(PublicShareService::class)->createShare($this->company, $this->owner, PublicShare::SUBJECT_SALES_INVOICE, $invoice->id, null, $password, $key);
    }

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('financial-ip:'.hash('sha256', '127.0.0.1'));
    }

    public function test_share_manager_formats_money_validates_dates_and_handles_issuance_refusal(): void
    {
        $invoice = $this->invoice();
        Livewire::test(FinancialShareManager::class, ['subjectType' => PublicShare::SUBJECT_SALES_INVOICE, 'subjectId' => $invoice->id])
            ->assertSee('160.00 USD')->assertDontSee('160.000000 USD');
        Livewire::test(FinancialShareManager::class, ['subjectType' => PublicShare::SUBJECT_CUSTOMER_STATEMENT, 'subjectId' => $invoice->customer_id])
            ->set('from', '2026-02-30')->assertSee(__('sharing.invalid_dates'))
            ->set('from', '2026-10-02')->set('to', '2026-10-01')->assertSee(__('sharing.invalid_dates'))
            ->set('to', '2026-10-09')->assertDontSee(__('sharing.invalid_dates'));
        $service = \Mockery::mock(PublicShareService::class);
        $service->shouldReceive('createShare')->once()->andThrow(new InvalidArgumentException('Capacity exceeded.'));
        $this->app->instance(PublicShareService::class, $service);
        Livewire::test(FinancialShareManager::class, ['subjectType' => PublicShare::SUBJECT_SALES_INVOICE, 'subjectId' => $invoice->id])
            ->call('create')->assertHasErrors('share')->assertSet('url', null)
            ->assertSee(__('sharing.issuance_unavailable'));
        $this->assertSame(0, PublicShare::count());
    }

    public function test_first_get_head_and_direct_formats_disclose_no_financial_identity(): void
    {
        $invoice = $this->invoice();
        $share = $this->share($invoice);
        auth()->logout();
        foreach (['', '?format=pdf', '?format=json', '?format=print', '?password=anything'] as $suffix) {
            $response = $this->get($share['url'].$suffix)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer');
            foreach ([$invoice->invoice_number, 'Secret customer', 'عميل سري', '160.00', 'Private line', 'application/pdf', 'fonts.googleapis', '/storage/'] as $secret) {
                $response->assertDontSee($secret, false);
            }
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->head($share['url'])->assertOk()->assertContent('');
        $this->assertSame(0, $share['share']->fresh()->view_count);
        $this->post($share['url'])->assertRedirect($share['url']);
        $this->get($share['url'].'?format=json')->assertOk()->assertJsonPath('document.number', $invoice->invoice_number);
        $this->get($share['url'].'?format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get($share['url'].'?format=print')->assertOk()->assertSee($invoice->invoice_number);
    }

    public function test_csrf_is_required_for_deliberate_unlock(): void
    {
        $share = $this->share($this->invoice());
        auth()->logout();
        $this->app->instance('env', 'phase9-csrf-test');
        try {
            $this->post($share['url'])->assertStatus(419);
            $this->withSession(['_token' => 'phase9-csrf-token'])->post($share['url'], ['_token' => 'phase9-csrf-token'])->assertRedirect();
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_unlock_is_share_bound_password_bound_expiring_and_revocable(): void
    {
        $invoice = $this->invoice();
        $first = $this->share($invoice, 'secret-pass');
        $second = $this->share($invoice);
        $this->get($first['url'].'?password=secret-pass')->assertDontSee($invoice->invoice_number);
        $this->post($first['url'], ['password' => 'wrong-pass'])->assertNotFound();
        $this->post($first['url'], ['password' => 'secret-pass'])->assertRedirect();
        $this->get($second['url'])->assertDontSee($invoice->invoice_number);
        $this->get($first['url'])->assertSee($invoice->invoice_number);
        $first['share']->update(['password_hash' => Hash::make('different-pass')]);
        $this->get($first['url'])->assertDontSee($invoice->invoice_number);
        $this->post($first['url'], ['password' => 'different-pass'])->assertRedirect();
        $this->travel(16)->minutes();
        $this->get($first['url'])->assertDontSee($invoice->invoice_number);
        $this->travelBack();
        app(PublicShareService::class)->revokeShare($first['share'], $this->owner);
        foreach (['', '?format=pdf', '?format=json', '?format=print'] as $suffix) {
            $this->get($first['url'].$suffix)->assertNotFound()->assertDontSee($invoice->invoice_number);
        }
    }

    public function test_source_and_company_retirement_deny_already_unlocked_requests(): void
    {
        $invoice = $this->invoice();
        $share = $this->share($invoice);
        $this->post($share['url'])->assertRedirect();
        DB::table('companies')->where('id', $this->company->id)->update(['status' => 'inactive']);
        $this->get($share['url'].'?format=json')->assertNotFound();
        DB::table('companies')->where('id', $this->company->id)->update(['status' => 'active']);
        $this->activate($this->owner);
        app(VoidSalesInvoiceAction::class)->execute($invoice, $this->owner, 'Retired');
        $this->get($share['url'].'?format=pdf')->assertNotFound();
    }

    public function test_create_recover_revoke_require_source_intersections_and_fresh_membership(): void
    {
        $invoice = $this->invoice();
        $share = $this->share($invoice);
        $actor = $this->customActor(['sales.document.share']);
        $this->activate($actor);
        foreach (['create', 'recover', 'revoke'] as $operation) {
            try {
                match ($operation) {
                    'create' => app(PublicShareService::class)->createShare($this->company, $actor, PublicShare::SUBJECT_SALES_INVOICE, $invoice->id),
                    'recover' => app(PublicShareService::class)->urlFor($share['share'], $actor),
                    'revoke' => app(PublicShareService::class)->revokeShare($share['share'], $actor),
                };
                $this->fail('Missing source-view authority was accepted.');
            } catch (AuthorizationException) {
                $this->assertTrue($share['share']->fresh()->is_active);
            }
        }
        $this->activate($this->owner);
        $retry = $this->share($invoice, null, 'same-intent');
        $this->assertSame($retry['raw_token'], $this->share($invoice, null, 'same-intent')['raw_token']);
        $this->expectException(InvalidArgumentException::class);
        $this->share($invoice, 'different-password', 'same-intent');
    }

    public function test_statement_is_encrypted_fixed_bounded_and_never_live_recomputed_after_damage(): void
    {
        $invoice = $this->invoice();
        $service = app(PublicShareService::class);
        try {
            $service->createShare($this->company, $this->owner, PublicShare::SUBJECT_CUSTOMER_STATEMENT, $invoice->customer_id);
            $this->fail('Unprotected statement was issued.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, PublicShare::count());
        }
        $result = $service->createShare($this->company, $this->owner, PublicShare::SUBJECT_CUSTOMER_STATEMENT, $invoice->customer_id, null, 'statement-secret', 'statement-key', ['from' => '2026-10-01', 'to' => '2026-10-09', 'locale' => 'en']);
        $share = $result['share'];
        $this->assertStringNotContainsString('Secret customer', $share->encrypted_snapshot);
        $this->assertSame('mediumtext', DB::selectOne("SELECT DATA_TYPE AS kind FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='public_shares' AND COLUMN_NAME='encrypted_snapshot'")->kind);
        $this->assertNotNull($share->expires_at);
        $this->post($result['url'], ['password' => 'statement-secret'])->assertRedirect();
        $original = $this->get($result['url'].'?format=json')->assertOk()->json();
        $invoice->customer->update(['name_en' => 'RENAMED CUSTOMER']);
        $this->company->update(['name_en' => 'RENAMED COMPANY']);
        $retry = $service->createShare($this->company, $this->owner, PublicShare::SUBJECT_CUSTOMER_STATEMENT, $invoice->customer_id, null, 'statement-secret', 'statement-key', ['from' => '2026-10-01', 'to' => '2026-10-09', 'locale' => 'en']);
        $this->assertSame($result['raw_token'], $retry['raw_token']);
        $this->assertSame($original, $this->get($result['url'].'?format=json')->assertOk()->json());
        $originalHash = $share->content_hash;
        $share->update(['content_hash' => str_repeat('0', 64)]);
        $this->get($result['url'].'?format=json')->assertDontSee('RENAMED')->assertDontSee($invoice->invoice_number);
        $this->post($result['url'], ['password' => 'statement-secret'])->assertNotFound();
        $share->update(['content_hash' => $originalHash, 'encrypted_snapshot' => 'broken']);
        $this->get($result['url'].'?format=pdf')->assertNotFound();
    }

    public function test_snapshot_integrity_and_previous_key_rotation_fail_closed(): void
    {
        $original = Crypt::getFacadeRoot();
        $data = app(DocumentDataBuilder::class)->build($this->invoice(), forPdf: true);
        $sealed = app(IssuedDocumentContent::class)->seal($data);
        $new = new Encrypter(random_bytes(32), 'AES-256-CBC');
        $new->previousKeys([$original->getKey()]);
        Crypt::swap($new);
        try {
            $this->assertSame(app(IssuedDocumentContent::class)->canonical($data->toArray()), app(IssuedDocumentContent::class)->canonical(app(IssuedDocumentContent::class)->open($sealed['encrypted_snapshot'], $sealed['content_hash'], 1)->toArray()));
            Crypt::swap(new Encrypter(random_bytes(32), 'AES-256-CBC'));
            $this->expectException(DecryptException::class);
            app(IssuedDocumentContent::class)->open($sealed['encrypted_snapshot'], $sealed['content_hash'], 1);
        } finally {
            Crypt::swap($original);
        }
    }

    public function test_invalid_tokens_and_password_attempts_are_generic_and_bounded(): void
    {
        foreach (['short', str_repeat('a', 5000), str_repeat('x', 40)] as $token) {
            $this->get('/share/'.$token)->assertNotFound()->assertDontSee('SQLSTATE');
        }
        $share = $this->share($this->invoice(), 'bounded-password');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post($share['url'], ['password' => 'wrong-password'])->assertNotFound();
        }
        $this->post($share['url'], ['password' => 'bounded-password'])->assertStatus(429);
    }

    public function test_legacy_grant_keeps_direct_disclosure_but_requires_current_validity(): void
    {
        $invoice = $this->invoice();
        $token = str_repeat('L', 40);
        $share = PublicShare::create(['company_id' => $this->company->id, 'subject_type' => PublicShare::SUBJECT_SALES_INVOICE, 'subject_id' => $invoice->id, 'token_lookup_hash' => hash('sha256', $token), 'encrypted_token' => Crypt::encryptString($token), 'is_active' => true]);
        $this->get('/share/'.$token)->assertOk()->assertSee($invoice->invoice_number)->assertDontSee('fonts.googleapis');
        $share->update(['is_active' => false]);
        $this->get('/share/'.$token)->assertDontSee($invoice->invoice_number);
    }
}
