<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->unique(['shop_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'idempotency_key']);
            $table->dropColumn(['idempotency_key', 'request_hash']);
        });
    }
};
