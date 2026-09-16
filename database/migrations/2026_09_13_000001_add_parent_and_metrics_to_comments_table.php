<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('article_id')->constrained('comments')->cascadeOnDelete();
            $table->string('external_id')->nullable()->index()->after('platform');
            $table->string('parent_external_id')->nullable()->index()->after('external_id');
            $table->unsignedInteger('likes_count')->default(0)->after('sentiment_score');
            $table->unsignedInteger('replies_count')->default(0)->after('likes_count');
            $table->timestamp('comment_created_at')->nullable()->after('replies_count');
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn([
                'parent_id',
                'external_id',
                'parent_external_id',
                'likes_count',
                'replies_count',
                'comment_created_at',
            ]);
        });
    }
};
