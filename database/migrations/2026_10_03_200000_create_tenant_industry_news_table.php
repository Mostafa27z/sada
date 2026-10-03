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
        Schema::create('tenant_industry_news', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->date('batch_date')->index();
            $table->unsignedTinyInteger('rank')->default(1);
            $table->string('title', 500);
            $table->text('summary')->nullable();
            $table->string('source_name', 255)->default('أخبار القطاع');
            $table->text('source_url')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('category', 100)->nullable();
            $table->decimal('importance_score', 4, 2)->default(8.00);
            $table->text('why_it_matters')->nullable();
            $table->json('suggested_actions')->nullable();
            $table->enum('status', ['unread', 'acted', 'dismissed'])->default('unread')->index();
            $table->timestamps();

            $table->index(['tenant_id', 'batch_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_industry_news');
    }
};
