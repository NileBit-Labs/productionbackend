<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', fn (Blueprint $table) => $table->unsignedBigInteger('delivery_fee')->default(0));
        Schema::table('refunds', fn (Blueprint $table) => $table->unsignedBigInteger('delivery_fee_refund')->default(0));
        Schema::create('delivery_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->foreignId('sale_id')->unique()->constrained();
            $table->string('fulfillment_type', 20);
            $table->string('status', 30)->default('pending');
            $table->string('recipient_name');
            $table->string('recipient_phone', 100);
            $table->string('address', 500)->nullable();
            $table->text('location_notes')->nullable();
            $table->text('instructions')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('requested_at')->nullable();
            $table->string('driver_name')->nullable();
            $table->string('driver_phone', 100)->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('proof_of_delivery', 1000)->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestampsTz();
            $table->index(['shop_id', 'status']);
        });
        Schema::create('delivery_payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_order_id')->constrained();
            $table->string('idempotency_key', 100);
            $table->foreignId('payment_id')->constrained();
            $table->unique(['delivery_order_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_payment_attempts');
        Schema::dropIfExists('delivery_orders');
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('delivery_fee'));
        Schema::table('refunds', fn (Blueprint $table) => $table->dropColumn('delivery_fee_refund'));
    }
};
