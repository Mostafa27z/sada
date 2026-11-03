<?php

use App\Support\PhoneNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('complaints', 'normalized_phone')) {
            Schema::table('complaints', function (Blueprint $table) {
                $table->string('normalized_phone', 32)->nullable()->after('phone');
                $table->index(['tenant_id', 'normalized_phone'], 'complaints_tenant_norm_phone_idx');
            });

            // Backfill existing complaints
            DB::table('complaints')
                ->whereNotNull('phone')
                ->orderBy('id')
                ->chunkById(200, function ($rows) {
                    foreach ($rows as $row) {
                        $norm = PhoneNormalizer::toE164($row->phone);
                        if ($norm) {
                            DB::table('complaints')
                                ->where('id', $row->id)
                                ->update(['normalized_phone' => $norm]);
                        }
                    }
                });
        }

        if (!Schema::hasTable('whatsapp_settings')) {
            Schema::create('whatsapp_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('app_id')->nullable();
                $table->string('app_name')->nullable();
                $table->text('api_key')->nullable();
                $table->string('source_phone', 32)->nullable();
                $table->string('default_country_code', 8)->default('966');
                $table->string('webhook_token', 64)->unique();
                $table->boolean('is_active')->default(false);
                $table->boolean('auto_send_on_stage_change')->default(true);
                $table->boolean('auto_send_on_new_complaint')->default(false);
                $table->timestamp('last_webhook_received_at')->nullable();
                $table->timestamps();

                $table->unique('tenant_id');
            });
        }

        if (!Schema::hasTable('whatsapp_stage_templates')) {
            Schema::create('whatsapp_stage_templates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('stage', 50);
                $table->boolean('is_enabled')->default(true);
                $table->string('dispatch_mode', 32)->default('session_text'); // 'session_text' | 'gupshup_template'
                $table->string('gupshup_template_id', 120)->nullable();
                $table->text('body_template');
                $table->json('variable_order')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'stage']);
            });
        }

        if (!Schema::hasTable('whatsapp_conversations')) {
            Schema::create('whatsapp_conversations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('normalized_phone', 32);
                $table->string('raw_phone', 50)->nullable();
                $table->string('contact_name')->nullable();
                $table->text('last_message_preview')->nullable();
                $table->timestamp('last_message_at')->nullable();
                $table->timestamp('last_inbound_at')->nullable();
                $table->unsignedInteger('unread_count')->default(0);
                $table->timestamps();

                $table->unique(['tenant_id', 'normalized_phone'], 'wa_conv_tenant_phone_unique');
                $table->index(['tenant_id', 'last_message_at']);
            });
        }

        if (!Schema::hasTable('whatsapp_messages')) {
            Schema::create('whatsapp_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
                $table->foreignId('complaint_id')->nullable()->constrained('complaints')->nullOnDelete();
                $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('gupshup_message_id', 128)->nullable()->index();
                $table->string('direction', 16); // 'inbound' | 'outbound'
                $table->string('message_type', 32)->default('text'); // 'text' | 'template' | 'image' | 'audio' | 'file'
                $table->string('triggered_by_stage', 50)->nullable();
                $table->text('body');
                $table->string('media_url')->nullable();
                $table->string('status', 32)->default('queued'); // 'queued' | 'sent' | 'delivered' | 'read' | 'failed'
                $table->text('error_message')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['conversation_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_conversations');
        Schema::dropIfExists('whatsapp_stage_templates');
        Schema::dropIfExists('whatsapp_settings');

        if (Schema::hasColumn('complaints', 'normalized_phone')) {
            Schema::table('complaints', function (Blueprint $table) {
                $table->dropIndex('complaints_tenant_norm_phone_idx');
                $table->dropColumn('normalized_phone');
            });
        }
    }
};

