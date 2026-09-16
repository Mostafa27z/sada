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
        Schema::create('auth_verification_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('code', 10);
            $table->string('type', 30)->default('password_reset'); // password_reset, email_verification
            $table->string('token', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auth_verification_codes');
    }
};
