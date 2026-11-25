<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NULL identifies the pre-feature write policy, independent of current currency metadata.
        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->unsignedTinyInteger('currency_minor_units')->nullable()->after('currency_code');
            $table->string('discount_type', 16)->nullable()->after('currency_minor_units');
            $table->decimal('discount_value', 20, 6)->default(0)->after('discount_type');
            $table->string('discount_scope', 16)->default('line')->after('discount_value');
            $table->string('discount_basis', 32)->default('subtotal')->after('discount_scope');
            $table->unsignedTinyInteger('discount_calculation_version')->default(1)->after('discount_basis');
        });

        Schema::table('sales_invoice_lines', function (Blueprint $table): void {
            $table->decimal('manual_discount_amount', 20, 6)->default(0)->after('discount_value');
            $table->decimal('allocated_document_discount', 20, 6)->default(0)->after('manual_discount_amount');
        });

        Schema::table('quotations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('currency_minor_units')->nullable()->after('currency_code');
            $table->string('discount_type', 16)->nullable()->after('currency_minor_units');
            $table->decimal('discount_value', 20, 6)->default(0)->after('discount_type');
            $table->string('discount_scope', 16)->default('line')->after('discount_value');
            $table->string('discount_basis', 32)->default('subtotal')->after('discount_scope');
            $table->unsignedTinyInteger('discount_calculation_version')->default(1)->after('discount_basis');
        });

        Schema::table('quotation_lines', function (Blueprint $table): void {
            $table->decimal('manual_discount_amount', 20, 6)->default(0)->after('discount_value');
            $table->decimal('allocated_document_discount', 20, 6)->default(0)->after('manual_discount_amount');
        });

        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->unsignedTinyInteger('currency_minor_units')->nullable()->after('currency_code');
        });
    }

    public function down(): void
    {
        // Check for populated snapshots before issuing any implicit-commit DDL.
        $hasInvoiceDiscountHistory = DB::table('sales_invoices')
            ->where(function ($q): void {
                $q->where('discount_scope', '!=', 'line')
                    ->orWhereNotNull('discount_type')
                    ->orWhere('discount_value', '!=', 0)
                    ->orWhere('discount_calculation_version', '!=', 1)
                    ->orWhere('discount_basis', '!=', 'subtotal')
                    ->orWhereNotNull('currency_minor_units');
            })
            ->exists();

        $hasInvoiceLineDiscountHistory = DB::table('sales_invoice_lines')
            ->where(function ($q): void {
                $q->where('allocated_document_discount', '!=', 0)
                    ->orWhere('manual_discount_amount', '!=', 0);
            })
            ->exists();

        $hasQuotationDiscountHistory = DB::table('quotations')
            ->where(function ($q): void {
                $q->where('discount_scope', '!=', 'line')
                    ->orWhereNotNull('discount_type')
                    ->orWhere('discount_value', '!=', 0)
                    ->orWhere('discount_calculation_version', '!=', 1)
                    ->orWhere('discount_basis', '!=', 'subtotal')
                    ->orWhereNotNull('currency_minor_units');
            })
            ->exists();

        $hasQuotationLineDiscountHistory = DB::table('quotation_lines')
            ->where(function ($q): void {
                $q->where('allocated_document_discount', '!=', 0)
                    ->orWhere('manual_discount_amount', '!=', 0);
            })
            ->exists();

        $hasReturnPrecision = DB::table('sales_returns')->whereNotNull('currency_minor_units')->exists();

        if ($hasReturnPrecision || $hasInvoiceDiscountHistory || $hasInvoiceLineDiscountHistory || $hasQuotationDiscountHistory || $hasQuotationLineDiscountHistory) {
            throw new RuntimeException('Cannot rollback migration: sales, quotation, or return discount or currency precision history exists. Retain the additive schema during source rollback.');
        }

        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->dropColumn(['currency_minor_units']);
        });

        Schema::table('quotation_lines', function (Blueprint $table): void {
            $table->dropColumn(['allocated_document_discount', 'manual_discount_amount']);
        });

        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropColumn([
                'discount_calculation_version',
                'discount_basis',
                'discount_scope',
                'discount_value',
                'discount_type',
                'currency_minor_units',
            ]);
        });

        Schema::table('sales_invoice_lines', function (Blueprint $table): void {
            $table->dropColumn(['allocated_document_discount', 'manual_discount_amount']);
        });

        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->dropColumn([
                'discount_calculation_version',
                'discount_basis',
                'discount_scope',
                'discount_value',
                'discount_type',
                'currency_minor_units',
            ]);
        });
    }
};
