# Daily Industry News Radar & AI Action Engine - API Documentation

> **Target Audience**: Frontend Engineers, Mobile Developers & AI Development Tools (Cursor, Copilot, v0, etc.)  
> **Module**: Daily Industry News Radar & Action Engine (رادار أخبار القطاع اليومي والمقترحات الذكية)  
> **Base URL**: `/api/v1`  
> **Authentication**: All endpoints require `Authorization: Bearer <TOKEN>` and `X-Tenant-ID: <TENANT_ID>` (or current authenticated tenant session).  
> **Schedule**: Runs automatically every 24 hours at **8:00 PM KSA Time (`Asia/Riyadh`)**.

---

## 1. Feature Overview & Core Concepts

The **Daily Industry News Radar** provides every tenant with a daily curated intelligence digest of the **Top 10 most impactful macro-industry news and regulatory developments** in Saudi Arabia and the MENA region.

### A. The Macro vs. Micro Rule (القاعدة الصارمة للرصد):
*  **Included (Macro Sector Environment):**
  * Royal decrees, ministerial appointments (e.g. appointment of a new Minister of Health, Vice Ministers, authority heads).
  * New laws, regulations, circulars, licensing conditions, and insurance policies (MOH, SFDA, SAMA, REGA, ZATCA).
  * Major sector investments, mergers, acquisitions, multi-billion infrastructure projects, national strategies (Vision 2030).
  * Major sector conferences and exhibitions (e.g., Global Health Exhibition, LEAP, Cityscape).
  * Sector-wide technology adoption (AI in medicine, automation).
* ❌ **Strictly Excluded (Micro Chatter & Noise):**
  * Individual patient or customer feedback, complaints, or compliments about a specific hospital/doctor.
  * Personal disputes, individual legal cases, viral social media gossip.
  * Unrelated general news.

### B. The 3 Actionable Suggestions per News Item:
For every single one of the 10 selected news items, the AI engine drafts **3 tailored actions**:
1. **`social_post` (منشور تواصل اجتماعي جاهز للنشر)**:
   * Recommended platforms: `LinkedIn`, `X`.
   * Complete, professional Arabic copy with a compelling hook, body copy, and hashtags ready to copy or post with one click.
2. **`operational_action` (إجراء تنظيمي وإداري داخلي)**:
   * Target department (e.g., Quality & Compliance, Legal, HR, Executive Management).
   * Urgency level: `high`, `medium`, or `low`.
   * Actionable checklist or instructions for staff.
3. **`marketing_opportunity` (فرصة تسويقية أو تجارية)**:
   * Campaign concept or service package capitalizing on the news momentum.

---

## 2. How the System Resolves the Tenant's Industry

The service resolves the tenant's field using a **4-tier priority cascade** (`IndustryNewsService::resolveTenantSector`):

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ Tier 1: Explicit Setting (الأولوية القصوى)                                   │
│ tenant.settings['industry'] (e.g., 'المستشفيات والرعاية الصحية')             │
└──────────────────────────────────────┬───────────────────────────────────────┘
                                       │ (if empty)
┌──────────────────────────────────────▼───────────────────────────────────────┐
│ Tier 2: Historical Monitored Articles                                        │
│ Check latest category from `articles.category`                               │
└──────────────────────────────────────┬───────────────────────────────────────┘
                                       │ (if empty)
┌──────────────────────────────────────▼───────────────────────────────────────┐
│ Tier 3: Semantic Name Deductions (استنتاج ذكي من اسم المنشأة)                │
│ Match keywords in tenant.name:                                               │
│  - "مستشف / صح / طب / علاج" ➔ الرعاية الصحية والمستشفيات                     │
│  - "تقني / برمج / حلول / سدايا" ➔ تقنية المعلومات والتحول الرقمي             │
│  - "عقار / بناء / إعمار" ➔ العقارات والتطوير العقاري                         │
│  - "تعليم / أكاديم / مدارس" ➔ التعليم والتدريب                               │
└──────────────────────────────────────┬───────────────────────────────────────┘
                                       │ (if empty)
┌──────────────────────────────────────▼───────────────────────────────────────┐
│ Tier 4: Safe Default Fallback                                                │
│ Default: 'الرعاية الصحية والمستشفيات'                                         │
└──────────────────────────────────────────────────────────────────────────────┘
```

> **How to customize**: Tenants can change their industry anytime by updating `industry` in `tenant.settings`.

---

## 3. Frontend UI / UX Implementation Guidelines

### A. Dashboard Card / Radar Page View:
* **Header**:
  * Date badge: `نشرة اليوم: 03 أكتوبر 2026 - تم التحديث في 8:00 مساءً`
  * Sector badge: `<Badge>قطاع الرعاية الصحية والمستشفيات</Badge>`
  * Refresh button: `[تحديث الرادار الآن]` (triggers `POST /api/v1/industry-news/trigger?sync=true`)
* **List of 10 Ranked News Cards**:
  * **Card Header**:
    * Rank pill: `#1`, `#2`, ... `#10`
    * Impact Score badge: `⭐ 9.5 / 10 - عالي الأهمية`
    * Category badge: `<Badge variant="outline">قرارات وتشريعات وزارية</Badge>`
    * Source & Time: `واس (وكالة الأنباء السعودية) • منذ 4 ساعات`
  * **Title & Link**:
    * Clean headline linking to the original source (`source_url`).
  * **"لماذا يهمنا؟" (Strategic Why It Matters)**:
    * Highlighted alert box explaining the strategic impact on the tenant.
  * **Interactive Action Tabs**:
    * **Tab 1: منشور السوشيال ميديا (Social Post)**:
      * Displays the hook and the pre-written Arabic post.
      * Platform icons: `LinkedIn`, `X`.
      * Button: `[نسخ المنشور بالكامل]` (Copies text + hashtags to clipboard).
      * Button: `[فتح في المستشار التسويقي الذكي]` (Opens AI chat with this post pre-loaded).
    * **Tab 2: الإجراء الداخلي (Operational Action)**:
      * Department badge (e.g. `إدارة الجودة والامتثال`).
      * Urgency pill: `🔴 عاجل` (`high`), `🟡 متوسط` (`medium`), `🟢 منخفض` (`low`).
      * Checklist instruction for the internal team.
    * **Tab 3: فرصة تسويقية (Marketing Opportunity)**:
      * Campaign type and promotional concept.
  * **Card Footer**:
    * Status action buttons:
      * `[تم اتخاذ إجراء ✓]` (`status = 'acted'`)
      * `[تجاهل ✕]` (`status = 'dismissed'`)

---

## 4. Database Schema Reference

### `tenant_industry_news` Table

| Column | Type | Nullable | Description |
| :--- | :--- | :---: | :--- |
| `id` | `bigint unsigned` | No | Primary Key |
| `tenant_id` | `bigint unsigned` | No | Foreign key referencing `tenants.id` (Cascades on delete) |
| `batch_date` | `date` | No | Batch date (YYYY-MM-DD), indexed |
| `rank` | `tinyint unsigned` | No | Daily rank from 1 to 10 |
| `title` | `varchar(500)` | No | News headline |
| `summary` | `text` | Yes | Concise news summary |
| `source_name` | `varchar(255)` | No | Media outlet name (واس, أرقام, الشرق بلومبرغ, etc.) |
| `source_url` | `text` | Yes | Direct canonical link to the news article |
| `published_at` | `timestamp` | Yes | Original publication timestamp |
| `category` | `varchar(100)` | Yes | Sector category (قرارات وزارية, استثمارات, مؤتمرات, تكنولوجيا) |
| `importance_score` | `decimal(4,2)`| No | Strategic impact rating (e.g. `9.50`) |
| `why_it_matters` | `text` | Yes | Strategic explanation tailored to the tenant |
| `suggested_actions`| `json` | Yes | JSON object containing `social_post`, `operational_action`, and `marketing_opportunity` |
| `status` | `enum` | No | `'unread'`, `'acted'`, or `'dismissed'` (Default: `'unread'`) |
| `created_at` | `timestamp` | Yes | Creation timestamp |
| `updated_at` | `timestamp` | Yes | Update timestamp |

---

## 5. API Endpoints Reference

### 1. Get Today's Top 10 Industry News Radar
Returns the top 10 ranked news items for today (or the most recent batch date if today's has not run yet).

* **Method**: `GET`
* **Endpoint**: `/api/v1/industry-news/today`
* **Headers**:
  ```http
  Authorization: Bearer <TOKEN>
  Accept: application/json
  ```

#### Response Example (`200 OK`):
```json
{
  "success": true,
  "message": "تم جلب رادار أخبار القطاع بنجاح",
  "data": {
    "batch_date": "2026-10-03",
    "is_today": true,
    "count": 10,
    "items": [
      {
        "id": 1,
        "tenant_id": 1,
        "batch_date": "2026-10-03",
        "rank": 1,
        "title": "صدور أمر ملكي بتعيين وزير صحة جديد وتحديث استراتيجية الرعاية الوقائية",
        "summary": "تضمن الأمر الملكي إعادة هيكلة لأولويات الرعاية الأولية والتحول الرقمي الصحي الشامل في المملكة.",
        "source_name": "واس - وكالة الأنباء السعودية",
        "source_url": "https://spa.gov.sa/viewstory.php?newsid=2849102",
        "published_at": "2026-10-03T14:30:00.000000Z",
        "category": "قرارات وتشريعات وزارية",
        "importance_score": "9.80",
        "why_it_matters": "تغيير القيادة العليا لوزارة الصحة يتبعه تسريع في اشتراطات التراخيص الوقائية والميكنة الرقمية، مما يمنح منشأتنا فرصة إبراز التزامها ومواءمتها مع التوجه الجديد.",
        "suggested_actions": {
          "social_post": {
            "recommended_platform": ["linkedin", "x"],
            "angle": "تهنئة رسمية ومواءمة استراتيجية",
            "hook": "نهنئ معالي وزير الصحة الجديد على الثقة الملكية الغالية! 🇸🇦✨",
            "body": "يتقدم مجلس إدارة وكافة منسوبي [اسم المنشأة] بخالص التهاني والتبريكات لمعالي... مؤكدين مواصلة التزامنا بدعم مستهدفات التحول الصحي ورؤية المملكة 2030 في تقديم أعلى معايير الرعاية الوقائية والحلول المبتكرة لخدمة ضيوفنا.",
            "hashtags": ["#وزارة_الصحة", "#التحول_الصحي", "#رؤية_السعودية_2030"]
          },
          "operational_action": {
            "department": "إدارة الجودة والامتثال",
            "urgency": "medium",
            "instruction": "مراجعة تقارير الامتثال الوقائي الداخلية للعيادات والتأكد من مطابقتها لأحدث أدلة وزارة الصحة الاسترشادية."
          },
          "marketing_opportunity": {
            "campaign_type": "حملة توعوية وترويجية موازية",
            "concept": "إطلاق باقة فحص شامل تحت شعار 'صحتك أولوية' لركوب موجة الاهتمام بالرعاية الوقائية التي أكد عليها القرار."
          }
        },
        "status": "unread",
        "created_at": "2026-10-03T17:00:00.000000Z",
        "updated_at": "2026-10-03T17:00:00.000000Z"
      }
    ]
  }
}
```

---

### 2. List News Archive (with Pagination & Filters)
Browse historical news archives by specific date, category, or status.

* **Method**: `GET`
* **Endpoint**: `/api/v1/industry-news`
* **Query Parameters**:
  * `date` (optional): Filter by date `YYYY-MM-DD`
  * `status` (optional): Filter by status (`unread`, `acted`, `dismissed`)
  * `category` (optional): Filter by category string
  * `per_page` (optional): Number of records per page (default: `15`)

#### Response Example (`200 OK`):
```json
{
  "success": true,
  "message": null,
  "data": {
    "current_page": 1,
    "data": [ ... ],
    "total": 50,
    "per_page": 15,
    "last_page": 4
  }
}
```

---

### 3. Get Single News Item Details
* **Method**: `GET`
* **Endpoint**: `/api/v1/industry-news/{id}`

#### Response Example (`200 OK`):
```json
{
  "success": true,
  "message": null,
  "data": {
    "id": 1,
    "rank": 1,
    "title": "...",
    "suggested_actions": { ... },
    "status": "unread"
  }
}
```

---

### 4. Update News Action Status
Update whether the tenant took action on this news recommendation or dismissed it.

* **Method**: `PATCH`
* **Endpoint**: `/api/v1/industry-news/{id}/status`
* **Body** (`application/json`):
  ```json
  {
    "status": "acted"
  }
  ```
  *(Allowed values: `unread`, `acted`, `dismissed`)*

#### Response Example (`200 OK`):
```json
{
  "success": true,
  "message": "تم تحديث حالة الخبر بنجاح",
  "data": {
    "id": 1,
    "status": "acted",
    "updated_at": "2026-10-03T17:05:12.000000Z"
  }
}
```

---

### 5. Trigger Real-Time Fetch (Manual Refresh)
Manually trigger the news ingestion and AI curation pipeline for the tenant without waiting for the 8:00 PM cron.

* **Method**: `POST`
* **Endpoint**: `/api/v1/industry-news/trigger`
* **Query Parameters**:
  * `sync=true` (optional): Runs synchronously and returns the freshly generated 10 items in the response immediately.
  * Omit `sync` to dispatch as an asynchronous background queue job (`FetchTenantIndustryNewsJob`).

#### Response Example (`200 OK` with `sync=true`):
```json
{
  "success": true,
  "message": "تم جلب وتحليل أهم أخبار القطاع وصياغة المقترحات بنجاح",
  "data": {
    "count": 10,
    "items": [ ... ]
  }
}
```

---

## 6. CLI Command & Cron Schedule

### Artisan Command:
```bash
# Run for all active tenants (queued):
php artisan sada:fetch-industry-news

# Run synchronously for a specific tenant:
php artisan sada:fetch-industry-news --tenant=1 --sync

# Run for a specific historical date:
php artisan sada:fetch-industry-news --tenant=1 --sync --date=2026-10-03
```

### Laravel Scheduler (`routes/console.php`):
```php
Schedule::command('sada:fetch-industry-news')
    ->dailyAt('20:00')
    ->timezone('Asia/Riyadh');
```

---

## 7. Deep Integration with AI Marketing Consultant Chat

When staff members chat with the AI Marketing Consultant in `/chats/{id}/messages` and ask marketing queries (e.g., *"ما هي خطتنا لمواكبة القرارات الجديدة اليوم؟"*), the context aggregator automatically includes `daily_industry_radar` with today's top news and suggested posts so the AI assistant can reference today's macro news seamlessly!
