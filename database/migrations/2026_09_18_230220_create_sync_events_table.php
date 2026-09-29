<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per event a device has pushed. The unique key is what makes
     * pushing the same offline sale twice harmless.
     */
    public function up(): void
    {
        Schema::create('sync_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('device_id');
            $table->string('local_event_id');
            $table->string('entity_type');
            $table->string('operation');
            $table->string('payload_hash', 64);
            $table->string('status');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('result')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'device_id', 'local_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_events');
    }
};
