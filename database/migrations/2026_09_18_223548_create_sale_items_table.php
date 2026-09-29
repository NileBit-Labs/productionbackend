<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Price and cost are copied onto each line at sale time so later price
     * changes can never rewrite past revenue or profit.
     */
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->string('product_name');
            $table->decimal('quantity', 12, 3);
            $table->string('unit');
            $table->decimal('unit_conversion', 12, 3)->default(1);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('historical_cost');
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('line_total');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
