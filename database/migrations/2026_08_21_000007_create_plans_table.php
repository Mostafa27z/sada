<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->string('currency')->default('SAR');
            $table->enum('billing_interval', ['monthly', 'yearly'])->default('monthly');
            $table->integer('max_users')->default(5);
            $table->integer('max_keywords')->default(20);
            $table->integer('max_sources')->default(50);
            $table->integer('max_articles')->default(10000);
            $table->integer('max_api_requests')->default(1000);
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
