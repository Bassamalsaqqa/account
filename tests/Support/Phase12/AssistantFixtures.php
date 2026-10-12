<?php

declare(strict_types=1);

namespace Tests\Support\Phase12;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\Warehouse;
use Tests\Support\SalesCorrectionFixtures;

trait AssistantFixtures
{
    use SalesCorrectionFixtures;

    protected Product $aiProduct;

    protected ProductUnit $aiCarton;

    protected Warehouse $aiWarehouse;

    protected array $proposal;

    protected function assistantFixtures(): void
    {
        $this->salesFixtures();
        app()->setLocale('en');
        $this->customer->update(['name_ar' => 'أحمد', 'name_en' => 'Ahmed', 'code' => 'C-AHMED']);
        config([
            'ai_assistant.enabled' => true, 'ai_assistant.provider_approved' => true,
            'ai_assistant.provider' => 'direct', 'ai_assistant.speech_enabled' => true,
            'ai_assistant.request_cost_ceiling_micros' => 1000,
            'ai_assistant.daily_company_cost_ceiling_micros' => 100000,
            'ai_assistant.monthly_company_cost_ceiling_micros' => 1000000,
            'ai_assistant.daily_user_cost_ceiling_micros' => 100000,
            'ai_assistant.monthly_user_cost_ceiling_micros' => 1000000,
        ]);
        $piece = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();
        $carton = Unit::updateOrCreate(['company_id' => $this->company->id, 'code' => 'carton'], ['name_ar' => 'كرتونة', 'name_en' => 'carton', 'allows_fraction' => false, 'decimal_places' => 0, 'active' => true]);
        $this->aiWarehouse = Warehouse::where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();
        $this->aiWarehouse->update(['name_ar' => 'المستودع', 'name_en' => 'Warehouse', 'code' => 'WH-AI']);
        $this->aiProduct = Product::create([
            'company_id' => $this->company->id, 'sku' => 'SOAP-3L', 'name_ar' => 'سائل الجلي', 'name_en' => 'Dishwashing Liquid',
            'base_unit_id' => $piece->id, 'product_type' => 'non_stock', 'track_stock' => false,
            'default_sale_price_base' => '5.000000', 'active' => true, 'created_by' => $this->owner->id,
        ]);
        ProductUnit::create(['company_id' => $this->company->id, 'product_id' => $this->aiProduct->id, 'unit_id' => $piece->id,
            'conversion_to_base' => '1.000000', 'is_base' => true, 'is_default_sale' => true, 'active' => true]);
        $this->aiCarton = ProductUnit::create(['company_id' => $this->company->id, 'product_id' => $this->aiProduct->id, 'unit_id' => $carton->id,
            'conversion_to_base' => '12.000000', 'default_sale_price_base' => '80.000000', 'active' => true]);
        $this->proposal = ['version' => 1, 'intent' => 'draft_sales_invoice', 'customer' => 'Ahmed', 'currency' => 'ILS', 'warehouse' => 'Warehouse',
            'lines' => [['product' => 'Dishwashing Liquid', 'unit' => 'carton', 'quantity' => '5', 'unit_price' => '80']]];
    }

    protected function wav(int $seconds = 1): string
    {
        $rate = 8000;
        $data = str_repeat("\0", $rate * 2 * $seconds);

        return 'RIFF'.pack('V', strlen($data) + 36).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16).'data'.pack('V', strlen($data)).$data;
    }
}
