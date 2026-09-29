<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // The product line this recipe makes; its sizes are the finished goods with the same family.
            $table->string('family')->nullable();
            // What one run of the recipe is expected to make (e.g. 100 litres, 40 loaves).
            $table->decimal('yield_quantity', 12, 3);
            $table->string('yield_unit', 50);
            $table->text('instructions')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['shop_id', 'name']);
        });

        Schema::create('recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            // In the input product's own base unit.
            $table->decimal('quantity', 12, 3);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['recipe_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_items');
        Schema::dropIfExists('recipes');
    }
};
