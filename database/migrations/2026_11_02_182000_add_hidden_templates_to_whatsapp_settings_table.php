<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_settings') && !Schema::hasColumn('whatsapp_settings', 'hidden_gupshup_templates')) {
            Schema::table('whatsapp_settings', function (Blueprint $table) {
                $table->json('hidden_gupshup_templates')->nullable()->after('last_webhook_received_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('whatsapp_settings') && Schema::hasColumn('whatsapp_settings', 'hidden_gupshup_templates')) {
            Schema::table('whatsapp_settings', function (Blueprint $table) {
                $table->dropColumn('hidden_gupshup_templates');
            });
        }
    }
};

