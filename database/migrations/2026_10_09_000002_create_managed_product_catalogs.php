<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('catalogs')) {
            if (! Schema::hasTable('catalog_items') || ! Schema::hasTable('catalog_publications') || ! Schema::hasColumn('catalogs', 'published_payload') || ! Schema::hasIndex('catalog_items', 'catalog_item_company_product_unique')) {
                throw new RuntimeException('Incomplete retained catalog schema requires repair.');
            }

            return;
        }
        foreach (['products', 'units'] as $parent) {
            if (! Schema::hasIndex($parent, $parent.'_company_id_unique')) {
                Schema::table($parent, fn (Blueprint $table) => $table->unique(['company_id', 'id'], $parent.'_company_id_unique'));
            }
        }
        Schema::create('catalogs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('name_ar', 160);
            $table->string('name_en', 160)->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('locale', 2)->default('ar');
            $table->string('status', 16)->default('draft');
            $table->boolean('show_prices')->default(false);
            $table->boolean('show_sku')->default(true);
            $table->boolean('show_description')->default(true);
            $table->boolean('show_images')->default(true);
            $table->string('currency_code', 3)->nullable();
            $table->string('tax_basis', 160)->nullable();
            $table->unsignedInteger('draft_revision')->default(1);
            $table->unsignedInteger('published_revision')->default(0);
            $table->json('published_payload')->nullable();
            $table->char('published_hash', 64)->nullable();
            $table->string('publication_key', 100)->nullable();
            $table->char('publication_intent_hash', 64)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id'], 'catalog_company_id_unique');
            $table->unique(['company_id', 'publication_key'], 'catalog_company_publication_unique');
            $table->index(['company_id', 'status']);
        });
        Schema::create('catalog_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('catalog_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('unit_id');
            // Draft selection only; published immutable media provenance lives in the approved projection.
            $table->unsignedBigInteger('image_id')->nullable();
            $table->unsignedSmallInteger('position');
            $table->string('name_ar', 160)->nullable();
            $table->string('name_en', 160)->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->decimal('custom_price', 20, 6)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'catalog_id', 'product_id'], 'catalog_item_company_product_unique');
            $table->index(['company_id', 'catalog_id', 'position']);
            $table->foreign(['company_id', 'catalog_id'])->references(['company_id', 'id'])->on('catalogs')->restrictOnDelete();
            $table->foreign(['company_id', 'product_id'])->references(['company_id', 'id'])->on('products')->restrictOnDelete();
            $table->foreign(['company_id', 'unit_id'])->references(['company_id', 'id'])->on('units')->restrictOnDelete();
        });
        Schema::create('catalog_publications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('catalog_id');
            $table->string('request_key', 100);
            $table->char('intent_hash', 64);
            $table->char('content_hash', 64);
            $table->unsignedInteger('revision');
            $table->json('payload');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['company_id', 'request_key'], 'catalog_publication_company_request_unique');
            $table->unique(['company_id', 'catalog_id', 'revision'], 'catalog_publication_revision_unique');
            $table->foreign(['company_id', 'catalog_id'])->references(['company_id', 'id'])->on('catalogs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Forward-only. Code rollback must retain approved marketing revisions and managed grants.
    }
};
