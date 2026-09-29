<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained();
            // Value of the goods returned = money handed back + debt cancelled.
            $table->unsignedBigInteger('total_refund');
            $table->unsignedBigInteger('cash_refund')->default(0);
            $table->unsignedBigInteger('balance_credit')->default(0);
            $table->string('method')->nullable();
            $table->string('reason');
            $table->foreignId('approved_by')->constrained('users');
            $table->string('idempotency_key')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'idempotency_key']);
            $table->index(['shop_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
