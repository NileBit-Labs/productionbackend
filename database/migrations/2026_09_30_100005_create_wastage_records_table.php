<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wastage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            // Set when the loss happened inside a batch: its cost is then part of that batch's cost.
            $table->foreignId('production_batch_id')->nullable()->constrained()->nullOnDelete();
            // raw_material, packaging, production or finished_goods.
            $table->string('stage', 30);
            $table->decimal('quantity', 12, 3);
            $table->unsignedBigInteger('unit_cost');
            $table->unsignedBigInteger('total_cost');
            $table->string('reason');
            $table->date('wastage_date');
            $table->foreignId('stock_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->constrained('users');
            $table->string('idempotency_key', 100)->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'idempotency_key']);
            $table->index(['shop_id', 'wastage_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wastage_records');
    }
};
