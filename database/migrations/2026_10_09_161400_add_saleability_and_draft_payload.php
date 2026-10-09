<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_saleable')->nullable();
        });
        Schema::table('production_batches', function (Blueprint $table) {
            $table->json('draft_payload')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('is_saleable'));
        Schema::table('production_batches', fn (Blueprint $table) => $table->dropColumn('draft_payload'));
    }
};
