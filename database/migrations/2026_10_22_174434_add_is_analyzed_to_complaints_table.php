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
        Schema::table('complaints', function (Blueprint $table) {
            $table->boolean('is_analyzed')->default(false)->after('status');
            $table->timestamp('analyzed_at')->nullable()->after('is_analyzed');

            $table->index(['tenant_id', 'is_analyzed']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'is_analyzed']);
            $table->dropColumn(['is_analyzed', 'analyzed_at']);
        });
    }
};
