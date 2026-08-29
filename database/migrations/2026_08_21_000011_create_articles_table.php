<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('sources')->nullOnDelete();
            $table->string('external_id')->nullable()->index();
            $table->string('title');
            $table->string('slug');
            $table->longText('content')->nullable();
            $table->text('summary')->nullable();
            $table->string('url');
            $table->string('image_url')->nullable();
            $table->string('author')->nullable();
            $table->string('language', 10)->default('ar')->index();
            $table->string('country', 10)->default('SA')->index();
            $table->string('category')->nullable()->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->enum('sentiment', ['positive', 'negative', 'neutral'])->nullable()->index();
            $table->decimal('sentiment_score', 5, 4)->nullable();
            $table->json('ai_metadata')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
