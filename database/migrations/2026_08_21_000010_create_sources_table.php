<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->enum('type', ['rss', 'website', 'news', 'blog', 'social', 'tv', 'other'])->default('news');
            $table->string('url');
            $table->string('country', 10)->default('SA');
            $table->string('language', 10)->default('ar');
            $table->string('category')->nullable();
            $table->string('status')->default('active');
            $table->json('configuration')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_status')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
