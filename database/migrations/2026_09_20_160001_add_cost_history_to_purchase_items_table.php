<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** What the product's cost was before and after this line, so a cancelled purchase can put it back. */
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->unsignedBigInteger('cost_before')->nullable();
            $table->unsignedBigInteger('cost_after')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', fn (Blueprint $table) => $table->dropColumn(['cost_before', 'cost_after']));
    }
};
