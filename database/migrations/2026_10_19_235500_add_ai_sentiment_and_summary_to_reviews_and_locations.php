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
        Schema::table('location_reviews', function (Blueprint $table) {
            if (!Schema::hasColumn('location_reviews', 'sentiment')) {
                $table->string('sentiment', 20)->nullable()->after('rating');
            }
            if (!Schema::hasColumn('location_reviews', 'sentiment_score')) {
                $table->decimal('sentiment_score', 4, 2)->nullable()->after('sentiment');
            }
            if (!Schema::hasColumn('location_reviews', 'ai_analysis')) {
                $table->text('ai_analysis')->nullable()->after('sentiment_score');
            }
        });

        Schema::table('tenant_locations', function (Blueprint $table) {
            if (!Schema::hasColumn('tenant_locations', 'ai_summary')) {
                $table->text('ai_summary')->nullable()->after('unanswered_reviews');
            }
            if (!Schema::hasColumn('tenant_locations', 'ai_recommendations')) {
                $table->json('ai_recommendations')->nullable()->after('ai_summary');
            }
            if (!Schema::hasColumn('tenant_locations', 'ai_analyzed_at')) {
                $table->timestamp('ai_analyzed_at')->nullable()->after('ai_recommendations');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('location_reviews', function (Blueprint $table) {
            $table->dropColumn(['sentiment', 'sentiment_score', 'ai_analysis']);
        });

        Schema::table('tenant_locations', function (Blueprint $table) {
            $table->dropColumn(['ai_summary', 'ai_recommendations', 'ai_analyzed_at']);
        });
    }
};
