<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('production_batches', function (Blueprint $table) {
            $table->string('completion_idempotency_key', 100)->nullable()->after('idempotency_key');
            $table->unique(['shop_id', 'completion_idempotency_key']);
        });
    }
    public function down(): void {
        Schema::table('production_batches', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'completion_idempotency_key']);
            $table->dropColumn('completion_idempotency_key');
        });
    }
};
