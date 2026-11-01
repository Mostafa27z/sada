<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Migrate legacy stage values to new standardized workflow
        DB::table('complaints')
            ->where('stage', 'investigating')
            ->update(['stage' => 'processing']);

        DB::table('complaints')
            ->where('stage', 'waiting_customer')
            ->update(['stage' => 'waiting_beneficiary']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('complaints')
            ->where('stage', 'processing')
            ->update(['stage' => 'investigating']);

        DB::table('complaints')
            ->where('stage', 'waiting_beneficiary')
            ->update(['stage' => 'waiting_customer']);
    }
};
