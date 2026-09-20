<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_requests', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            $table->string('sector')->nullable();
            $table->string('contact_name');
            $table->string('contact_email');
            $table->string('contact_phone')->nullable();
            $table->foreignId('requested_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('commercial_register')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->index();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_requests');
    }
};
