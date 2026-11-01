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
        // Unify status with stage across all complaint records
        DB::table('complaints')->update([
            'status' => DB::raw('stage')
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse needed as status and stage are logically unified
    }
};
