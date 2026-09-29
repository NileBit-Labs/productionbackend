<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A purchase is stock received from a supplier. It is never edited or
     * deleted: a mistake is cancelled, which reverses the stock and the debt
     * with new entries.
     */
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained();
            $table->string('purchase_number');
            $table->string('status')->default('received');
            $table->date('purchase_date');
            $table->string('reference', 100)->nullable();
            $table->unsignedBigInteger('total');
            $table->unsignedBigInteger('amount_paid')->default(0);
            $table->unsignedBigInteger('amount_due')->default(0);
            $table->string('note', 500)->nullable();
            $table->foreignId('received_by')->constrained('users');
            $table->string('idempotency_key', 100)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'purchase_number']);
            $table->unique(['shop_id', 'idempotency_key']);
            $table->index(['shop_id', 'purchase_date']);
            $table->index(['supplier_id', 'created_at']);
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            // As entered on the supplier's invoice (e.g. 5 cartons at 48,000).
            $table->decimal('quantity', 12, 3);
            $table->string('unit_name')->nullable();
            $table->decimal('conversion', 12, 3)->default(1);
            $table->unsignedBigInteger('unit_cost');
            $table->unsignedBigInteger('line_total');
            // What that means in the product's own base unit (5 cartons x 24 = 120 pieces).
            $table->decimal('base_quantity', 12, 3);
            $table->unsignedBigInteger('base_unit_cost');
            $table->timestamps();

            $table->index('purchase_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
    }
};
