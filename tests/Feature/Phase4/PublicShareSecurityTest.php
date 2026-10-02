<?php

declare(strict_types=1);

namespace Tests\Feature\Phase4;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PublicShare;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Sales\PublicShareService;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use Tests\TestCase;

class PublicShareSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Customer $customer;

    protected PublicShareService $shareService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $creator = app(CreateCompanyAction::class);

        $this->company = $creator->execute($this->user, [
            'name_ar' => 'شركة الروابط العامة',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->user);
        $this->actingAs($this->user);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل الروابط الآمنة',
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->shareService = app(PublicShareService::class);
    }

    private function createAndPostInvoice(): SalesInvoice
    {
        $draftAction = app(CreateSalesInvoiceDraftAction::class);
        $postAction = app(PostSalesInvoiceAction::class);

        $invoice = $draftAction->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => Carbon::now()->toDateString(),
            'due_date' => Carbon::now()->addDays(30)->toDateString(),
            'lines' => [
                [
                    'product_id' => null,
                    'item_description' => 'استشارة تقنية سرية',
                    'quantity' => '2.000000',
                    'unit_price' => '150.000000',
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ]);

        return $postAction->execute($invoice, $this->user);
    }

    public function test_draft_invoice_or_draft_quotation_cannot_be_shared(): void
    {
        $draftAction = app(CreateSalesInvoiceDraftAction::class);
        $invoice = $draftAction->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => Carbon::now()->toDateString(),
            'due_date' => Carbon::now()->addDays(30)->toDateString(),
            'lines' => [
                [
                    'product_id' => null,
                    'item_description' => 'مسودة فاتورة',
                    'quantity' => '1.000000',
                    'unit_price' => '100.000000',
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only posted sales invoices can be publicly shared');

        $this->shareService->createShare(
            $this->company,
            $this->user,
            PublicShare::SUBJECT_SALES_INVOICE,
            $invoice->id
        );
    }

    public function test_token_is_hashed_and_encrypted_at_rest(): void
    {
        $invoice = $this->createAndPostInvoice();

        $result = $this->shareService->createShare(
            $this->company,
            $this->user,
            PublicShare::SUBJECT_SALES_INVOICE,
            $invoice->id
        );

        $rawToken = $result['raw_token'];
        /** @var PublicShare $share */
        $share = $result['share'];

        // Token in DB is hashed with sha256
        $this->assertSame(hash('sha256', $rawToken), $share->token_lookup_hash);
        $this->assertNotSame($rawToken, $share->token_lookup_hash);

        // Encrypted token can be decrypted back to raw token
        $this->assertSame($rawToken, Crypt::decryptString($share->encrypted_token));

        // Raw token appears in public URL
        $this->assertStringContainsString($rawToken, $result['url']);
    }

    public function test_password_protected_share_requires_correct_password(): void
    {
        $invoice = $this->createAndPostInvoice();

        $result = $this->shareService->createShare(
            $this->company,
            $this->user,
            PublicShare::SUBJECT_SALES_INVOICE,
            $invoice->id,
            password: 'secret-password-123'
        );

        $rawToken = $result['raw_token'];

        // Resolving without password fails
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or missing password');
        $this->shareService->resolveShare($rawToken, null);
    }

    public function test_password_protected_share_succeeds_with_valid_password(): void
    {
        $invoice = $this->createAndPostInvoice();

        $result = $this->shareService->createShare(
            $this->company,
            $this->user,
            PublicShare::SUBJECT_SALES_INVOICE,
            $invoice->id,
            password: 'secret-password-123'
        );

        $rawToken = $result['raw_token'];

        $resolved = $this->shareService->resolveShare($rawToken, 'secret-password-123');
        $this->assertSame($result['share']->id, $resolved->id);
        $this->assertSame(1, $resolved->view_count);
    }

    public function test_expired_share_fails_resolution(): void
    {
        $invoice = $this->createAndPostInvoice();

        // Expired yesterday
        $result = $this->shareService->createShare(
            $this->company,
            $this->user,
            PublicShare::SUBJECT_SALES_INVOICE,
            $invoice->id,
            expiresAt: Carbon::yesterday()
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The requested document link has expired');
        $this->shareService->resolveShare($result['raw_token']);
    }

    public function test_revocation_is_immediate(): void
    {
        $invoice = $this->createAndPostInvoice();

        $result = $this->shareService->createShare(
            $this->company,
            $this->user,
            PublicShare::SUBJECT_SALES_INVOICE,
            $invoice->id
        );

        $share = $result['share'];

        // Revoke the share
        $this->shareService->revokeShare($share, $this->user);

        $share->refresh();
        $this->assertFalse($share->is_active);
        $this->assertNotNull($share->revoked_at);
        $this->assertSame($this->user->id, $share->revoked_by);

        // Resolving revoked share fails
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The requested document link is invalid or has been revoked');
        $this->shareService->resolveShare($result['raw_token']);
    }

    public function test_whitelisted_data_strictly_excludes_cost_and_profit_fields(): void
    {
        $invoice = $this->createAndPostInvoice();

        $result = $this->shareService->createShare(
            $this->company,
            $this->user,
            PublicShare::SUBJECT_SALES_INVOICE,
            $invoice->id
        );

        $data = $this->shareService->buildWhitelistedData($result['share']);

        // Check document header
        $this->assertSame('sales_invoice', $data['type']);
        $this->assertSame($invoice->invoice_number, $data['document']['number']);
        $this->assertSame('300.000000', $data['document']['grand_total']);

        // Check line items
        $this->assertCount(1, $data['lines']);
        $lineData = $data['lines'][0];

        $this->assertSame('استشارة تقنية سرية', $lineData['item_description']);
        $this->assertSame('150.000000', $lineData['unit_price']);
        $this->assertSame('300.000000', $lineData['total']);

        // CRITICAL: Ensure NO cost, COGS, margin, or internal fields exist anywhere in the payload
        $json = json_encode($data);
        $this->assertStringNotContainsString('cogs', strtolower($json));
        $this->assertStringNotContainsString('cost', strtolower($json));
        $this->assertStringNotContainsString('margin', strtolower($json));
        $this->assertStringNotContainsString('profit', strtolower($json));
        $this->assertStringNotContainsString('posting_batch', strtolower($json));
    }
}
