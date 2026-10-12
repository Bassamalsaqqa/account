<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\DeterministicTransactionParser;
use App\Application\AI\TenantSpeechCandidates;
use App\Application\AI\TransactionProposalResolver;
use App\Application\AI\TransactionProposalSchema;
use App\Models\AiCompanyPolicy;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\PostingBatch;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\ProductCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

class TenantSpeechCandidatesTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        Http::preventStrayRequests();
    }

    public function test_exact_sku_and_barcode_take_precedence_over_fuzzy_candidates(): void
    {
        // Product A has exact SKU 'SOAP-PRECISE'
        $productA = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'صابون دقيق',
            'name_en' => 'Precise Soap',
            'sku' => 'SOAP-PRECISE',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'base_unit_id' => $this->aiProduct->base_unit_id,
            'default_sale_price_base' => '10.000000',
        ], $this->owner->id);

        // Product B has a nearby name that would match via substring/fuzzy
        $productB = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'صابون دقيق إضافي',
            'name_en' => 'SOAP-PRECISE-EXTRA',
            'sku' => 'SOAP-EXTRA',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'base_unit_id' => $this->aiProduct->base_unit_id,
            'default_sale_price_base' => '12.000000',
        ], $this->owner->id);

        // Spoken proposal specifically mentions 'SOAP-PRECISE'
        $text = 'sell to "Ahmed": 2 "piece" "SOAP-PRECISE" at 10 ILS';
        $result = $this->resolve($text);

        // Exact match Product A must be resolved without ambiguity or questions
        $this->assertSame($productA->id, $result['payload']['lines'][0]['product_id']);
        $this->assertSame([], $result['questions']);

        // Barcode exact match with barcode unit truth
        $cartonUnit = Unit::where('company_id', $this->company->id)->where('code', 'carton')->firstOrFail();
        $productCarton = ProductUnit::where('company_id', $this->company->id)
            ->where('product_id', $productA->id)
            ->where('unit_id', $cartonUnit->id)
            ->first();
        if ($productCarton === null) {
            $productCarton = ProductUnit::create([
                'company_id' => $this->company->id,
                'product_id' => $productA->id,
                'unit_id' => $cartonUnit->id,
                'conversion_to_base' => '10.000000',
                'default_sale_price_base' => '90.000000',
                'active' => true,
            ]);
        }
        app(ProductCatalogService::class)->addBarcode($productA, '8855221144', $productCarton->unit_id, true);

        // Barcode without explicit unit must select the barcode's unit (carton)
        $proposalWithBarcode = [
            'version' => 2,
            'intent' => 'sales',
            'party' => 'Ahmed',
            'currency' => 'ILS',
            'warehouse' => null,
            'exchange_rate' => null,
            'date' => null,
            'due_date' => null,
            'vendor_invoice_number' => null,
            'notes' => null,
            'lines' => [
                [
                    'product' => '8855221144',
                    'unit' => null,
                    'quantity' => '1',
                    'unit_price' => '90',
                    'unit_cost' => null,
                    'discount_type' => null,
                    'discount_value' => null,
                    'tax' => null,
                    'lots' => [],
                ],
            ],
            'evidence' => [
                'party' => 'Ahmed',
                'currency' => 'ILS',
                'lines.0.product' => '8855221144',
                'lines.0.quantity' => '1',
                'lines.0.unit_price' => '90',
            ],
        ];

        $barcodeResult = app(TransactionProposalResolver::class)->resolve(
            $this->company,
            $this->owner,
            $proposalWithBarcode
        );

        $this->assertSame($productA->id, $barcodeResult['payload']['lines'][0]['product_id']);
        $this->assertSame($productCarton->id, $barcodeResult['payload']['lines'][0]['product_unit_id']);
        $this->assertSame([], $barcodeResult['questions']);
    }

    public function test_arabic_normalized_collisions_produce_ambiguity_requiring_explicit_choice(): void
    {
        $cust1 = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'أحمد التاجر',
            'name_en' => 'Ahmed Trader A',
            'code' => 'C-TRADER-1',
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);

        $cust2 = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'احمد التاجر',
            'name_en' => 'Ahmed Trader B',
            'code' => 'C-TRADER-2',
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);

        // Spoken with diacritics and tatweel: 'احـمـدَ التاجـر'
        $spoken = 'احـمـدَ التاجـر';
        $text = 'بيع إلى "'.$spoken.'": 5 "كرتونة" "SOAP-3L" بسعر 80 ILS';
        $proposal = $this->proposal($text);

        $first = app(TransactionProposalResolver::class)->resolve($this->company, $this->owner, $proposal);

        // Ambiguity collision: customer_id must not be guessed
        $this->assertNull($first['payload']['customer_id']);
        $this->assertCount(1, $first['questions']);
        $this->assertSame('party', $first['questions'][0]['path']);
        $candidates = $first['questions'][0]['candidates'];
        $this->assertCount(2, $candidates);
        $candidateIds = array_column($candidates, 'value');
        $this->assertEqualsCanonicalizing([$cust1->id, $cust2->id], $candidateIds);

        // Explicit choice scoped to candidate IDs resolves
        $chosen = app(TransactionProposalResolver::class)->resolve(
            $this->company,
            $this->owner,
            $proposal,
            ['party' => (int) $cust2->id]
        );

        $this->assertSame($cust2->id, $chosen['payload']['customer_id']);
        $this->assertSame('resolved_choice', $chosen['origins']['customer_id']);
        $this->assertSame([], $chosen['questions']);
    }

    public function test_english_minor_typo_requires_choice_and_does_not_silently_auto_select(): void
    {
        // Only one customer in this tenant: 'Ahmed'
        // User speaks minor typo: 'Ahmd' (edit distance 1)
        $text = 'sell to "Ahmd": 5 "carton" "SOAP-3L" at 80 ILS';
        $proposal = $this->proposal($text);

        $result = app(TransactionProposalResolver::class)->resolve($this->company, $this->owner, $proposal);

        // Must NOT silently auto-select even though only one fuzzy match exists
        $this->assertNull($result['payload']['customer_id']);
        $this->assertCount(1, $result['questions']);
        $this->assertSame('party', $result['questions'][0]['path']);
        $this->assertCount(1, $result['questions'][0]['candidates']);
        $this->assertSame($this->customer->id, $result['questions'][0]['candidates'][0]['value']);

        // Explicit user confirmation resolves
        $confirmed = app(TransactionProposalResolver::class)->resolve(
            $this->company,
            $this->owner,
            $proposal,
            ['party' => (int) $this->customer->id]
        );

        $this->assertSame($this->customer->id, $confirmed['payload']['customer_id']);
        $this->assertSame([], $confirmed['questions']);
    }

    public function test_candidates_capped_at_five_and_refusal_when_exceeding_finite_scan_cap(): void
    {
        // Part 1: Cap at 5 candidates when multiple similar matches exist
        for ($i = 1; $i <= 7; $i++) {
            app(ProductCatalogService::class)->createProduct($this->company, [
                'name_ar' => 'صابون معطر '.$i,
                'name_en' => 'Scented Soap '.$i,
                'sku' => 'SCENT-'.$i,
                'product_type' => Product::TYPE_STOCK,
                'track_stock' => true,
                'base_unit_id' => $this->aiProduct->base_unit_id,
                'default_sale_price_base' => '3.000000',
            ], $this->owner->id);
        }

        $text = 'sell to "Ahmed": 1 "piece" "Scented Soap" at 3 ILS';
        $result = $this->resolve($text);

        $this->assertNull($result['payload']['lines'][0]['product_id']);
        $productQuestion = collect($result['questions'])->firstWhere('path', 'lines.0.product');
        $this->assertNotNull($productQuestion);
        $this->assertCount(TenantSpeechCandidates::MAX_CANDIDATES, $productQuestion['candidates']);
        $this->assertSame(5, count($productQuestion['candidates']));

        // Part 2: Finite scan cap refusal
        // Create products up to exceeding FINITE_SCAN_CAP (100)
        $currentCount = Product::where('company_id', $this->company->id)->count();
        $target = TenantSpeechCandidates::FINITE_SCAN_CAP + 5;
        $needed = $target - $currentCount;

        $lastProduct = null;
        for ($i = 1; $i <= $needed; $i++) {
            $lastProduct = Product::create([
                'company_id' => $this->company->id,
                'name_ar' => 'منتج كتالوج '.$i,
                'name_en' => 'Catalog Product '.$i,
                'sku' => 'CATALOG-SKU-'.$i,
                'product_type' => Product::TYPE_STOCK,
                'track_stock' => true,
                'base_unit_id' => $this->aiProduct->base_unit_id,
                'default_sale_price_base' => '4.000000',
                'active' => true,
                'created_by' => $this->owner->id,
            ]);
            ProductUnit::create([
                'company_id' => $this->company->id,
                'product_id' => $lastProduct->id,
                'unit_id' => $this->aiProduct->base_unit_id,
                'conversion_to_base' => '1.000000',
                'is_base' => true,
                'is_default_sale' => true,
                'active' => true,
            ]);
        }

        $this->assertGreaterThan(TenantSpeechCandidates::FINITE_SCAN_CAP, Product::where('company_id', $this->company->id)->count());

        // Fuzzy match when catalog exceeds scan cap must refuse fuzzy scanning (empty candidates)
        $fuzzyProposalText = 'sell to "Ahmed": 1 "piece" "Catalog Prduct" at 4 ILS'; // typo in 'Product'
        $fuzzyResult = $this->resolve($fuzzyProposalText);
        $this->assertNull($fuzzyResult['payload']['lines'][0]['product_id']);
        $fuzzyQuestion = collect($fuzzyResult['questions'])->firstWhere('path', 'lines.0.product');
        $this->assertNotNull($fuzzyQuestion);
        $this->assertSame([], $fuzzyQuestion['candidates']);

        // BUT exact match remains available beyond fuzzy scan cap!
        $exactProposalText = 'sell to "Ahmed": 1 "piece" "'.$lastProduct->sku.'" at 4 ILS';
        $exactResult = $this->resolve($exactProposalText);
        $this->assertSame($lastProduct->id, $exactResult['payload']['lines'][0]['product_id']);
    }

    public function test_foreign_company_and_inactive_records_excluded_including_explicit_choice(): void
    {
        // Foreign company setup
        $foreignOwner = User::factory()->create();
        app(CompanyContext::class)->clear();
        $foreignCompany = app(CreateCompanyAction::class)->execute($foreignOwner, [
            'name_ar' => 'شركة أجنبية',
            'name_en' => 'Foreign Corp',
            'base_currency_code' => 'ILS',
        ]);
        app(CompanyContext::class)->setCompany($foreignCompany, $foreignOwner);
        $foreignCustomer = Customer::create([
            'company_id' => $foreignCompany->id,
            'name_ar' => 'عميل أجنبي',
            'name_en' => 'Secret Customer',
            'code' => 'SECRET-C',
            'status' => 'active',
            'created_by' => $foreignOwner->id,
        ]);

        // Active context back to this->company
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);

        // Inactive customer in same company
        $inactiveCustomer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل معطل',
            'name_en' => 'Inactive Customer',
            'code' => 'INACTIVE-C',
            'status' => 'inactive',
            'created_by' => $this->owner->id,
        ]);

        // Proposal looking for 'Customer'
        $text = 'sell to "Customer": 5 "carton" "SOAP-3L" at 80 ILS';
        $proposal = $this->proposal($text);

        // Neither foreign nor inactive customer can appear in candidates
        $result = app(TransactionProposalResolver::class)->resolve($this->company, $this->owner, $proposal);
        $candidates = $result['questions'][0]['candidates'] ?? [];
        $candidateIds = array_column($candidates, 'value');
        $this->assertNotContains($foreignCustomer->id, $candidateIds);
        $this->assertNotContains($inactiveCustomer->id, $candidateIds);

        // Even with explicit choice for foreign customer, it must be rejected
        $foreignChoiceResult = app(TransactionProposalResolver::class)->resolve(
            $this->company,
            $this->owner,
            $proposal,
            ['party' => (int) $foreignCustomer->id]
        );
        $this->assertNull($foreignChoiceResult['payload']['customer_id']);

        // Explicit choice for inactive customer must also be rejected
        $inactiveChoiceResult = app(TransactionProposalResolver::class)->resolve(
            $this->company,
            $this->owner,
            $proposal,
            ['party' => (int) $inactiveCustomer->id]
        );
        $this->assertNull($inactiveChoiceResult['payload']['customer_id']);
    }

    public function test_same_person_admin_in_company_a_and_viewer_in_company_b_is_gated_by_permissions(): void
    {
        // Second company
        $otherOwner = User::factory()->create();
        app(CompanyContext::class)->clear();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherOwner, [
            'name_ar' => 'شركة ثانية',
            'name_en' => 'Second Company',
            'base_currency_code' => 'ILS',
        ]);
        app(CompanyContext::class)->setCompany($otherCompany, $otherOwner);
        AiCompanyPolicy::create([
            'company_id' => $otherCompany->id,
            'enabled' => true,
            'workflow_mode' => 'seamless',
            'speech_method' => 'disabled',
            'settings' => ['allowed_intents' => ['sales', 'purchase']],
        ]);

        // Add this->owner as Viewer in otherCompany (no purchasing cost permission)
        CompanyUser::create([
            'company_id' => $otherCompany->id,
            'user_id' => $this->owner->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        $viewerRole = Role::create([
            'company_id' => $otherCompany->id,
            'name' => 'Restricted Viewer',
            'guard_name' => 'web',
        ]);
        $viewerRole->givePermissionTo(['ai.assistant.use', 'ai.purchases.use', 'purchasing.purchase.create']);
        // Missing 'purchasing.cost.view' permission!
        setPermissionsTeamId($otherCompany->id);
        $this->owner->assignRole($viewerRole);

        // Same user can resolve in Company A (owner)
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);
        $saleResult = $this->resolve('sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS');
        $this->assertSame($this->customer->id, $saleResult['payload']['customer_id']);

        // But in otherCompany, purchase proposal resolution throws AuthorizationException before any disclosure
        app(CompanyContext::class)->setCompany($otherCompany, $this->owner);
        $this->expectException(AuthorizationException::class);
        app(TransactionProposalResolver::class)->resolve(
            $otherCompany,
            $this->owner,
            [
                'version' => 2,
                'intent' => 'purchase',
                'party' => 'Vendor',
                'currency' => 'ILS',
                'warehouse' => null,
                'exchange_rate' => null,
                'date' => null,
                'due_date' => null,
                'vendor_invoice_number' => null,
                'notes' => null,
                'lines' => [],
                'evidence' => [],
            ]
        );
    }

    public function test_piece_carton_spoken_variants_map_against_product_active_units_preserving_prices(): void
    {
        // Spoken carton variant 'كرتون' (without taa marbuta) -> resolves carton price 80 ILS
        $resCarton1 = $this->resolve('sell to "Ahmed": 2 "كرتون" "SOAP-3L" at 80 ILS');
        $this->assertSame($this->aiCarton->id, $resCarton1['payload']['lines'][0]['product_unit_id']);
        $this->assertSame('80', $resCarton1['payload']['lines'][0]['unit_price']);
        $this->assertSame([], $resCarton1['questions']);

        // Spoken carton variant 'صندوق' -> resolves carton
        $resCarton2 = $this->resolve('sell to "Ahmed": 2 "صندوق" "SOAP-3L" at 80 ILS');
        $this->assertSame($this->aiCarton->id, $resCarton2['payload']['lines'][0]['product_unit_id']);

        // Spoken carton variant 'باكيت' -> resolves carton
        $resCarton3 = $this->resolve('sell to "Ahmed": 2 "باكيت" "SOAP-3L" at 80 ILS');
        $this->assertSame($this->aiCarton->id, $resCarton3['payload']['lines'][0]['product_unit_id']);

        // Spoken piece variant 'حبة' -> resolves base piece unit (5 ILS suggested default)
        $pieceUnit = ProductUnit::where('company_id', $this->company->id)
            ->where('product_id', $this->aiProduct->id)
            ->where('is_base', true)
            ->firstOrFail();

        $resPiece1 = $this->resolve('sell to "Ahmed": 3 "حبة" "SOAP-3L" at 5 ILS');
        $this->assertSame($pieceUnit->id, $resPiece1['payload']['lines'][0]['product_unit_id']);
        $this->assertSame('5', $resPiece1['payload']['lines'][0]['unit_price']);

        // Spoken piece variant 'قطعة' -> resolves piece
        $resPiece2 = $this->resolve('sell to "Ahmed": 3 "قطعة" "SOAP-3L" at 5 ILS');
        $this->assertSame($pieceUnit->id, $resPiece2['payload']['lines'][0]['product_unit_id']);

        // Barcode conflict check:
        // When barcode belongs to carton unit, but spoken unit is piece -> conflict!
        app(ProductCatalogService::class)->addBarcode($this->aiProduct, 'BARCODE-CARTON-1', $this->aiCarton->unit_id, true);
        $conflictText = 'sell to "Ahmed": 1 "piece" "BARCODE-CARTON-1" at 5 ILS';
        $conflictResult = $this->resolve($conflictText);
        $this->assertNull($conflictResult['payload']['lines'][0]['product_unit_id']);
        $unitQuestion = collect($conflictResult['questions'])->firstWhere('path', 'lines.0.unit');
        $this->assertNotNull($unitQuestion);
    }

    public function test_bogus_reference_returns_no_candidates_and_does_not_select_unrelated_records(): void
    {
        $text = 'sell to "Ahmed": 2 "piece" "COMPLETELY_BOGUS_NONEXISTENT_ITEM_XYZ" at 10 ILS';
        $result = $this->resolve($text);

        $this->assertNull($result['payload']['lines'][0]['product_id']);
        $question = collect($result['questions'])->firstWhere('path', 'lines.0.product');
        $this->assertNotNull($question);
        $this->assertSame([], $question['candidates']);
    }

    public function test_multiline_payload_preserves_valid_lines_while_clarifying_unresolved_line(): void
    {
        $text = 'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS; 2 "piece" "UNKNOWN_PRODUCT" at 3 ILS';
        $proposal = $this->proposal($text);

        $first = app(TransactionProposalResolver::class)->resolve($this->company, $this->owner, $proposal);

        // Line 0 is fully resolved
        $this->assertSame($this->aiProduct->id, $first['payload']['lines'][0]['product_id']);
        $this->assertSame($this->aiCarton->id, $first['payload']['lines'][0]['product_unit_id']);
        $this->assertSame('5', $first['payload']['lines'][0]['quantity']);
        $this->assertSame('80', $first['payload']['lines'][0]['unit_price']);

        // Line 1 is unresolved
        $this->assertNull($first['payload']['lines'][1]['product_id']);
        $this->assertSame('lines.1.product', $first['questions'][0]['path']);
        $this->assertSame([], $first['questions'][0]['candidates']);

        // An unoffered same-company ID cannot substitute for an unknown reference.
        $forged = app(TransactionProposalResolver::class)->resolve(
            $this->company,
            $this->owner,
            $proposal,
            ['lines.1.product' => (int) $this->aiProduct->id]
        );
        $this->assertNull($forged['payload']['lines'][1]['product_id']);

        // Explicitly correcting the unknown reference preserves the valid line.
        $proposal['lines'][1]['product'] = 'SOAP-3L';
        $clarified = app(TransactionProposalResolver::class)->resolve($this->company, $this->owner, $proposal);

        $this->assertSame($this->aiProduct->id, $clarified['payload']['lines'][0]['product_id']);
        $this->assertSame($this->aiProduct->id, $clarified['payload']['lines'][1]['product_id']);
        $this->assertSame('5', $clarified['payload']['lines'][0]['quantity']);
        $this->assertSame('2', $clarified['payload']['lines'][1]['quantity']);
    }

    public function test_financial_history_invariance_and_no_stray_requests(): void
    {
        $this->resolve('sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS');

        // Resolving proposals must never write financial or stock rows
        $this->assertSame(0, SalesInvoice::count());
        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, PostingBatch::count());
        Http::assertNothingSent();
    }

    public function test_normalization_choices_handle_digits_tatweel_diacritics_alef_and_whitespace(): void
    {
        // Digits (Arabic-Indic and Persian)
        $this->assertSame('0123456789', TenantSpeechCandidates::normalize('٠١٢٣٤٥٦٧٨٩'));
        $this->assertSame('0123456789', TenantSpeechCandidates::normalize('۰۱۲۳۴۵۶۷۸۹'));
        $this->assertSame('12.5', TenantSpeechCandidates::normalize('١٢٫٥'));

        // Tatweel
        $this->assertSame('احمد', TenantSpeechCandidates::normalize('احـــــمد'));

        // Diacritics
        $this->assertSame('احمد', TenantSpeechCandidates::normalize('أَحْمَدُ'));

        // Alef variants
        $this->assertSame('احمد', TenantSpeechCandidates::normalize('إحمد'));
        $this->assertSame('احمد', TenantSpeechCandidates::normalize('آحمد'));
        $this->assertSame('احمد', TenantSpeechCandidates::normalize('ٱحمد'));

        // Taa Marbuta & Alef Maqsura
        $this->assertSame('كرتونه', TenantSpeechCandidates::normalize('كرتونة'));
        $this->assertSame('علي', TenantSpeechCandidates::normalize('على'));

        // Whitespace and case
        $this->assertSame('dish soap 3l', TenantSpeechCandidates::normalize("  DiSh   Soap\t3L \n "));
    }

    /** @return array<string, mixed> */
    private function proposal(string $text): array
    {
        return app(TransactionProposalSchema::class)->validate(
            app(DeterministicTransactionParser::class)->parse($text),
            $text,
            'auto'
        );
    }

    /**
     * @param  array<string, int>  $choices
     * @return array{intent: string, payload: array<string, mixed>, questions: list<array<string, mixed>>, origins: array<string, string>, unresolved: list<string>}
     */
    private function resolve(string $text, ?User $actor = null, array $choices = []): array
    {
        return app(TransactionProposalResolver::class)->resolve(
            $this->company,
            $actor ?? $this->owner,
            $this->proposal($text),
            $choices
        );
    }
}
