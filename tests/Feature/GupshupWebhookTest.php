<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Tenant;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Models\WhatsappSetting;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GupshupWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected WhatsappSetting $setting;
    protected string $token = 'test-gupshup-token-12345';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'domain' => 'test.localhost',
            'status' => 'active',
        ]);

        TenantContext::setTenant($this->tenant);

        $this->setting = WhatsappSetting::create([
            'tenant_id' => $this->tenant->id,
            'app_id' => 'test_app_id',
            'app_name' => 'test_app_name',
            'api_key' => 'test_api_key',
            'source_phone' => '966500000000',
            'default_country_code' => '966',
            'webhook_token' => $this->token,
            'is_active' => true,
        ]);

        TenantContext::forgetTenant();
    }

    public function test_get_ping_returns_ok_or_challenge(): void
    {
        $response = $this->getJson("/api/v1/webhooks/gupshup/{$this->token}");
        $response->assertStatus(200);
        $response->assertJson(['status' => 'ok', 'verified' => true]);

        $challengeResp = $this->get("/api/v1/webhooks/gupshup/{$this->token}?hub_challenge=my_challenge_code_99");
        $challengeResp->assertStatus(200);
        $this->assertEquals('my_challenge_code_99', $challengeResp->getContent());
    }

    public function test_meta_format_v3_inbound_message_creates_conversation_and_message(): void
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => '100652245234234',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '966500000000',
                                    'phone_number_id' => '1234567890',
                                ],
                                'contacts' => [
                                    [
                                        'profile' => [
                                            'name' => 'سارة الشمري',
                                        ],
                                        'wa_id' => '966551234567',
                                    ],
                                ],
                                'messages' => [
                                    [
                                        'from' => '966551234567',
                                        'id' => 'wamid.HBgLMjAyNjExMDQ=',
                                        'timestamp' => '1730748000',
                                        'type' => 'text',
                                        'text' => [
                                            'body' => 'أهلاً، أود الاستفسار عن تفاصيل البلاغ الطبي',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson("/api/v1/webhooks/gupshup/{$this->token}", $payload);
        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        $conversation = WhatsappConversation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('normalized_phone', '966551234567')
            ->first();

        $this->assertNotNull($conversation);
        $this->assertEquals('سارة الشمري', $conversation->contact_name);
        $this->assertEquals(1, $conversation->unread_count);

        $message = WhatsappMessage::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('conversation_id', $conversation->id)
            ->first();

        $this->assertNotNull($message);
        $this->assertEquals('inbound', $message->direction);
        $this->assertEquals('أهلاً، أود الاستفسار عن تفاصيل البلاغ الطبي', $message->body);
        $this->assertEquals('wamid.HBgLMjAyNjExMDQ=', $message->gupshup_message_id);
    }

    public function test_meta_format_v3_delivery_status_event_updates_message(): void
    {
        $conversation = WhatsappConversation::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'normalized_phone' => '966551234567',
            'raw_phone' => '0551234567',
            'contact_name' => 'سارة',
            'unread_count' => 0,
        ]);

        $message = WhatsappMessage::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'gupshup_message_id' => 'wamid.OUTBOUND_12345',
            'status' => 'sent',
            'body' => 'رسالة تجريبية',
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => '100652245234234',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'statuses' => [
                                    [
                                        'id' => 'wamid.OUTBOUND_12345',
                                        'status' => 'delivered',
                                        'timestamp' => '1730748010',
                                        'recipient_id' => '966551234567',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson("/api/v1/webhooks/gupshup/{$this->token}", $payload);
        $response->assertStatus(200);

        $this->assertEquals('delivered', $message->fresh()->status);
    }

    public function test_gupshup_format_v2_inbound_message(): void
    {
        $payload = [
            'app' => 'test_app_name',
            'timestamp' => 1730748000,
            'version' => 2,
            'type' => 'message',
            'payload' => [
                'id' => 'gs_msg_987654',
                'source' => '966509876543',
                'type' => 'text',
                'payload' => [
                    'text' => 'مرحباً عبر نسق جوب شوب القديم v2',
                ],
                'sender' => [
                    'phone' => '966509876543',
                    'name' => 'عبدالله',
                ],
            ],
        ];

        $response = $this->postJson("/api/v1/webhooks/gupshup/{$this->token}", $payload);
        $response->assertStatus(200);

        $conversation = WhatsappConversation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('normalized_phone', '966509876543')
            ->first();

        $this->assertNotNull($conversation);
        $this->assertEquals('عبدالله', $conversation->contact_name);

        $msg = WhatsappMessage::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('gupshup_message_id', 'gs_msg_987654')
            ->first();

        $this->assertNotNull($msg);
        $this->assertEquals('مرحباً عبر نسق جوب شوب القديم v2', $msg->body);
    }
}
