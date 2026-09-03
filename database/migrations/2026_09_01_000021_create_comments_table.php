<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->nullable()->constrained('articles')->cascadeOnDelete();
            $table->string('platform', 50)->default('other')->index();
            $table->string('author')->nullable();
            $table->text('comment_text');
            $table->enum('sentiment', ['positive', 'negative', 'neutral'])->nullable()->index();
            $table->decimal('sentiment_score', 5, 4)->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
