<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // raw_material and packaging are production inputs; only finished_good is sold at the till.
            // Existing products default to finished_good so everything already on sale keeps working.
            $table->string('kind', 30)->default('finished_good')->after('category_id');
            // Groups the sizes of one product line (e.g. "Mango Juice" in 300ml, 500ml and 1L).
            $table->string('family')->nullable()->after('name');
            $table->string('size_label', 50)->nullable()->after('family');
            // How much of a batch's output one unit represents, in the recipe's yield unit
            // (a 300ml bottle of a recipe that yields litres is 0.3). Batch cost is shared by it.
            $table->decimal('output_equivalent', 12, 3)->nullable()->after('size_label');
            $table->unsignedInteger('shelf_life_days')->nullable()->after('output_equivalent');

            $table->index(['shop_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'kind']);
            $table->dropColumn(['kind', 'family', 'size_label', 'output_equivalent', 'shelf_life_days']);
        });
    }
};
