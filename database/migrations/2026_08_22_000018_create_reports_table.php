<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['executive', 'sentiment', 'volume', 'custom'])->default('executive');
            $table->enum('format', ['pdf', 'csv', 'excel', 'json'])->default('pdf');
            $table->json('parameters')->nullable();
            $table->string('file_path')->nullable();
            $table->enum('status', ['pending', 'generating', 'completed', 'failed'])->default('pending')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
