<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cashier_id')->constrained('users');
            $table->unsignedBigInteger('opening_cash');
            $table->bigInteger('expected_cash')->nullable();
            $table->unsignedBigInteger('actual_cash')->nullable();
            $table->bigInteger('variance')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->string('close_note')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'opened_at']);
        });

        // A cashier can only have one shift open at a time - enforced by the
        // database, so two simultaneous "open" taps can't create two.
        DB::statement('create unique index shifts_one_open_per_cashier on shifts (shop_id, cashier_id) where closed_at is null');
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
