<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add trial_ends_at to tenants
        Schema::table('tenants', function (Blueprint $table) {
            if (!Schema::hasColumn('tenants', 'trial_ends_at')) {
                $table->timestamp('trial_ends_at')->nullable()->after('status');
            }
        });

        // 2. Add has_news and campaign limits to plans
        Schema::table('plans', function (Blueprint $table) {
            if (!Schema::hasColumn('plans', 'has_news')) {
                $table->boolean('has_news')->default(false)->after('features');
            }
            if (!Schema::hasColumn('plans', 'max_campaigns')) {
                $table->integer('max_campaigns')->default(5)->after('max_api_requests');
            }
            if (!Schema::hasColumn('plans', 'max_articles_per_campaign')) {
                $table->integer('max_articles_per_campaign')->default(25)->after('max_campaigns');
            }
        });

        // 3. Create plan_requests table
        if (!Schema::hasTable('plan_requests')) {
            Schema::create('plan_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->index();
                $table->text('notes')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_requests');

        Schema::table('plans', function (Blueprint $table) {
            if (Schema::hasColumn('plans', 'has_news')) {
                $table->dropColumn('has_news');
            }
            if (Schema::hasColumn('plans', 'max_campaigns')) {
                $table->dropColumn('max_campaigns');
            }
            if (Schema::hasColumn('plans', 'max_articles_per_campaign')) {
                $table->dropColumn('max_articles_per_campaign');
            }
        });

        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'trial_ends_at')) {
                $table->dropColumn('trial_ends_at');
            }
        });
    }
};
