<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_batch_output_id')->constrained()->cascadeOnDelete();
            $table->decimal('produced_quantity', 12, 3);
            $table->date('production_date');
            $table->date('expiry_date')->nullable();
            $table->timestamps();

            $table->unique('production_batch_output_id');
            $table->index(['shop_id', 'product_id', 'expiry_date']);
        });

        Schema::create('production_lot_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_lot_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity_delta', 12, 3);
            $table->string('movement_type', 40);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('performed_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['production_lot_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_lot_movements');
        Schema::dropIfExists('production_lots');
    }
};
