<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Make maps_url nullable in tenant_locations so branches can exist solely for complaints/QR code
        Schema::table('tenant_locations', function (Blueprint $table) {
            $table->text('maps_url')->nullable()->change();
        });

        // 2. Add tenant_location_id to complaints table
        Schema::table('complaints', function (Blueprint $table) {
            $table->foreignId('tenant_location_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('tenant_locations')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_location_id');
        });

        Schema::table('tenant_locations', function (Blueprint $table) {
            $table->text('maps_url')->nullable(false)->change();
        });
    }
};
