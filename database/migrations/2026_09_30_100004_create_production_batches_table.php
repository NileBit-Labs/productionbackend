<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->unsignedBigInteger('batch_counter')->default(0);
        });

        Schema::create('production_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('batch_number', 50);
            $table->foreignId('recipe_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            // draft -> completed, or draft/completed -> cancelled.
            $table->string('status', 20)->default('draft');
            $table->date('production_date');
            $table->date('expiry_date')->nullable();
            // Expected output in the recipe's yield unit, used to scale the recipe and measure yield.
            $table->decimal('planned_yield', 12, 3)->nullable();
            $table->string('yield_unit', 50)->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            // Frozen when the batch is completed. Money is integer UGX.
            $table->unsignedBigInteger('material_cost')->default(0);
            $table->unsignedBigInteger('packaging_cost')->default(0);
            $table->unsignedBigInteger('labour_cost')->default(0);
            $table->unsignedBigInteger('direct_expense_cost')->default(0);
            $table->unsignedBigInteger('wastage_cost')->default(0);
            $table->unsignedBigInteger('total_cost')->default(0);
            // Saleable output in the yield unit (sum of quantity x output equivalent).
            $table->decimal('output_quantity', 14, 3)->default(0);

            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->string('cancel_reason')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'batch_number']);
            $table->unique(['shop_id', 'idempotency_key']);
            $table->index(['shop_id', 'production_date']);
            $table->index(['shop_id', 'status']);
        });

        Schema::create('production_batch_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            // Both in the input's base unit. Planned comes from the recipe; actual is what was really used.
            $table->decimal('planned_quantity', 12, 3)->nullable();
            $table->decimal('actual_quantity', 12, 3)->nullable();
            $table->unsignedBigInteger('unit_cost')->default(0);
            $table->unsignedBigInteger('line_cost')->default(0);
            $table->timestamps();

            $table->unique(['production_batch_id', 'product_id']);
        });

        Schema::create('production_batch_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->decimal('quantity', 12, 3);
            $table->decimal('output_equivalent', 12, 3)->default(1);
            $table->unsignedBigInteger('allocated_cost')->default(0);
            $table->unsignedBigInteger('unit_cost')->default(0);
            $table->date('expiry_date')->nullable();
            $table->unsignedBigInteger('cost_before')->nullable();
            $table->unsignedBigInteger('cost_after')->nullable();
            $table->timestamps();

            $table->unique(['production_batch_id', 'product_id']);
            $table->index(['product_id', 'expiry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_batch_outputs');
        Schema::dropIfExists('production_batch_inputs');
        Schema::dropIfExists('production_batches');

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('batch_counter');
        });
    }
};
