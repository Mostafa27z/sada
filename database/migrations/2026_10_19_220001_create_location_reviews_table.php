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
        Schema::create('location_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tenant_location_id')->constrained('tenant_locations')->cascadeOnDelete();

            // Identifiers & Source
            $table->string('google_review_id')->index();
            $table->string('source', 20)->default('apify'); // 'gbp' or 'apify'

            // Review Author & Content
            $table->string('author_name');
            $table->text('author_photo_url')->nullable();
            $table->tinyInteger('rating'); // 1 - 5
            $table->text('text')->nullable();
            $table->dateTime('published_at');
            $table->string('relative_time_description')->nullable();
            $table->unsignedInteger('likes_count')->default(0);

            // Owner Reply
            $table->text('response_text')->nullable();
            $table->dateTime('response_published_at')->nullable();
            $table->boolean('is_responded')->default(false);

            $table->timestamps();

            // Enforce uniqueness per location to prevent duplicates
            $table->unique(['tenant_location_id', 'google_review_id']);
            $table->index(['tenant_location_id', 'published_at']);
            $table->index(['tenant_id', 'rating']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('location_reviews');
    }
};
