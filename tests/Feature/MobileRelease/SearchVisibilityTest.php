<?php

declare(strict_types=1);

namespace Tests\Feature\MobileRelease;

use App\Actions\Money\ReceiveCheckAction;
use App\Actions\Sales\CreateQuotationAction;
use App\Livewire\Pages\Customers\CustomerIndex;
use App\Models\Customer;
use App\Services\Search\GlobalSearch;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Phase5E\Phase5ETestCase;

class SearchVisibilityTest extends Phase5ETestCase
{
    public function test_each_document_category_uses_its_permission_and_readonly_safe_projection(): void
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'عميل البحث', 'name_en' => 'Search Customer', 'created_by' => $this->owner->id]);
        $quote = app(CreateQuotationAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id, 'issue_date' => '2026-10-15', 'currency_code' => 'ILS', 'exchange_rate' => '1',
            'lines' => [['item_description' => 'Search item', 'quantity' => '1', 'unit_price' => '9876.54']],
        ]);
        $purchase = $this->createAndPostPurchase();
        $check = app(ReceiveCheckAction::class)->execute($this->company, $this->owner, [
            'party_id' => $customer->id, 'check_number' => 'SEARCH-CHECK-01', 'bank_name' => 'Bank', 'date' => '2026-10-15',
            'due_date' => '2026-10-20', 'currency_code' => 'ILS', 'amount' => '8765.43', 'exchange_rate' => '1',
            'idempotency_key' => 'search-check', 'allocations' => [],
        ]);
        $before = $this->fingerprint();
        foreach ([['sales.quote.view', 'Search Customer', 'quotation', $quote->public_id],
            ['purchasing.purchase.view', $purchase->purchase_number, 'purchase', $purchase->public_id],
            ['money.check.view', 'SEARCH-CHECK', 'check', $check->public_id],
            ['vendors.view', $this->vendor->name_ar, 'vendor', $this->vendor->public_id],
        ] as [$permission, $term, $category, $id]) {
            $actor = $this->customActor([$permission], 'Search-'.$category);
            $this->activate($actor);
            $results = app(GlobalSearch::class)->search($term);
            $this->assertSame([$category], array_keys($results));
            $this->assertSame($id, $results[$category][0]['public_id']);
            $this->assertStringNotContainsString('9876.54', json_encode($results));
            $this->assertStringNotContainsString('8765.43', json_encode($results));
        }
        $this->assertSame($before, $this->fingerprint());
    }

    public function test_search_only_role_has_no_financial_mobile_destination_or_camera_write_controls(): void
    {
        $this->activate($this->customActor(['customers.view'], 'SearchOnly'));
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $html = Livewire::test(CustomerIndex::class)->html();
            $this->assertStringNotContainsString('href="'.route('payments.create'), $html);
            $this->assertStringNotContainsString('href="'.route('invoices.create'), $html);
        }
    }

    /** @return array<string,string> */
    private function fingerprint(): array
    {
        $out = [];
        foreach (['posting_lines', 'posting_batches', 'stock_movements', 'document_sequences', 'check_events'] as $table) {
            $out[$table] = hash('sha256', DB::table($table)->orderBy('id')->get()->toJson());
        }

        return $out;
    }
}
