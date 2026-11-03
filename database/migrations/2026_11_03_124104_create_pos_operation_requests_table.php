<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_operation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->foreignId('actor_id')->constrained('users');
            $table->string('operation_key', 100);
            $table->string('request_hash', 64);
            $table->text('response_body')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('content_type')->nullable();
            $table->timestamps();
            $table->unique(['shop_id', 'operation_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_operation_requests');
    }
};
