<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('collections')) {
            Schema::table('collections', function (Blueprint $table) {
                if (!Schema::hasColumn('collections', 'date_from')) {
                    $table->string('date_from', 20)->nullable()->after('country');
                }
                if (!Schema::hasColumn('collections', 'date_to')) {
                    $table->string('date_to', 20)->nullable()->after('date_from');
                }
            });
        }

        if (Schema::hasTable('trends')) {
            Schema::table('trends', function (Blueprint $table) {
                if (!Schema::hasColumn('trends', 'date_from')) {
                    $table->string('date_from', 20)->nullable()->after('country');
                }
                if (!Schema::hasColumn('trends', 'date_to')) {
                    $table->string('date_to', 20)->nullable()->after('date_from');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('collections')) {
            Schema::table('collections', function (Blueprint $table) {
                $table->dropColumn(['date_from', 'date_to']);
            });
        }

        if (Schema::hasTable('trends')) {
            Schema::table('trends', function (Blueprint $table) {
                $table->dropColumn(['date_from', 'date_to']);
            });
        }
    }
};
