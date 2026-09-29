<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('unit_name');
            $table->decimal('conversion_to_base_unit', 12, 3);
            $table->unsignedBigInteger('selling_price');
            $table->timestamps();

            $table->unique(['product_id', 'unit_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_units');
    }
};
