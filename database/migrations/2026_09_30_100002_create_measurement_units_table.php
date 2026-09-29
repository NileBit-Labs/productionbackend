<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurement_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('symbol', 20);
            // mass, volume, count or other: lets the screen group units sensibly.
            $table->string('dimension', 20)->default('other');
            $table->timestamps();

            $table->unique(['shop_id', 'symbol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('measurement_units');
    }
};
