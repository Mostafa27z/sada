<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('trigger_type', ['keyword', 'sentiment', 'volume', 'all'])->default('all');
            $table->foreignId('keyword_id')->nullable()->constrained('keywords')->nullOnDelete();
            $table->enum('sentiment', ['positive', 'negative', 'neutral'])->nullable();
            $table->json('channels')->nullable();
            $table->json('recipients')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};
