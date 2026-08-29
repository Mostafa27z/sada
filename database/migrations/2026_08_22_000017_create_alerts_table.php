<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_rule_id')->nullable()->constrained('alert_rules')->nullOnDelete();
            $table->foreignId('article_id')->nullable()->constrained('articles')->cascadeOnDelete();
            $table->string('title');
            $table->text('message');
            $table->enum('type', ['keyword_matched', 'negative_sentiment', 'volume_spike'])->default('keyword_matched')->index();
            $table->enum('status', ['unread', 'read', 'resolved'])->default('unread')->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
