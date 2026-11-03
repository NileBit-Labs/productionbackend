<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_batches', fn (Blueprint $table) => $table->unsignedInteger('revision')->default(1));
    }

    public function down(): void
    {
        Schema::table('production_batches', fn (Blueprint $table) => $table->dropColumn('revision'));
    }
};
