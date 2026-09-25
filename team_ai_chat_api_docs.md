# Team AI Group Chat with Marketing Consultant - API Documentation

> **Target Audience**: Frontend Engineers & AI Development Tools (Cursor, Copilot, v0, etc.)  
> **Module**: Team AI Group Chat & Marketing Consultant Agent  
> **Base URL**: `/api/v1`  
> **Authentication**: All endpoints require `Authorization: Bearer <TOKEN>` and `X-Tenant-ID: <TENANT_ID>`.

---

## 1. Feature Overview & Core Concepts

This module allows tenant staff members to collaborate in internal group chats with a virtual **AI Marketing Consultant ("مستشار التسويق الذكي")** participating in the room.

### Key Capabilities:
1. **Admin Control**:
   - Company admins/managers can create topic-specific chat rooms (e.g., "حملة اليوم الوطني", "استراتيجية تحسين السمعة").
   - Admins can assign specific staff members to each room, add new members, or remove existing members.
2. **Deep Tenant Data Access**:
   - The AI Marketing Consultant has direct read access to the tenant's live operational and marketing data:
     - **Customer Complaints & Ratings**: Customer dissatisfaction points, ratings distribution (1-5), and existing complaint summaries.
     - **Social Media Mentions & Comments**: Positive/negative sentiment across Instagram, Facebook, X, and TikTok.
     - **Market Trends**: Discovered topic trends, audience interest spikes, and viral master posts.
     - **Tracked Keywords**: Core keywords and brand monitors.
     - **Conversation History**: Previous messages in the room to maintain context.
3. **The `@ai` Trigger Mechanism**:
   - Staff members can converse normally with each other without triggering AI responses or incurring LLM costs.
   - **Whenever a user types `@ai` anywhere in their message** (e.g. `مرحباً @ai، بناءً على شكاوى العملاء الأخيرة كيف نصيغ الحملة القادمة؟`), the AI Marketing Consultant is automatically invoked and returns a reasoned response.
4. **Transparent Thinking Steps (Chain of Thought)**:
   - Every AI response contains an array of `thinking_steps`.
   - The frontend can render a collapsible accordion (e.g., "خطوات تفكير المستشار الذكي") showing what data was checked, what was deduced, and the final strategic advice.

---

## 2. Frontend UI / UX Guidance

### A. How to render Messages:
- If `sender_type === "user"`:
  - Display user avatar, `user.name`, timestamp, and message bubble aligned to the right.
- If `sender_type === "ai"`:
  - Display AI Consultant avatar (or robot icon), badge "مستشار التسويق الذكي", timestamp, and the AI message bubble aligned to the left.

### B. Rendering AI "Thinking Steps" Accordion:
Inside any message where `sender_type === "ai"` and `thinking_steps` has items:
1. Display a collapsible card above the main response text:
   - Header: `🧠 خطوات تفكير المستشار الذكي (انقر للعرض)`
2. When expanded, render each step sequentially:
   - **Step Number & Title**: e.g., `خطوة 1: استرجاع شكاوى العملاء ومؤشرات الرضا`
   - **Data Source Badge**: e.g., `<Badge variant="outline">سجلات الشكاوى والتقييمات</Badge>`
   - **Reasoning Detail**: e.g., `تم فحص 10 شكاوى حديثة، ومتوسط التقييم 2.4/5، مع تركز الاستياء حول تأخر التوصيل.`
3. Below the thinking steps, render the final `message` (supports Markdown).

---

## 3. Database Schema Reference

### `chat_rooms` table
- `id` (int): Primary key
- `tenant_id` (int): Foreign key to `tenants`
- `created_by` (int): User ID who created the room
- `title` (string): Room title
- `description` (text, nullable): Room description
- `is_ai_enabled` (boolean): Default `true`
- `ai_consultant_name` (string): Default `'مستشار التسويق الذكي'`
- `created_at`, `updated_at` (timestamps)

### `chat_room_users` table
- `chat_room_id` (int): Foreign key to `chat_rooms`
- `user_id` (int): Foreign key to `users`
- `role` (string): `'admin'` or `'member'`
- `created_at`, `updated_at` (timestamps)

### `chat_messages` table
- `id` (int): Primary key
- `chat_room_id` (int): Foreign key to `chat_rooms`
- `user_id` (int, nullable): Sender user ID (`null` for AI messages)
- `sender_type` (string): `'user'` or `'ai'`
- `message` (text): Message text
- `thinking_steps` (json, nullable): Array of reasoning steps
- `metadata` (json, nullable): Referenced sources, confidence score
- `created_at`, `updated_at` (timestamps)

---

## 4. API Endpoints Specification

### 1. List User's Chat Rooms
Returns all chat rooms that the authenticated user belongs to within the active tenant.

- **Endpoint**: `GET /api/v1/chats`
- **Headers**:
  - `Authorization: Bearer <TOKEN>`
  - `X-Tenant-ID: <TENANT_ID>`
- **Response** (`200 OK`):
```json
{
  "success": true,
  "message": null,
  "data": [
    {
      "id": 1,
      "tenant_id": 1,
      "title": "حملة اليوم الوطني والتسويق الرقمي",
      "description": "مناقشة خطة الحملة ومراجعة ردود الفعل مع مستشار التسويق",
      "is_ai_enabled": true,
      "ai_consultant_name": "مستشار التسويق الذكي",
      "created_by": 5,
      "members_count": 4,
      "latest_message": {
        "id": 12,
        "sender_type": "ai",
        "message": "بناءً على شكاوى العملاء الأخيرة، أقترح التركيز على ضمان سرعة التوصيل في إعلانات الحملة...",
        "created_at": "2026-09-25T21:00:00.000000Z"
      },
      "created_at": "2026-09-25T20:00:00.000000Z",
      "updated_at": "2026-09-25T21:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 1
  }
}
```

---

### 2. Create Chat Room (Admin)
Creates a new group chat room and assigns designated staff members.

- **Endpoint**: `POST /api/v1/chats`
- **Request Body** (`application/json`):
```json
{
  "title": "حملة إطلاق المنتج الجديد",
  "description": "غرفة مخصصة للتخطيط مع فريق التسويق والمستشار الذكي",
  "is_ai_enabled": true,
  "ai_consultant_name": "مستشار التسويق الذكي",
  "user_ids": [2, 7, 9]
}
```
- **Response** (`201 Created`):
```json
{
  "success": true,
  "message": "تم إنشاء غرفة المحادثة بنجاح",
  "data": {
    "id": 2,
    "tenant_id": 1,
    "title": "حملة إطلاق المنتج الجديد",
    "description": "غرفة مخصصة للتخطيط مع فريق التسويق والمستشار الذكي",
    "is_ai_enabled": true,
    "ai_consultant_name": "مستشار التسويق الذكي",
    "created_by": 1,
    "members_count": 4,
    "members": [
      { "id": 1, "name": "أحمد المدير", "email": "ahmed@example.com", "role": "admin" },
      { "id": 2, "name": "سارة مسؤولة الإعلانات", "email": "sara@example.com", "role": "member" }
    ],
    "created_at": "2026-09-25T21:10:00.000000Z"
  }
}
```

---

### 3. Get Chat Room Details & Members
- **Endpoint**: `GET /api/v1/chats/{id}`
- **Response** (`200 OK`):
```json
{
  "success": true,
  "data": {
    "id": 1,
    "title": "حملة اليوم الوطني والتسويق الرقمي",
    "description": "مناقشة خطة الحملة",
    "is_ai_enabled": true,
    "ai_consultant_name": "مستشار التسويق الذكي",
    "created_by": 1,
    "creator": {
      "id": 1,
      "name": "أحمد المدير",
      "email": "ahmed@example.com"
    },
    "members_count": 3,
    "members": [
      { "id": 1, "name": "أحمد المدير", "email": "ahmed@example.com", "role": "admin" },
      { "id": 2, "name": "سارة مسؤولة الإعلانات", "email": "sara@example.com", "role": "member" }
    ]
  }
}
```

---

### 4. Update Chat Room
- **Endpoint**: `PUT /api/v1/chats/{id}`
- **Request Body**:
```json
{
  "title": "العنوان الجديد",
  "description": "الوصف المحدث",
  "is_ai_enabled": true
}
```

---

### 5. Delete Chat Room
- **Endpoint**: `DELETE /api/v1/chats/{id}`
- **Response** (`200 OK`):
```json
{
  "success": true,
  "message": "تم حذف غرفة المحادثة بنجاح",
  "data": null
}
```

---

### 6. Add Members to Chat Room
- **Endpoint**: `POST /api/v1/chats/{id}/users`
- **Request Body**:
```json
{
  "user_ids": [4, 8]
}
```

---

### 7. Remove Member from Chat Room
- **Endpoint**: `DELETE /api/v1/chats/{id}/users/{userId}`

---

### 8. List Room Messages (Chat History)
Retrieves paginated messages ordered from newest to oldest.

- **Endpoint**: `GET /api/v1/chats/{id}/messages?per_page=30`
- **Response** (`200 OK`):
```json
{
  "success": true,
  "data": [
    {
      "id": 25,
      "chat_room_id": 1,
      "sender_type": "ai",
      "user": null,
      "message": "بناءً على فحص 12 شكوى حديثة تفيد ببطء وصول أكواد التفعيل، نقترح إطلاق حملة اعتذار وتوضيح مدعومة بخصم 15% للعملاء المتأثرين...",
      "thinking_steps": [
        {
          "step": 1,
          "title": "استرجاع شكاوى وتقييمات العملاء",
          "detail": "تم فحص أحدث الشكاوى المسجلة، حيث انخفض متوسط التقييم إلى 2.8/5 بسبب تأخر وصول الرسائل التأكيدية.",
          "data_source": "سجلات الشكاوى والتقييمات"
        },
        {
          "step": 2,
          "title": "مراجعة ردود السوشيال ميديا الحالية",
          "detail": "رصد تعليقات سلبية بنسبة 45% على منصة X حول خدمة الدعم الفني.",
          "data_source": "تعليقات منصات التواصل"
        },
        {
          "step": 3,
          "title": "صياغة التوجيه التسويقي",
          "detail": "ربط الحل بإطلاق مبادرة شفافية وعرض ترويجي لتعويض المتضررين وبناء ولاء إيجابي.",
          "data_source": "استراتيجية التسويق والنمو"
        }
      ],
      "metadata": {
        "primary_concern": "تأخر وصول الرسائل",
        "recommended_channel": "البريد الإلكتروني ومنصة X",
        "confidence_score": 0.94
      },
      "created_at": "2026-09-25T21:15:30.000000Z"
    },
    {
      "id": 24,
      "chat_room_id": 1,
      "sender_type": "user",
      "user": {
        "id": 2,
        "name": "سارة مسؤولة الإعلانات",
        "email": "sara@example.com"
      },
      "message": "يا جماعة @ai لاحظت شكاوى متكررة اليوم، كيف نوجه الحملة الإعلانية القادمة لمعالجة هذا الأمر؟",
      "thinking_steps": [],
      "metadata": null,
      "created_at": "2026-09-25T21:15:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 30,
    "total": 2
  }
}
```

---

### 9. Send Message (Staff or `@ai` Mention)
Sends a message into the chat room.

> [!IMPORTANT]
> **Triggering AI**:
> - If the `message` string contains `@ai` (case-insensitive, e.g. `@ai`, `@AI`), the backend automatically invokes the **Marketing Consultant AI** using the tenant's live data.
> - The response returns both the `user_message` and the newly created `ai_message` containing the `thinking_steps`.
> - If `@ai` is **not** present, `ai_message` will be `null`, functioning as normal peer-to-peer staff chat.

- **Endpoint**: `POST /api/v1/chats/{id}/messages`
- **Request Body** (`application/json`):
```json
{
  "message": "مرحباً @ai، ما هي أفضل القنوات التسويقية لزيادة تفاعل الجمهور بناءً على تريندات السوق الحالية؟"
}
```

- **Response when `@ai` is mentioned** (`201 Created`):
```json
{
  "success": true,
  "message": "تم إرسال الرسالة بنجاح",
  "data": {
    "user_message": {
      "id": 30,
      "chat_room_id": 1,
      "sender_type": "user",
      "user": {
        "id": 1,
        "name": "أحمد المدير",
        "email": "ahmed@example.com"
      },
      "message": "مرحباً @ai، ما هي أفضل القنوات التسويقية لزيادة تفاعل الجمهور بناءً على تريندات السوق الحالية؟",
      "thinking_steps": [],
      "metadata": null,
      "created_at": "2026-09-25T21:20:00.000000Z"
    },
    "ai_message": {
      "id": 31,
      "chat_room_id": 1,
      "sender_type": "ai",
      "user": null,
      "message": "بناءً على مراجعة اتجاهات السوق وتريندات المحتوى الحديثة لشركتنا، نجد أن منصتي TikTok و Instagram تشهدان أعلى معدل تفاعل (Engagement Rate) مع الفيديوهات القصيرة...\n\n### خطة العمل المقترحة:\n1. **إطلاق محتوى تفاعلي قصير** (Short-form Video) يسلط الضوء على قصص نجاح العملاء.\n2. **الاستفادة من الكلمات المفتاحية النشطة** لزيادة الظهور العضوي.\n3. **عقد جلسة بث مباشر (Live Q&A)** للإجابة على استفسارات الجمهور وبناء علاقة قوية.",
      "thinking_steps": [
        {
          "step": 1,
          "title": "فحص اتجاهات السوق وتريندات المحتوى",
          "detail": "تمت مراجعة التريندات المرصودة للشركة، ولوحظ ارتفاع ملحوظ في تفاعل الجمهور مع مقاطع الفيديو السريعة بنسبة 35%.",
          "data_source": "تريندات السوق والمحتوى"
        },
        {
          "step": 2,
          "title": "تحليل أداء الكلمات المفتاحية وقنوات النشر",
          "detail": "تم فحص الكلمات المفتاحية النشطة وتبين تركيز تفاعل المتابعين في منصات الفيديو الترفيهية.",
          "data_source": "الكلمات المفتاحية النشطة"
        },
        {
          "step": 3,
          "title": "صياغة الاستشارة التسويقية وتحديد القنوات",
          "detail": "إعداد خطة تنفيذية فورية تركز على المحتوى القصير والبث المباشر لتحقيق أقصى وصول.",
          "data_source": "استراتيجية التسويق والنمو"
        }
      ],
      "metadata": {
        "primary_concern": "زيادة تفاعل الجمهور",
        "recommended_channel": "TikTok & Instagram",
        "confidence_score": 0.95
      },
      "created_at": "2026-09-25T21:20:15.000000Z"
    }
  }
}
```

- **Response when staff chat normally without `@ai`** (`201 Created`):
```json
{
  "success": true,
  "message": "تم إرسال الرسالة بنجاح",
  "data": {
    "user_message": {
      "id": 32,
      "chat_room_id": 1,
      "sender_type": "user",
      "user": {
        "id": 1,
        "name": "أحمد المدير",
        "email": "ahmed@example.com"
      },
      "message": "تمام يا فريق، سنبدأ الاجتماع خلال 10 دقائق.",
      "thinking_steps": [],
      "metadata": null,
      "created_at": "2026-09-25T21:22:00.000000Z"
    },
    "ai_message": null
  }
}
```

---

## 5. Ready-to-Test cURL Examples

### 1. Create a Chat Room with Members
```bash
curl -X POST "http://localhost:8000/api/v1/chats" \
     -H "Authorization: Bearer YOUR_SANCTUM_TOKEN" \
     -H "X-Tenant-ID: 1" \
     -H "Content-Type: application/json" \
     -H "Accept: application/json" \
     -d '{
           "title": "حملة تحسين الصورة الذهنية",
           "description": "غرفة عمل تسويقية مع مستشار التسويق الذكي",
           "user_ids": [2, 3]
         }'
```

### 2. Send Normal Message Between Staff
```bash
curl -X POST "http://localhost:8000/api/v1/chats/1/messages" \
     -H "Authorization: Bearer YOUR_SANCTUM_TOKEN" \
     -H "X-Tenant-ID: 1" \
     -H "Content-Type: application/json" \
     -H "Accept: application/json" \
     -d '{
           "message": "السلام عليكم، هل بدأتم بمراجعة تصاميم الحملة؟"
         }'
```

### 3. Trigger AI Marketing Consultant via `@ai` Mention
```bash
curl -X POST "http://localhost:8000/api/v1/chats/1/messages" \
     -H "Authorization: Bearer YOUR_SANCTUM_TOKEN" \
     -H "X-Tenant-ID: 1" \
     -H "Content-Type: application/json" \
     -H "Accept: application/json" \
     -d '{
           "message": "مرحباً @ai، بالنظر إلى بيانات الشكاوى والتريندات لشركتنا، كيف نقوم بصياغة الرسائل التسويقية للحملة القادمة؟"
         }'
```

### 4. Fetch Chat History with AI Thinking Steps
```bash
curl -X GET "http://localhost:8000/api/v1/chats/1/messages?per_page=30" \
     -H "Authorization: Bearer YOUR_SANCTUM_TOKEN" \
     -H "X-Tenant-ID: 1" \
     -H "Accept: application/json"
```

### 5. Add Members to Room
```bash
curl -X POST "http://localhost:8000/api/v1/chats/1/users" \
     -H "Authorization: Bearer YOUR_SANCTUM_TOKEN" \
     -H "X-Tenant-ID: 1" \
     -H "Content-Type: application/json" \
     -H "Accept: application/json" \
     -d '{
           "user_ids": [4]
         }'
```
