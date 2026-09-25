<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamAiChatTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $adminUser;
    protected User $memberUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $this->adminUser = User::factory()->create([
            'current_tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        $this->adminUser->tenants()->attach($this->tenant->id);

        $this->memberUser = User::factory()->create([
            'current_tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        $this->memberUser->tenants()->attach($this->tenant->id);
    }

    public function test_admin_can_create_chat_room_and_assign_members(): void
    {
        Sanctum::actingAs($this->adminUser);

        $payload = [
            'title' => 'حملة التسويق الرمضانية',
            'description' => 'مناقشة خطة الحملة مع مستشار التسويق',
            'user_ids' => [$this->memberUser->id],
        ];

        $response = $this->withHeaders(['X-Tenant-ID' => $this->tenant->id])
            ->postJson('/api/v1/chats', $payload);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'data' => [
                'title' => 'حملة التسويق الرمضانية',
                'created_by' => $this->adminUser->id,
            ],
        ]);

        $this->assertDatabaseHas('chat_rooms', [
            'tenant_id' => $this->tenant->id,
            'title' => 'حملة التسويق الرمضانية',
            'created_by' => $this->adminUser->id,
        ]);

        $this->assertDatabaseHas('chat_room_users', [
            'user_id' => $this->memberUser->id,
        ]);
    }

    public function test_user_can_list_accessible_chat_rooms(): void
    {
        $room = ChatRoom::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->adminUser->id,
            'title' => 'غرفة التخطيط',
        ]);
        $room->users()->attach($this->adminUser->id, ['role' => 'admin']);
        $room->users()->attach($this->memberUser->id, ['role' => 'member']);

        Sanctum::actingAs($this->memberUser);

        $response = $this->withHeaders(['X-Tenant-ID' => $this->tenant->id])
            ->getJson('/api/v1/chats');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'title', 'created_by', 'members_count', 'created_at'],
            ],
        ]);
        $this->assertEquals(1, count($response->json('data')));
    }

    public function test_staff_can_send_regular_message_without_ai_trigger(): void
    {
        $room = ChatRoom::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->adminUser->id,
            'title' => 'غرفة التسويق',
        ]);
        $room->users()->attach($this->adminUser->id, ['role' => 'admin']);

        Sanctum::actingAs($this->adminUser);

        $response = $this->withHeaders(['X-Tenant-ID' => $this->tenant->id])
            ->postJson("/api/v1/chats/{$room->id}/messages", [
                'message' => 'السلام عليكم يا فريق، كيف تسير تجهيزات الحملة؟',
            ]);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'data' => [
                'user_message' => [
                    'message' => 'السلام عليكم يا فريق، كيف تسير تجهيزات الحملة؟',
                    'sender_type' => 'user',
                ],
                'ai_message' => null,
            ],
        ]);

        $this->assertDatabaseHas('chat_messages', [
            'chat_room_id' => $room->id,
            'user_id' => $this->adminUser->id,
            'sender_type' => 'user',
        ]);

        // Verify no AI message was created
        $this->assertEquals(1, ChatMessage::where('chat_room_id', $room->id)->count());
    }

    public function test_mentioning_ai_triggers_marketing_consultant_with_thinking_steps(): void
    {
        $room = ChatRoom::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->adminUser->id,
            'title' => 'غرفة استراتيجية العلامة',
            'is_ai_enabled' => true,
        ]);
        $room->users()->attach($this->adminUser->id, ['role' => 'admin']);

        Sanctum::actingAs($this->adminUser);

        $response = $this->withHeaders(['X-Tenant-ID' => $this->tenant->id])
            ->postJson("/api/v1/chats/{$room->id}/messages", [
                'message' => 'مرحباً @ai، بناءً على بيانات شركتنا والشكاوى الأخيرة، ما هي أفضل خطة تسويقية لمعالجة مخاوف العملاء؟',
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'user_message' => [
                    'id',
                    'sender_type',
                    'message',
                ],
                'ai_message' => [
                    'id',
                    'sender_type',
                    'message',
                    'thinking_steps' => [
                        '*' => ['step', 'title', 'detail', 'data_source'],
                    ],
                ],
            ],
        ]);

        $this->assertEquals('ai', $response->json('data.ai_message.sender_type'));
        $this->assertNotEmpty($response->json('data.ai_message.thinking_steps'));

        $this->assertDatabaseHas('chat_messages', [
            'chat_room_id' => $room->id,
            'sender_type' => 'ai',
        ]);
    }

    public function test_non_participant_cannot_access_or_send_in_room(): void
    {
        $otherUser = User::factory()->create([
            'current_tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        $otherUser->tenants()->attach($this->tenant->id);

        $room = ChatRoom::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->adminUser->id,
            'title' => 'غرفة خاصة',
        ]);
        $room->users()->attach($this->adminUser->id, ['role' => 'admin']);

        Sanctum::actingAs($otherUser);

        $response = $this->withHeaders(['X-Tenant-ID' => $this->tenant->id])
            ->getJson("/api/v1/chats/{$room->id}");

        $response->assertStatus(403);

        $sendResponse = $this->withHeaders(['X-Tenant-ID' => $this->tenant->id])
            ->postJson("/api/v1/chats/{$room->id}/messages", [
                'message' => 'هل يمكنني المشاركة؟',
            ]);

        $sendResponse->assertStatus(403);
    }

    public function test_can_add_and_remove_members(): void
    {
        $room = ChatRoom::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->adminUser->id,
            'title' => 'غرفة الإدارة',
        ]);
        $room->users()->attach($this->adminUser->id, ['role' => 'admin']);

        Sanctum::actingAs($this->adminUser);

        // Add member
        $addResponse = $this->withHeaders(['X-Tenant-ID' => $this->tenant->id])
            ->postJson("/api/v1/chats/{$room->id}/users", [
                'user_ids' => [$this->memberUser->id],
            ]);

        $addResponse->assertStatus(200);
        $this->assertTrue($room->fresh()->hasUser($this->memberUser->id));

        // Remove member
        $removeResponse = $this->withHeaders(['X-Tenant-ID' => $this->tenant->id])
            ->deleteJson("/api/v1/chats/{$room->id}/users/{$this->memberUser->id}");

        $removeResponse->assertStatus(200);
        $this->assertFalse($room->fresh()->hasUser($this->memberUser->id));
    }

    public function test_copywriting_prompt_fulfills_direct_command_without_complaint_bias(): void
    {
        $room = ChatRoom::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->adminUser->id,
            'title' => 'غرفة الحملات',
            'is_ai_enabled' => true,
        ]);
        $room->users()->attach($this->adminUser->id, ['role' => 'admin']);

        Sanctum::actingAs($this->adminUser);

        $response = $this->withHeaders(['X-Tenant-ID' => $this->tenant->id])
            ->postJson("/api/v1/chats/{$room->id}/messages", [
                'message' => '@ai ابعت لي صياغة منشور كامل للتسويق لمعجون اسنان سيجنال',
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'ai_message' => [
                    'id',
                    'message',
                    'thinking_steps' => [
                        '*' => ['step', 'title', 'detail', 'data_source'],
                    ],
                    'suggested_followups',
                ],
            ],
        ]);

        $aiMessage = $response->json('data.ai_message.message');
        // Verify it addresses the product/copywriting request
        $this->assertNotEmpty($aiMessage);
        $this->assertNotEmpty($response->json('data.ai_message.suggested_followups'));
    }
}
