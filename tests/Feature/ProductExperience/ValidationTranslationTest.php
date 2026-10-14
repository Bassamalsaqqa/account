<?php

declare(strict_types=1);

namespace Tests\Feature\ProductExperience;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ValidationTranslationTest extends TestCase
{
    public function test_validation_files_exist_in_resources_lang(): void
    {
        $arPath = resource_path('lang/ar/validation.php');
        $enPath = resource_path('lang/en/validation.php');

        $this->assertFileExists($arPath);
        $this->assertFileExists($enPath);

        // Active path must be resources/lang, not root lang
        $rootLangPath = base_path('lang');
        $this->assertDirectoryDoesNotExist($rootLangPath);
    }

    public function test_arabic_validation_array_structure_and_core_rules(): void
    {
        /** @var array<string, mixed> $ar */
        $ar = require resource_path('lang/ar/validation.php');

        $this->assertIsArray($ar);

        $coreRules = [
            'accepted',
            'after',
            'after_or_equal',
            'before',
            'before_or_equal',
            'between',
            'boolean',
            'confirmed',
            'current_password',
            'date',
            'date_format',
            'decimal',
            'digits',
            'email',
            'exists',
            'in',
            'integer',
            'max',
            'min',
            'numeric',
            'password',
            'required',
            'same',
            'size',
            'string',
            'unique',
        ];

        foreach ($coreRules as $rule) {
            $this->assertArrayHasKey($rule, $ar, "Arabic validation file is missing rule: {$rule}");
        }

        $this->assertIsArray($ar['attributes']);
        $this->assertNotEmpty($ar['attributes']);
    }

    public function test_installed_framework_validation_rules_have_arabic_and_english_messages(): void
    {
        $framework = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');

        foreach (['ar', 'en'] as $locale) {
            $messages = require resource_path("lang/{$locale}/validation.php");
            $this->assertSame([], array_values(array_diff(array_keys($framework), array_keys($messages))));
        }
    }

    public function test_arabic_attributes_cover_critical_app_fields(): void
    {
        /** @var array<string, mixed> $ar */
        $ar = require resource_path('lang/ar/validation.php');
        $attributes = $ar['attributes'];

        $expectedFieldKeys = [
            'name',
            'name_ar',
            'name_en',
            'email',
            'password',
            'password_confirmation',
            'role',
            'locale',
            'sku',
            'barcode',
            'category_id',
            'brand_id',
            'base_unit_id',
            'product_type',
            'track_stock',
            'track_expiry',
            'unit_price',
            'quantity',
            'tax_rate',
            'warehouse_id',
            'vendor_id',
            'customer_id',
            'account_id',
            'from_account_id',
            'to_account_id',
            'check_number',
            'status',
            'due_date',
            'issue_date',
        ];

        foreach ($expectedFieldKeys as $key) {
            $this->assertArrayHasKey($key, $attributes, "Arabic validation attributes is missing key: {$key}");
            $this->assertNotEmpty($attributes[$key], "Arabic label for attribute {$key} must not be empty");
        }
    }

    public function test_arabic_validator_produces_truthful_messages_with_attributes(): void
    {
        App::setLocale('ar');

        $validator = Validator::make(
            ['name_ar' => null, 'sku' => null],
            ['name_ar' => ['required', 'string'], 'sku' => ['required']]
        );

        $this->assertTrue($validator->fails());

        $errors = $validator->errors();
        $nameArError = $errors->first('name_ar');
        $skuError = $errors->first('sku');

        $this->assertStringContainsString('الاسم بالعربية', $nameArError);
        $this->assertStringContainsString('مطلوب', $nameArError);

        $this->assertStringContainsString('رمز الصنف (SKU)', $skuError);
        $this->assertStringContainsString('مطلوب', $skuError);
    }

    public function test_english_validator_produces_truthful_messages_with_attributes(): void
    {
        App::setLocale('en');

        $validator = Validator::make(
            ['name_ar' => null, 'sku' => null],
            ['name_ar' => ['required', 'string'], 'sku' => ['required']]
        );

        $this->assertTrue($validator->fails());

        $errors = $validator->errors();
        $nameArError = $errors->first('name_ar');
        $skuError = $errors->first('sku');

        $this->assertSame('The Arabic name field is required.', $nameArError);
        $this->assertSame('The SKU field is required.', $skuError);
    }
}
