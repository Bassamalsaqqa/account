<?php

declare(strict_types=1);

namespace Tests\Feature\AiV22;

use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Application\AI\AiGovernance;
use App\Application\AI\AssistantReportCatalog;
use App\Application\AI\DeterministicTransactionParser;
use App\Livewire\AI\AssistantLauncher;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Services\Purchasing\VendorCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

/** Combined A1–A4 source gate with actual canonical history and mocked SDK responses. */
final class IntegratedReleaseCandidateTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    /** @return array<string, array{string, string, string, string, string}> */
    public static function languages(): array
    {
        return [
            'English' => ['en', 'sales this month', 'Please sell to Ahmed 5 carton SOAP-3L at 80 ILS',
                'Please buy from Supplier 5 carton SOAP-3L at 80 ILS', 'Ahmed'],
            'Palestinian Arabic text' => ['ar', 'ملخص المبيعات هذا الشهر', 'سجل بيعة لأحمد 5 carton SOAP-3L بسعر 80 ILS',
                'سجل شراء من Supplier عدد 5 carton SOAP-3L بسعر 80 ILS', 'أحمد'],
        ];
    }

    #[DataProvider('languages')]
    public function test_reports_and_both_unsaved_forms_preserve_history_credentials_and_company_policy(
        string $locale, string $question, string $salesText, string $purchaseText, string $customer,
    ): void {
        $this->finalAiFixtures();
        app()->setLocale($locale);
        Http::preventStrayRequests();
        config(['ai_assistant.turn_enabled' => true, 'ai_assistant.questions.enabled' => true]);
        $settings = $this->aiPolicy->settings;
        $settings['questions'] = ['enabled' => true, 'allowed_reports' => AssistantReportCatalog::KEYS];
        $governance = app(AiGovernance::class);
        $this->aiPolicy = $governance->updatePolicy($this->company, $this->owner,
            ['enabled' => true, 'personal_credentials_enabled' => false, 'follow_up_enabled' => true,
                'workflow_mode' => 'seamless', 'speech_method' => 'disabled', 'settings' => $settings], $this->aiPolicy->revision);
        $governance->recordConsent($this->company, $this->owner, $this->textConnection->public_id,
            $governance->consentFingerprint($this->aiPolicy, $this->textConnection, 'question'), 'question');
        $this->aiProduct->update(['product_type' => 'stock', 'track_stock' => true]);
        $this->aiCarton->update(['is_default_purchase' => true]);
        app(VendorCatalogService::class)->save($this->company, $this->owner, ['name_ar' => 'المورد', 'name_en' => 'Supplier']);
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id, 'currency_code' => 'ILS', 'exchange_rate' => '1',
            'issue_date' => now()->format('Y-m-d'),
            'lines' => [['item_description' => 'Synthetic history', 'quantity' => '1', 'unit_price' => '10']],
        ]);
        app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
        $before = $this->fingerprints();
        $credentialHash = hash('sha256', $this->textConnection->fresh()->secret_ciphertext);
        $policy = $this->aiPolicy->fresh()->toArray();

        $component = Livewire::test(AssistantLauncher::class)->call('showAssistant')
            ->set('text', $question)->call('prepare');
        $reports = $component->get('turnReports');
        self::assertSame('10.000000', $reports[0]['totals']['gross_sales_base']);
        Http::assertNothingSent();
        $component->call('resetQuestion');

        $workflows = [['sales', $salesText, $customer, InvoiceForm::class, 'unit_price'],
            ['purchase', $purchaseText, 'Supplier', PurchaseForm::class, 'unit_cost']];
        $sequence = Http::sequence();
        foreach ($workflows as [$intent, $text, $party, $form, $price]) {
            $proposal = app(DeterministicTransactionParser::class)->parse(
                ($intent === 'sales' ? 'sell to' : 'buy from').' "'.$party.'": 5 "carton" "SOAP-3L" at 80 ILS'
            );
            $proposal['evidence'] = array_map(fn ($path, $quote) => ['path' => $path, 'quote' => $quote],
                array_keys($proposal['evidence']), array_values($proposal['evidence']));
            $sequence->push(['choices' => [['message' => ['content' => json_encode($proposal, JSON_THROW_ON_ERROR)],
                'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 90]]);
        }
        Http::fake(['*' => $sequence]);
        foreach ($workflows as [$intent, $text, $party, $form, $price]) {
            $component->set('text', $text)->call('prepare');
            self::assertNull($component->get('error'), $intent.': '.json_encode($component->get('error')));
            self::assertSame([], $component->get('questions'), $intent.': '.json_encode($component->get('questions')));
            $component->assertRedirect();
            parse_str((string) parse_url($component->__get('effects')['redirect'], PHP_URL_QUERY), $query);
            Livewire::withQueryParams(['assistant' => $query['assistant']])->test($form)
                ->assertSet('lines.0.quantity', '5')->assertSet('lines.0.'.$price, '80');
            self::assertSame($before, $this->fingerprints());
            $component = Livewire::test(AssistantLauncher::class);
        }
        Http::assertSentCount(2);
        self::assertSame($credentialHash, hash('sha256', $this->textConnection->fresh()->secret_ciphertext));
        self::assertSame($policy, $this->aiPolicy->fresh()->toArray());
        self::assertSame($before, $this->fingerprints());
    }

    /** @return array<string, string> */
    private function fingerprints(): array
    {
        $result = [];
        foreach (['sales_invoices', 'sales_invoice_lines', 'purchases', 'purchase_lines', 'posting_batches', 'posting_lines',
            'stock_movements', 'customer_payments', 'vendor_payments', 'customers', 'vendors', 'products'] as $table) {
            $result[$table] = hash('sha256', DB::table($table)->orderBy('id')->get()->toJson());
        }

        return $result;
    }
}
