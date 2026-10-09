# دليل تكامل الواجهة الأمامية: تحديثات نظام الباقات، الفترة التجريبية، وميزة الأخبار
# Frontend Integration Guide: Plans, 5-Day Trial, & News Feature

هذا التوثيق موجه لمطور/وكيل الذكاء الاصطناعي الخاص بالواجهة الأمامية (**Frontend AI Agent**) لتحديث شاشات وتدفقات التطبيق وفقاً للتعديلات البرمجية الجديدة في الواجهة الخلفية (**Backend**).

---

## 1. ملخص التعديلات المعمارية والمنطقية (Overview)

1. **تسجيل الشركات الجديدة**:
   * عند قيام العميل بتسجيل شركة جديدة عبر `/api/v1/auth/register`، يتم إنشاء الحساب والشركة بحالة **`suspended` (معلق)** في انتظار مراجعة وتدقيق الإدارة (**Super Admin**).
   * لا تبدأ الفترة التجريبية ولا تعمل محركات الذكاء الاصطناعي فور التسجيل.
2. **موافقة السوبر أدمن وتفعيل الفترة التجريبية (5 أيام)**:
   * يقوم السوبر أدمن بمراجعة الشركة والموافقة عليها عبر `/api/v1/admin/tenants/{id}/approve`.
   * تتحول الشركة إلى **`status = 'trial'`** ويتم ضبط تاريخ انتهاء التجربة **`trial_ends_at = 5 أيام`**.
   * يتم تفعيل محركات الرصد بالذكاء الاصطناعي تلقائياً فور الموافقة.
3. **قيود الفترة التجريبية (Trial Limits)**:
   * **الحد الأقصى للحملات**: **5 حملات كحد أقصى** (`max_campaigns = 5`).
   * **الحد الأقصى للردود والمقالات لكل حملة**: **25 فقط** (`comments_limit = 25` و `max_articles_per_campaign = 25`).
   * **انتهاء الـ 5 أيام**: عند انتهاء الفترة التجريبية، يتم حظر العمليات الإنشائية وإرجاع خطأ `403` مع الرمز `trial_expired` لمطالبة العميل باختيار باقة مدفوعة.
4. **نظام طلب الباقات والموافقة عليها (Plan Requests)**:
   * يستطيع العميل في مساحة العمل طلب ترقية/تغيير الباقة عبر `/api/v1/plans/request`.
   * تظهر الطلبات للسوبر أدمن لمراجعتها والموافقة عليها أو رفضها.
   * عند موافقة السوبر أدمن، تتحول الشركة إلى **`active`** (خروج من التجربة) وتفعل الباقة وحدودها الجديدة.
5. **ميزة رادار الأخبار اليومية بالذكاء الاصطناعي (Premium News Feature)**:
   * أصبحت ميزة الأخبار (`/api/v1/industry-news/*`) ميزة مدفوعة مشروطة بقيمة **`has_news: boolean`** في الباقة.
   * إذا كانت الباقة لا تتضمن الأخبار (`has_news = false` أو حساب تجريبي بدون أخبار)، يُرجع الخادم خطأ `403` مع الرمز `feature_not_included`.

---

## 2. النماذج وأنواع البيانات (TypeScript Types & Interfaces)

```typescript
// حالة مساحة العمل
export type TenantStatus = 'suspended' | 'trial' | 'active' | 'cancelled';

// الباقات
export interface SaaSPlan {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  price: number;
  currency: string;
  billing_interval: 'monthly' | 'yearly';
  limits: {
    max_users: number;
    max_keywords: number;
    max_sources: number;
    max_articles: number;
    max_api_requests: number;
    max_campaigns: number;            // جديد
    max_articles_per_campaign: number;// جديد
  };
  features: string[];
  has_news: boolean;                  // جديد: هل تتضمن رادار الأخبار
  is_active: boolean;
}

// مساحة العمل (Tenant)
export interface TenantWorkspace {
  id: number;
  ulid: string;
  name: string;
  slug: string;
  logo: string | null;
  status: TenantStatus;
  plan_id: number | null;
  trial_ends_at: string | null;       // جديد: تاريخ انتهاء التجربة ISO string
  trial_days_remaining?: number;
  plan?: SaaSPlan;
}

// طلبات الباقات (Plan Request)
export interface PlanRequestItem {
  id: number;
  tenant_id: number;
  plan_id: number;
  user_id: number;
  status: 'pending' | 'approved' | 'rejected';
  notes: string | null;
  rejection_reason: string | null;
  created_at: string;
  reviewed_at: string | null;
  tenant?: {
    id: number;
    name: string;
    slug: string;
  };
  plan?: SaaSPlan;
  user?: {
    id: number;
    name: string;
    email: string;
  };
  reviewer?: {
    id: number;
    name: string;
    email: string;
  };
}
```

---

## 3. التدفقات البرمجية وشاشات الواجهة (UI/UX Flows)

### Flow 1: تسجيل شركة جديدة (Company Registration)
* **Endpoint**: `POST /api/v1/auth/register`
* **Response (HTTP 201)**:
  ```json
  {
    "success": true,
    "message": "تم تسجيل شركتك بنجاح، والحساب معلق حالياً بانتظار مراجعة وتفعيل الإدارة.",
    "data": {
      "user": { "id": 10, "name": "أحمد", "status": "suspended" },
      "token": "...",
      "tenant": { "id": 5, "name": "شركة التجربة", "status": "suspended" }
    }
  }
  ```
* **تحديث الواجهة**:
  * لا يتم تحويل المستخدم تلقائياً للوحة التحكم.
  * يتم توجيهه إلى صفحة: **"حسابك قيد المراجعة" (Pending Approval Screen)**.
  * تتضمن رسالة: *"تم استلام طلب تسجيل شركتك بنجاح. سيقوم فريق الإدارة بمراجعة الحساب وتفعيل الفترة التجريبية خلال وقت قصير. ستصلك رسالة عبر البريد الإلكتروني فور الاعتماد."*

---

### Flow 2: تسجيل الدخول لحساب معلق (Login Handling)
* **Endpoint**: `POST /api/v1/auth/login`
* إذا كان الحساب أو الشركة معلقة، يرجع الخادم خطأ `422`:
  ```json
  {
    "message": "فشل التحقق من البيانات.",
    "errors": {
      "email": ["حساب شركتك معلق حالياً في انتظار مراجعة وتفعيل الإدارة."]
    }
  }
  ```
* **تحديث الواجهة**: إظهار رسالة التنبيه بوضوح في شاشة تسجيل الدخول مع رابط للتواصل مع الدعم الفني.

---

### Flow 3: لوحة تحكم السوبر أدمن (Super Admin Dashboard)

#### أ. تبويب مراجعة واعتماد الشركات:
1. **فلترة الشركات المعلقة**:
   * `GET /api/v1/admin/tenants?status=suspended`
   * عرض الشركات مع تفاصيل التواصل، القطاع، والسجل التجاري.
2. **الموافقة وتفعيل التجربة (5 أيام)**:
   * **Endpoint**: `POST /api/v1/admin/tenants/{id}/approve`
   * **Headers**: `Authorization: Bearer <super_admin_token>`
   * **Response (HTTP 200)**:
     ```json
     {
       "success": true,
       "message": "تمت مراجعة الشركة وتفعيل فترة تجريبية مدتها 5 أيام بنجاح.",
       "data": {
         "id": 5,
         "name": "شركة صدى",
         "status": "trial",
         "trial_ends_at": "2026-10-13T21:30:00.000000Z"
       }
     }
     ```
3. **رفض التسجيل**:
   * **Endpoint**: `POST /api/v1/admin/tenants/{id}/reject`
   * **Payload**:
     ```json
     { "reason": "البيانات المدخلة غير مكتملة" }
     ```

#### ب. تبويب طلبات ترقية الباقات (Plan Requests):
1. **عرض الطلبات**:
   * `GET /api/v1/admin/plan-requests?status=pending`
   * خيارات فلترة: `pending`, `approved`, `rejected`, `all`.
2. **اعتماد طلب الباقة للعميل**:
   * `POST /api/v1/admin/plan-requests/{id}/approve`
   * النتيجة: يتم ترقية الشركة وتفعيل باقتها وخروجها من الفترة التجريبية إلى حالة `active`.
3. **رفض طلب الباقة**:
   * `POST /api/v1/admin/plan-requests/{id}/reject`
   * **Payload**:
     ```json
     { "rejection_reason": "يرجى سداد الفاتورة أولاً عبر التحويل البنكي" }
     ```

#### ج. إدارة الباقات (Plans Management):
* في شاشات إضافة وتعديل الباقات (`POST /api/v1/plans` و `PUT /api/v1/plans/{id}`):
  * إضافة حقل Switch: **"تضمين رادار الأخبار اليومي بالذكاء الاصطناعي" (`has_news: boolean`)**.
  * إضافة حقول الأرقام:
    * **أقصى عدد للحملات (`max_campaigns`)**.
    * **أقصى عدد للردود لكل حملة (`max_articles_per_campaign`)**.

---

### Flow 4: مساحة عمل العميل والفترة التجريبية (Client Workspace)

#### أ. شريط التنبيه العلوي (Trial Countdown Banner):
* إذا كانت الشركة في فترة تجريبية (`tenant.status === 'trial'`):
  * إظهار شريط علوي بارز:
    > ⏳ **أنت حالياً في الفترة التجريبية (متبقي X أيام)** — يمكنك إنشاء **5 حملات كحد أقصى** (مع 25 رد أو مقال لكل حملة). [ترقية الباقة الآن]

#### ب. إنشاء الحملات (Campaigns Quota):
* **Endpoint**: `POST /api/v1/collections`
* **السلوك**:
  * في الفترة التجريبية، يتم تلقائياً ضبط `comments_limit = 25` كحد أقصى.
  * عند محاولة إنشاء الحملة رقم 6 في التجربة، يُرجع الـ Backend خطأ:
    ```json
    {
      "success": false,
      "message": "لقد وصلت إلى الحد الأقصى للحملات في الفترة التجريبية (5 حملات كحد أقصى)."
    }
    ```
  * **تحديث الواجهة**: تعطيل زر "إنشاء حملة جديدة" وإظهار عداد: `الحملات المستهلكة: 5 / 5`، مع فتح نافذة الترقية فور محاولة الإضافة.

#### ج. معالجة انتهاء التجربة (Trial Expiration):
* عند مرور الـ 5 أيام، أي طلب تعديل/إنشاء (POST/PUT/DELETE) يرجع:
  * **HTTP 403**:
    ```json
    {
      "success": false,
      "code": "trial_expired",
      "message": "انتهت الفترة التجريبية (5 أيام). يرجى الترقية واختيار باقة للاستمرار في الاستخدام."
    }
    ```
* **تحديث الواجهة**: عند استقبال `code === 'trial_expired'`، إظهار نافذة منبثقة غير قابلة للإغلاق (Upgrade Modal) توجه المستخدم مباشرة لاختيار باقة وطلبها.

#### د. طلب ترقية باقة من قِبل العميل:
* **إرسال الطلب**:
  * **Endpoint**: `POST /api/v1/plans/request`
  * **Headers**: `X-Tenant-ID: <tenant_id>`
  * **Payload**:
    ```json
    {
      "plan_id": 2,
      "notes": "نرجو اعتماد باقة برو السنوية"
    }
    ```
* **عرض حالة الطلب للعميل**:
  * **Endpoint**: `GET /api/v1/plans/my-requests`
  * إظهار شارة (Badge) بجوار الباقة: `قيد المراجعة من الإدارة`.

---

### Flow 5: ميزة رادار الأخبار الحصرية (Premium News Gate)

* **Endpoints**:
  * `GET /api/v1/industry-news/today`
  * `GET /api/v1/industry-news`
* **السلوك إذا كانت الباقة لا تدعم الأخبار (`has_news: false`)**:
  * **HTTP 403**:
    ```json
    {
      "success": false,
      "code": "feature_not_included",
      "message": "ميزة رادار الأخبار اليومي بالذكاء الاصطناعي هي ميزة حصرية تتطلب باقة تدعمها. يرجى الترقية."
    }
    ```
* **تحديث الواجهة في القائمة الجانبية (Sidebar)**:
  * إذا كانت الباقة الحالية تملك `has_news: false`:
    * وضع أيقونة **قفل (Lock Icon)** أو شارة **PRO** بجانب عنصر "رادار الأخبار".
    * عند الضغط عليه، لا يتم إظهار شاشة خطأ مكسورة، بل يتم فتح شاشة ترويجية (Feature Teaser):
      * *"ميزة رادار الأخبار بالذكاء الاصطناعي ترصد يومياً أهم أخبار قطاعك ومنافسيك وتقترح إجراءات تشغيلية وتسويقية فورية. متوفرة في باقة PRO فما فوق."*
      * زر CTA: **[طلب ترقية الباقة]**.

---

## 4. جدول نقاط النهاية السريعة (API Cheat Sheet)

| المسار (Endpoint) | الطريقة | الصلاحية | الوصف |
| :--- | :--- | :--- | :--- |
| `/api/v1/auth/register` | `POST` | عام | تسجيل شركة جديدة (تكون معلقة) |
| `/api/v1/admin/tenants?status=suspended` | `GET` | Super Admin | عرض الشركات المعلقة بانتظار الاعتماد |
| `/api/v1/admin/tenants/{id}/approve` | `POST` | Super Admin | اعتماد الشركة وبدء تجربة 5 أيام |
| `/api/v1/admin/tenants/{id}/reject` | `POST` | Super Admin | رفض طلب تسجيل الشركة |
| `/api/v1/admin/plan-requests` | `GET` | Super Admin | عرض طلبات ترقية الباقات |
| `/api/v1/admin/plan-requests/{id}/approve` | `POST` | Super Admin | الموافقة على طلب باقة وترقية الشركة |
| `/api/v1/admin/plan-requests/{id}/reject` | `POST` | Super Admin | رفض طلب الباقة |
| `/api/v1/plans` | `GET` | عام / مساحة عمل | عرض الباقات (مع `has_news` والحدود) |
| `/api/v1/plans/request` | `POST` | مساحة عمل | إرسال طلب اختيار/ترقية باقة |
| `/api/v1/plans/my-requests` | `GET` | مساحة عمل | متابعة طلبات الباقات للشركة الحالية |
| `/api/v1/collections` | `POST` | مساحة عمل | إنشاء حملة (5 كحد أقصى، 25 رداً بالتجربة) |
| `/api/v1/industry-news/today` | `GET` | مساحة عمل | رادار الأخبار (يتطلب `has_news: true`) |
