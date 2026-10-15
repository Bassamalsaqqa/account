<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_creation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key');
            $table->char('request_hash', 64);
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['company_id', 'user_id', 'request_key'], 'product_creation_request_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_creation_requests');
    }
};
