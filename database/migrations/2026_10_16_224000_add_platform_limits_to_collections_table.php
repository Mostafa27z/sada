<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('collections') && !Schema::hasColumn('collections', 'platform_limits')) {
            Schema::table('collections', function (Blueprint $table) {
                $table->json('platform_limits')->nullable()->after('comments_limit');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('collections') && Schema::hasColumn('collections', 'platform_limits')) {
            Schema::table('collections', function (Blueprint $table) {
                $table->dropColumn('platform_limits');
            });
        }
    }
};
