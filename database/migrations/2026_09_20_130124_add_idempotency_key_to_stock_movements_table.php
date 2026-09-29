<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manual stock writes (adjustments, damage, loss, opening stock, purchases)
     * are retried by flaky connections and double-taps; a key makes the
     * repeat return the original movement instead of moving stock twice.
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable();
            $table->unique(['shop_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
