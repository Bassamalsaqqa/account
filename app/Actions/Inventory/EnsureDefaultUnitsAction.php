<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\Company;
use App\Models\Unit;

class EnsureDefaultUnitsAction
{
    /**
     * Common default units catalog.
     *
     * @return list<array{code: string, name_ar: string, name_en: string, symbol_ar: ?string, symbol_en: ?string, allows_fraction: bool, decimal_places: int}>
     */
    public static function defaultCatalog(): array
    {
        return [
            [
                'code' => 'piece',
                'name_ar' => 'قطعة',
                'name_en' => 'Piece',
                'symbol_ar' => 'قطعة',
                'symbol_en' => 'pc',
                'allows_fraction' => false,
                'decimal_places' => 0,
            ],
            [
                'code' => 'carton',
                'name_ar' => 'كرتونة',
                'name_en' => 'Carton',
                'symbol_ar' => 'كرتونة',
                'symbol_en' => 'ctn',
                'allows_fraction' => false,
                'decimal_places' => 0,
            ],
            [
                'code' => 'box',
                'name_ar' => 'صندوق',
                'name_en' => 'Box',
                'symbol_ar' => 'صندوق',
                'symbol_en' => 'box',
                'allows_fraction' => false,
                'decimal_places' => 0,
            ],
            [
                'code' => 'pack',
                'name_ar' => 'طرد / عبوة',
                'name_en' => 'Pack',
                'symbol_ar' => 'طرد',
                'symbol_en' => 'pk',
                'allows_fraction' => false,
                'decimal_places' => 0,
            ],
            [
                'code' => 'bottle',
                'name_ar' => 'زجاجة / قارورة',
                'name_en' => 'Bottle',
                'symbol_ar' => 'زجاجة',
                'symbol_en' => 'btl',
                'allows_fraction' => false,
                'decimal_places' => 0,
            ],
            [
                'code' => 'kg',
                'name_ar' => 'كيلوغرام',
                'name_en' => 'Kilogram',
                'symbol_ar' => 'كغم',
                'symbol_en' => 'kg',
                'allows_fraction' => true,
                'decimal_places' => 3,
            ],
            [
                'code' => 'g',
                'name_ar' => 'غرام',
                'name_en' => 'Gram',
                'symbol_ar' => 'غم',
                'symbol_en' => 'g',
                'allows_fraction' => true,
                'decimal_places' => 2,
            ],
            [
                'code' => 'liter',
                'name_ar' => 'لتر',
                'name_en' => 'Liter',
                'symbol_ar' => 'لتر',
                'symbol_en' => 'L',
                'allows_fraction' => true,
                'decimal_places' => 3,
            ],
            [
                'code' => 'meter',
                'name_ar' => 'متر',
                'name_en' => 'Meter',
                'symbol_ar' => 'م',
                'symbol_en' => 'm',
                'allows_fraction' => true,
                'decimal_places' => 2,
            ],
        ];
    }

    /**
     * Idempotently ensure standard units exist for company.
     *
     * @return list<Unit>
     */
    public function execute(Company $company): array
    {
        $created = [];

        foreach (self::defaultCatalog() as $def) {
            $created[] = Unit::firstOrCreate(
                [
                    'company_id' => $company->id,
                    'code' => $def['code'],
                ],
                [
                    'name_ar' => $def['name_ar'],
                    'name_en' => $def['name_en'],
                    'symbol_ar' => $def['symbol_ar'],
                    'symbol_en' => $def['symbol_en'],
                    'allows_fraction' => $def['allows_fraction'],
                    'decimal_places' => $def['decimal_places'],
                    'active' => true,
                ]
            );
        }

        return $created;
    }
}
