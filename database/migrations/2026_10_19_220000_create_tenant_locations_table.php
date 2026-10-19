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
        Schema::create('tenant_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('business_name');
            $table->string('address')->nullable();
            $table->text('maps_url');
            $table->string('google_place_id')->nullable()->index();
            $table->string('google_cid')->nullable();

            // Provider & Capabilities
            $table->string('provider', 20)->default('apify'); // 'gbp' or 'apify'
            $table->string('gbp_account_id')->nullable();
            $table->string('gbp_location_id')->nullable();
            $table->text('gbp_access_token')->nullable();
            $table->text('gbp_refresh_token')->nullable();
            $table->timestamp('gbp_token_expires_at')->nullable();
            $table->boolean('is_verified_owner')->default(false);
            $table->boolean('can_reply')->default(false);

            // Sync Status
            $table->string('sync_status', 30)->default('idle'); // idle, pending, syncing, completed, failed
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error_message')->nullable();

            // Cached Aggregated Stats
            $table->unsignedInteger('total_reviews')->default(0);
            $table->decimal('average_rating', 3, 1)->default(0.0);
            $table->json('rating_distribution')->nullable(); // {"1":0,"2":0,"3":0,"4":0,"5":0}
            $table->unsignedInteger('unanswered_reviews')->default(0);

            $table->timestamps();

            $table->index(['tenant_id', 'sync_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_locations');
    }
};
