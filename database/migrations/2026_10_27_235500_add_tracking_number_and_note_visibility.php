<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->string('tracking_number')->nullable()->unique()->after('id');
        });

        Schema::table('complaint_activities', function (Blueprint $table) {
            $table->boolean('is_visible_to_customer')->default(false)->after('type');
        });

        // Generate tracking numbers for existing complaints
        $complaints = DB::table('complaints')->whereNull('tracking_number')->get();
        foreach ($complaints as $c) {
            $date = $c->created_at ? date('Ymd', strtotime($c->created_at)) : date('Ymd');
            $rand = mt_rand(1000, 9999);
            DB::table('complaints')->where('id', $c->id)->update([
                'tracking_number' => "{$date}_{$c->id}_{$rand}"
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('complaint_activities', function (Blueprint $table) {
            $table->dropColumn(['is_visible_to_customer']);
        });

        Schema::table('complaints', function (Blueprint $table) {
            $table->dropColumn(['tracking_number']);
        });
    }
};
