<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ask_queries', function (Blueprint $table) {
            $table->boolean('counts_toward_limit')->default(false)->after('error');
            $table->index(['shop_id', 'counts_toward_limit', 'created_at']);
        });

        // Preserve the historic meaning of completed Ask records on existing deployments.
        DB::table('ask_queries')->whereNull('error')->update(['counts_toward_limit' => true]);
    }

    public function down(): void
    {
        Schema::table('ask_queries', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'counts_toward_limit', 'created_at']);
            $table->dropColumn('counts_toward_limit');
        });
    }
};
