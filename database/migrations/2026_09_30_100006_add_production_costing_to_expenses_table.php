<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            // What an expense in this category usually is; the screen pre-selects it.
            $table->string('default_type', 30)->default('operating');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['shop_id', 'name']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            // operating, direct_labour or direct_production. Only operating expenses reduce
            // profit directly; direct ones are part of a batch's cost and reach profit through COGS.
            $table->string('type', 30)->default('operating')->after('category');
            $table->foreignId('production_batch_id')->nullable()->after('type')->constrained()->nullOnDelete();

            $table->index(['shop_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'type']);
            $table->dropConstrainedForeignId('production_batch_id');
            $table->dropColumn('type');
        });

        Schema::dropIfExists('expense_categories');
    }
};
