<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_settings') && !Schema::hasColumn('whatsapp_settings', 'app_id')) {
            Schema::table('whatsapp_settings', function (Blueprint $table) {
                $table->string('app_id')->nullable()->after('tenant_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('whatsapp_settings') && Schema::hasColumn('whatsapp_settings', 'app_id')) {
            Schema::table('whatsapp_settings', function (Blueprint $table) {
                $table->dropColumn('app_id');
            });
        }
    }
};

