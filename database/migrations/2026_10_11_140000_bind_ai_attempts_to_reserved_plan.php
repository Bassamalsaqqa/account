<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_requests', function (Blueprint $table): void {
            $table->json('attempt_plan')->nullable(); // Route IDs/revisions only, never credentials or content.
        });
    }

    public function down(): void
    {
        Schema::table('assistant_requests', fn (Blueprint $table) => $table->dropColumn('attempt_plan'));
    }
};
