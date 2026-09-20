<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_backups', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('disk')->default('local');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->enum('status', ['pending', 'completed', 'failed'])->default('completed');
            $table->string('triggered_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_backups');
    }
};
