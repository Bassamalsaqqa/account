<?php

declare(strict_types=1);

namespace Tests\Support\AiV22;

use App\Actions\Sales\CreateMoneyAccountAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Application\AI\AiGovernance;
use App\Models\AiCompanyPolicy;
use App\Models\AiProviderConnection;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Support\Phase12\AssistantFixtures;

trait AccountingTurnFixtures
{
    use AssistantFixtures;

    protected SalesInvoice $octInvoice1;

    protected SalesInvoice $octInvoice2;

    protected SalesInvoice $sepInvoice;

    protected SalesInvoice $augInvoice;

    protected CustomerPayment $sepPayment;

    protected Product $lowStockProduct;

    protected AiProviderConnection $textConnection;

    protected AiCompanyPolicy $aiPolicy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:00'));

        $this->assistantFixtures();

        // Synthetic password hash only (never real password)
        $this->owner->update(['password' => Hash::make('synthetic-owner-secret')]);

        config([
            'ai_assistant.enabled' => true,
            'ai_assistant.questions.enabled' => true,
            'ai_assistant.turn_enabled' => true,
            'ai_assistant.provider_approved' => true,
            'ai_assistant.provider' => 'direct',
            'ai_assistant.max_input_tokens' => 6000,
            'ai_assistant.max_output_tokens' => 4000,
        ]);

        $governance = app(AiGovernance::class);
        $this->textConnection = $governance->provision($this->company, $this->owner, [
            'provider' => 'deepseek',
            'model' => 'deepseek-flash',
            'region' => 'global',
            'capability' => 'text_structured',
            'credential_source' => 'company_byok',
        ], 'synthetic-primary-key-never-real', 'synthetic-owner-secret');
        $this->textConnection->update(['verification_status' => 'verified']);

        $reportAllowlist = [
            'customers.balances',
            'sales.last-invoice',
            'sales.summary',
            'inventory.low-stock',
        ];

        $pricing = [
            $this->textConnection->public_id => [
                'reference' => 'https://example.test/synthetic-pricing',
                'checked_at' => '2026-10-11',
                'input_micros_per_million' => 1000,
                'output_micros_per_million' => 2000,
                'audio_micros_per_minute' => 6000,
            ],
        ];

        $this->aiPolicy = $governance->updatePolicy($this->company, $this->owner, [
            'enabled' => true,
            'personal_credentials_enabled' => false,
            'follow_up_enabled' => true,
            'workflow_mode' => 'seamless',
            'speech_method' => 'disabled',
            'settings' => [
                'allowed_connections' => [$this->textConnection->public_id],
                'text_connection' => $this->textConnection->public_id,
                'speech_connection' => null,
                'fallback' => ['enabled' => false, 'text_connection' => null, 'speech_connection' => null],
                'privacy' => [
                    'operator' => 'Synthetic fixture',
                    'retention' => 'No real dispatch',
                    'policy_url' => 'https://example.test/privacy',
                ],
                'pricing' => $pricing,
                'limits' => [
                    'request_micros' => 1000,
                    'daily_company_micros' => 100000,
                    'monthly_company_micros' => 1000000,
                    'daily_user_micros' => 100000,
                    'monthly_user_micros' => 1000000,
                    'daily_company_requests' => 100,
                    'daily_user_requests' => 25,
                ],
                'retention_days' => 30,
                'questions' => [
                    'enabled' => true,
                    'allowed_reports' => $reportAllowlist,
                ],
            ],
        ]);

        $governance->recordConsent(
            $this->company,
            $this->owner,
            $this->textConnection->public_id,
            $governance->consentFingerprint($this->aiPolicy, $this->textConnection, 'question'),
            'question'
        );

        $this->setupRealSyntheticPostedFixtures();

        Http::preventStrayRequests();
    }

    protected function setupRealSyntheticPostedFixtures(): void
    {
        // Customer Ahmed is created in AssistantFixtures:
        // $this->customer has name_ar => 'أحمد', name_en => 'Ahmed', code => 'C-AHMED'

        // Two months of exact ILS sales fixtures using canonical draft and post actions:
        // October 2026 (this month):
        // Invoice 1: 2026-10-05, 500.00 ILS (quantity 10 @ 50.00 ILS)
        $draftOct1 = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'issue_date' => '2026-10-05',
            'due_date' => null,
            'lines' => [
                [
                    'item_description' => 'Soap Cartons',
                    'quantity' => '10',
                    'unit_price' => '50.000000',
                ],
            ],
        ]);
        $this->octInvoice1 = app(PostSalesInvoiceAction::class)->execute($draftOct1, $this->owner);

        // Invoice 2: 2026-10-10, 300.00 ILS (quantity 6 @ 50.00 ILS) - Ahmed's latest invoice
        $draftOct2 = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'issue_date' => '2026-10-10',
            'due_date' => null,
            'lines' => [
                [
                    'item_description' => 'Cleaning Supplies',
                    'quantity' => '6',
                    'unit_price' => '50.000000',
                ],
            ],
        ]);
        $this->octInvoice2 = app(PostSalesInvoiceAction::class)->execute($draftOct2, $this->owner);

        // September 2026 (last month):
        // Invoice: 2026-09-15, 450.00 ILS (quantity 9 @ 50.00 ILS)
        $draftSep = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'issue_date' => '2026-09-15',
            'due_date' => null,
            'lines' => [
                [
                    'item_description' => 'Soap Pack',
                    'quantity' => '9',
                    'unit_price' => '50.000000',
                ],
            ],
        ]);
        $this->sepInvoice = app(PostSalesInvoiceAction::class)->execute($draftSep, $this->owner);

        // August 2026 (month before last month):
        // Invoice: 2026-08-20, 200.00 ILS (quantity 4 @ 50.00 ILS)
        $draftAug = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.000000',
            'issue_date' => '2026-08-20',
            'due_date' => null,
            'lines' => [
                [
                    'item_description' => 'August Maintenance',
                    'quantity' => '4',
                    'unit_price' => '50.000000',
                ],
            ],
        ]);
        $this->augInvoice = app(PostSalesInvoiceAction::class)->execute($draftAug, $this->owner);

        // Canonical payment: Ahmed pays the September invoice (450.00 ILS) via cash
        $ilsCash = app(CreateMoneyAccountAction::class)->execute($this->company, $this->owner, [
            'account_type' => 'cash',
            'name_ar' => 'صندوق شيكل',
            'name_en' => 'Cash ILS',
            'currency_code' => 'ILS',
        ]);

        $this->sepPayment = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $ilsCash->id,
            'payment_date' => '2026-09-20',
            'payment_method' => 'cash',
            'amount' => '450.000000',
            'exchange_rate' => '1.000000',
            'idempotency_key' => 'receipt-sep-ahmed-acceptance',
            'allocations' => [
                ['sales_invoice_id' => $this->sepInvoice->id, 'allocated_amount' => '450.000000'],
            ],
        ]);

        // Low stock product fixture: tracked product with minimum stock threshold but zero on hand
        $piece = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();
        $this->lowStockProduct = Product::create([
            'company_id' => $this->company->id,
            'sku' => 'LOW-STOCK-01',
            'name_ar' => 'صابون منخفض المخزون',
            'name_en' => 'Low Stock Soap Item',
            'base_unit_id' => $piece->id,
            'product_type' => 'stock',
            'track_stock' => true,
            'minimum_stock_base' => '10.000000',
            'default_sale_price_base' => '15.000000',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);
    }

    /**
     * @param  list<array{id: string, name: string, arguments: array<string, mixed>}>  $toolCalls
     * @return array<string, mixed>
     */
    protected function mockDeepSeekResponse(array $toolCalls): array
    {
        $formattedCalls = [];
        foreach ($toolCalls as $call) {
            if (($call['arguments']['filters'] ?? null) === []) {
                $call['arguments']['filters'] = (object) [];
            }
            $formattedCalls[] = [
                'id' => $call['id'],
                'type' => 'function',
                'function' => [
                    'name' => $call['name'],
                    'arguments' => json_encode($call['arguments'], JSON_THROW_ON_ERROR),
                ],
            ];
        }

        return [
            'model' => 'deepseek-flash',
            'choices' => [
                [
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => $formattedCalls,
                    ],
                ],
            ],
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
            ],
        ];
    }
}
