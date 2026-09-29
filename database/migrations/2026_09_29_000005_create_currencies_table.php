<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary();
            $table->string('name', 64);
            $table->string('symbol', 16);
            $table->unsignedTinyInteger('minor_units')->default(2);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Seed standard currencies idempotently
        $currencies = [
            ['code' => 'ILS', 'name' => 'Israeli Shekel', 'symbol' => '₪', 'minor_units' => 2, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'minor_units' => 2, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'JOD', 'name' => 'Jordanian Dinar', 'symbol' => 'د.أ', 'minor_units' => 3, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ];

        foreach ($currencies as $currency) {
            DB::table('currencies')->updateOrInsert(
                ['code' => $currency['code']],
                $currency
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
