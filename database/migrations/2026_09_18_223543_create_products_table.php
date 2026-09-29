<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('base_unit')->default('piece');
            // Money is stored as integer UGX, never floating point.
            $table->unsignedBigInteger('selling_price');
            $table->unsignedBigInteger('current_cost')->default(0);
            $table->decimal('low_stock_threshold', 12, 3)->default(0);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['shop_id', 'sku']);
            $table->unique(['shop_id', 'barcode']);
            $table->index(['shop_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
