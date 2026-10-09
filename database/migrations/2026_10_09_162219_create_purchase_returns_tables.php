<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->foreignId('purchase_id')->constrained();
            $table->unsignedBigInteger('total');
            $table->unsignedBigInteger('balance_credit');
            $table->unsignedBigInteger('cash_refund');
            $table->string('reason', 500);
            $table->string('idempotency_key', 100);
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();
            $table->unique(['shop_id', 'idempotency_key']);
        });
        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained();
            $table->foreignId('purchase_item_id')->constrained();
            $table->decimal('quantity', 18, 3);
            $table->unsignedBigInteger('amount');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
    }
};
