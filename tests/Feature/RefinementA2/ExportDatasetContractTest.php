<?php

declare(strict_types=1);

namespace Tests\Feature\RefinementA2;

use App\Application\CompanyDataExport\Datasets\ExportDatasetRegistry;
use App\Application\Reporting\Support\CsvCellFormatter;
use App\Models\Customer;
use Tests\Feature\Phase8\Phase8TestCase;

final class ExportDatasetContractTest extends Phase8TestCase
{
    public function test_every_dataset_query_matches_mariadb_schema_and_declared_fields(): void
    {
        $definitions = ExportDatasetRegistry::all();
        $this->assertCount(40, $definitions);
        $keys = [];
        foreach ($definitions as $definition) {
            $keys[] = $definition['key'];
            $this->assertMatchesRegularExpression('/^[a-z_]+\.csv$/', $definition['filename']);
            foreach (($definition['fetch'])($this->company, 100) as $chunk) {
                foreach ($chunk as $row) {
                    foreach ($definition['columns'] as $column) {
                        $this->assertArrayHasKey($column['key'], $row, $definition['key'].'.'.$column['key']);
                        if ($column['type'] === 'decimal') {
                            CsvCellFormatter::decimal(isset($row[$column['key']]) ? (string) $row[$column['key']] : null);
                        }
                    }
                }
            }
        }
        $this->assertSame($keys, array_values(array_unique($keys)));
    }

    public function test_archived_customer_directory_and_currency_balances_remain_interpretable(): void
    {
        $customer = Customer::create([
            'company_id' => $this->company->id, 'name_ar' => 'عميل مؤرشف',
            'active' => true, 'created_by' => $this->owner->id,
        ]);
        $customer->delete();
        $definition = collect(ExportDatasetRegistry::all())->firstWhere('key', 'customers');
        $rows = [];
        foreach (($definition['fetch'])($this->company, 100) as $chunk) {
            $rows = array_merge($rows, $chunk);
        }
        $row = collect($rows)->firstWhere('id', $customer->id);
        $this->assertNotNull($row);
        $this->assertNotNull($row['deleted_at']);
    }
}
