<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('sale_number');
            // FK is added by the customers migration once that table exists.
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->foreignId('cashier_id')->constrained('users');
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('total');
            $table->unsignedBigInteger('amount_paid')->default(0);
            $table->unsignedBigInteger('amount_due')->default(0);
            $table->date('due_date')->nullable();
            $table->string('status')->default('completed');
            $table->string('idempotency_key')->nullable();
            $table->timestamp('client_created_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'sale_number']);
            $table->unique(['shop_id', 'idempotency_key']);
            $table->index(['shop_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
