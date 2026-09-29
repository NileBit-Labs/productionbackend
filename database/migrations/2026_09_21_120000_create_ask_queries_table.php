<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every question put to "Ask Your Shop": who asked, what it looked at and what it cost in
     * tokens. It is how the daily limit is counted and how a wrong answer can be looked into.
     */
    public function up(): void
    {
        Schema::create('ask_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->text('question');
            $table->text('answer')->nullable();
            $table->json('tools')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('status', 20);
            $table->string('error', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['shop_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ask_queries');
    }
};
