# دليل واجهات السوبر أدمن الحقيقية (Super Admin API Documentation)
# Production-Ready Endpoints for Super Admin Dashboard

> [!NOTE]
> هذا التوثيق مخصص لمطور/وكيل الذكاء الاصطناعي للواجهة الأمامية (**Frontend AI Agent**) لبناء لوحة تحكم السوبر أدمن (**Super Admin Dashboard**).  
> **جميع الواجهات المذكورة هنا حقيقية 100% وتعتمد على قاعدة البيانات والمنطق التشغيلي الفعلي (تم استبعاد الواجهات الوهمية أو الـ Mock).**

---

## 1. المصادقة والترويسات (Authentication & Headers)

جميع الواجهات محمية وتتطلب تسجيل الدخول بحساب **مدير النظام الفائق (Super Admin)** وإرسال التوكن في الـ Headers:

```http
Authorization: Bearer <SUPER_ADMIN_SANCTUM_TOKEN>
Accept: application/json
Content-Type: application/json
```

### بيانات الدخول الافتراضية للسوبر أدمن (Default Seeded Credentials):
- **البريد الإلكتروني:** `superadmin@sada.com`
- **كلمة المرور:** `password`
- **نقطة تسجيل الدخول:** `POST /api/v1/auth/login`

```json
// POST /api/v1/auth/login
{
  "email": "superadmin@sada.com",
  "password": "password"
}
```

---

## 2. النماذج وأنواع البيانات (TypeScript Types)

```typescript
export type TenantStatus = 'suspended' | 'trial' | 'active' | 'cancelled';
export type PlanRequestStatus = 'pending' | 'approved' | 'rejected';
export type RiskFactor = 'quota_exceeded' | 'quota_warning' | 'renewal_due';

// نموذج الشركة / مساحة العمل
export interface SuperAdminTenant {
  id: number;
  ulid: string;
  name: string;
  slug: string;
  logo: string | null;
  status: TenantStatus;
  is_owner: boolean;
  settings: Record<string, any> | null;
  plan: {
    id: number;
    name: string;
    slug: string;
    price: number;
    features: string[];
  } | null;
  subscription: {
    id: number;
    status: string;
    trial_ends_at: string | null;
    ends_at: string | null;
    billing_cycle: string;
  } | null;
  created_at: string;
  updated_at: string;
}

// نموذج طلب ترقية / تغيير الباقة
export interface PlanRequestItem {
  id: number;
  tenant_id: number;
  plan_id: number;
  user_id: number;
  status: PlanRequestStatus;
  notes: string | null;
  rejection_reason: string | null;
  created_at: string;
  reviewed_at: string | null;
  tenant: {
    id: number;
    name: string;
    slug: string;
  };
  plan: {
    id: number;
    name: string;
    slug: string;
    price: number;
    billing_interval: string;
  };
  user: {
    id: number;
    name: string;
    email: string;
  };
  reviewer?: {
    id: number;
    name: string;
    email: string;
  } | null;
}

// نموذج طلب الانضمام العام
export interface TenantJoinRequest {
  id: number;
  company_name: string;
  sector: string | null;
  contact_name: string;
  contact_email: string;
  contact_phone: string | null;
  requested_plan_id: number | null;
  commercial_register: string | null;
  status: 'pending' | 'approved' | 'rejected';
  notes: string | null;
  created_tenant_id: number | null;
  created_at: string;
  requested_plan?: {
    id: number;
    name: string;
    slug: string;
    price: number;
  } | null;
}

// باقة الاشتراك
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
    max_campaigns: number;
    max_articles_per_campaign: number;
  };
  features: string[];
  has_news: boolean;
  is_active: boolean;
}

// إحصائيات المنصة الشاملة
export interface PlatformMetrics {
  tenants: {
    total: number;
    active: number;
  };
  articles: {
    total: number;
  };
  users: {
    total: number;
  };
}
```

---

## 3. إحصائيات النظام ومؤشرات الأداء (Platform Metrics & Analytics)

### 3.1 الإحصائيات العامة للمنصة
- **المسار:** `GET /api/v1/admin/metrics`
- **الوظيفة:** إرجاع أعداد حقيقية من قاعدة البيانات للشركات المسجلة، والشركات النشطة، والمقالات، ومستخدمي المنصة.
- **استجابة الخادم:**
```json
{
  "success": true,
  "data": {
    "tenants": {
      "total": 14,
      "active": 9
    },
    "articles": {
      "total": 8450
    },
    "users": {
      "total": 28
    }
  }
}
```

---

### 3.2 نمو الشركات والإيراد الشهري (Company Growth & MRR)
- **المسار:** `GET /api/v1/admin/analytics/company-growth`
- **الوظيفة:** حساب حقيقي للنمو عبر آخر 6 أشهر (الشركات الجديدة، الشركات النشطة، وإجمالي الإيراد الشهري MRR للاشتراكات النشطة).
- **استجابة الخادم:**
```json
{
  "success": true,
  "data": [
    {
      "month": "مايو",
      "new_tenants": 2,
      "active_tenants": 5,
      "mrr": 18500.00
    },
    {
      "month": "يونيو",
      "new_tenants": 4,
      "active_tenants": 8,
      "mrr": 32400.00
    }
  ]
}
```

---

### 3.3 توزيع المشتركين على الباقات (Packages Breakdown)
- **المسار:** `GET /api/v1/admin/analytics/packages-breakdown`
- **الوظيفة:** حساب عدد الاشتراكات النشطة لكل باقة ونسبتها المئوية من إجمالي المشتركين.
- **استجابة الخادم:**
```json
{
  "success": true,
  "data": [
    {
      "plan_id": 1,
      "plan_name": "الباقة الأساسية",
      "count": 5,
      "percentage": 55.6
    },
    {
      "plan_id": 2,
      "plan_name": "الباقة الاحترافية",
      "count": 3,
      "percentage": 33.3
    },
    {
      "plan_id": 3,
      "plan_name": "باقة المؤسسات",
      "count": 1,
      "percentage": 11.1
    }
  ]
}
```

---

### 3.4 الشركات المعرضة للخطر (At-Risk Tenants)
- **المسار:** `GET /api/v1/admin/tenants/at-risk`
- **الوظيفة:** حساب نسبة استهلاك الكوتا الحقيقية (المقالات المستخدمة مقارنة بسقف الباقة) والشركات القريبة من التجديد (خلال 7 أيام).
- **استجابة الخادم:**
```json
{
  "success": true,
  "data": [
    {
      "id": 3,
      "name": "شركة الرياض للإعلام",
      "quota_used_percentage": 88,
      "risk_factor": "quota_exceeded",
      "days_to_renewal": 12
    },
    {
      "id": 5,
      "name": "وكالة صدى الإعلانية",
      "quota_used_percentage": 64,
      "risk_factor": "renewal_due",
      "days_to_renewal": 3
    }
  ]
}
```

---

### 3.5 الشركات الأكثر نشاطاً (Top Companies Leaderboard)
- **المسار:** `GET /api/v1/admin/tenants/top-leaderboard?limit=5`
- **الوظيفة:** ترتيب الشركات النشطة حسب حجم المحتوى والمقالات المرصودة.
- **استجابة الخادم:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "مجموعة الشايع",
      "articles_count": 4820,
      "sentiment_score": 8.4,
      "status": "active"
    }
  ]
}
```

---

## 4. إدارة الشركات والموافقة والفترة التجريبية (Tenants & Approvals)

### 4.1 استعراض قائمة الشركات مع الفلترة والصفحات
- **المسار:** `GET /api/v1/admin/tenants`
- **المعاملات (Query Params):**
  - `status`: `all` | `suspended` | `trial` | `active` | `cancelled` (افتراضي: الكل)
  - `page`: رقم الصفحة (تدرج 20 شركة بالصفحة)
- **استجابة الخادم:**
```json
{
  "success": true,
  "data": [
    {
      "id": 12,
      "ulid": "01J9...",
      "name": "شركة التقنية المتقدمة",
      "slug": "advanced-tech",
      "logo": "https://...",
      "status": "suspended",
      "is_owner": false,
      "plan": {
        "id": 1,
        "name": "الباقة الأساسية",
        "slug": "basic",
        "price": 349.00
      },
      "subscription": null,
      "created_at": "2026-10-08T20:15:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 2,
    "per_page": 20,
    "total": 24
  }
}
```

---

### 4.2 الموافقة على شركة جديدة وتفعيل التجربة (5 أيام)
- **المسار:** `POST /api/v1/admin/tenants/{id}/approve`
- **الوظيفة:**
  1. تحويل حالة الشركة من `suspended` إلى **`trial`**.
  2. تفعيل حساب المالك (`status = 'active'`).
  3. ضبط تاريخ انتهاء التجربة لـ **5 أيام** (`trial_ends_at = now + 5 days`).
  4. إنشاء اشتراك تجريبي `status = 'trial'`.
  5. إطلاق مهمة الذكاء الاصطناعي (`InitializeTenantIntelligenceJob`) لبدء الرصد الفعلي.
- **استجابة الخادم:**
```json
{
  "success": true,
  "message": "تمت الموافقة على الشركة وتفعيل الفترة التجريبية لمدة 5 أيام بنجاح.",
  "data": {
    "id": 12,
    "name": "شركة التقنية المتقدمة",
    "status": "trial",
    "subscription": {
      "id": 8,
      "status": "trial",
      "trial_ends_at": "2026-10-13T20:15:00.000000Z",
      "ends_at": "2026-10-13T20:15:00.000000Z"
    }
  }
}
```

---

### 4.3 رفض تسجيل شركة مع إبداء السبب
- **المسار:** `POST /api/v1/admin/tenants/{id}/reject`
- **جسم الطلب (Body):**
```json
{
  "reason": "السجل التجاري غير واضح أو غير مطابق للبيانات المدخلة"
}
```
- **استجابة الخادم:**
```json
{
  "success": true,
  "message": "Tenant rejected successfully"
}
```

---

### 4.4 تعيين / ترقية باقة الشركة مباشرة (Direct Plan Assignment)
- **المسار:** `POST /api/v1/admin/tenants/{id}/plan`
- **الوظيفة:** ترقية الشركة يدوياً إلى باقة محددة وتفعيل اشتراكها لمدة شهر.
- **جسم الطلب (Body):**
```json
{
  "plan_id": 2
}
```
- **استجابة الخادم:**
```json
{
  "success": true,
  "message": "Plan assigned to tenant successfully."
}
```

---

## 5. إدارة طلبات ترقية الباقات (Tenant Plan Requests)

تُستخدم عندما يطلب العميل ترقية باقته من لوحة تحكمه، لتظهر للسوبر أدمن للموافقة أو الرفض.

### 5.1 استعراض طلبات الباقات
- **المسار:** `GET /api/v1/admin/plan-requests`
- **المعاملات (Query Params):**
  - `status`: `pending` | `approved` | `rejected` | `all`
  - `page`: رقم الصفحة
- **استجابة الخادم:**
```json
{
  "success": true,
  "data": [
    {
      "id": 4,
      "tenant_id": 8,
      "plan_id": 2,
      "user_id": 14,
      "status": "pending",
      "notes": "نحتاج ميزة رادار الأخبار اليومي وزيادة الحملات إلى 30",
      "rejection_reason": null,
      "created_at": "2026-10-08T18:30:00.000000Z",
      "tenant": {
        "id": 8,
        "name": "شركة نجد للاستشارات",
        "slug": "najd-consulting"
      },
      "plan": {
        "id": 2,
        "name": "الباقة الاحترافية",
        "price": 899.00
      },
      "user": {
        "id": 14,
        "name": "عبدالله السالم",
        "email": "salem@najd.com"
      }
    }
  ]
}
```

---

### 5.2 الموافقة على طلب ترقية الباقة
- **المسار:** `POST /api/v1/admin/plan-requests/{id}/approve`
- **الوظيفة:**
  1. ترقية الشركة فوراً للباقة المطلوبة.
  2. تحويل حالة الشركة إلى **`active`** (خروج من التجربة).
  3. إنشاء اشتراك نشط لمدة 30 يوماً (أو 365 يوماً إذا كانت الباقة سنوية).
  4. تسجيل المشرف الذي وافق على الطلب وتاريخ الموافقة.
- **استجابة الخادم:**
```json
{
  "success": true,
  "message": "تمت الموافقة على طلب الباقة وترقية مساحة العمل بنجاح.",
  "data": {
    "id": 4,
    "status": "approved",
    "reviewed_at": "2026-10-08T22:00:00.000000Z",
    "tenant": {
      "id": 8,
      "status": "active",
      "plan": {
        "id": 2,
        "name": "الباقة الاحترافية",
        "price": 899.00
      }
    }
  }
}
```

---

### 5.3 رفض طلب ترقية الباقة مع إبداء السبب
- **المسار:** `POST /api/v1/admin/plan-requests/{id}/reject`
- **جسم الطلب (Body):**
```json
{
  "rejection_reason": "يرجى سداد الفاتورة المعلقة السابقة أولاً"
}
```
- **استجابة الخادم:**
```json
{
  "success": true,
  "data": {
    "id": 4,
    "status": "rejected",
    "rejection_reason": "يرجى سداد الفاتورة المعلقة السابقة أولاً"
  }
}
```

---

## 6. إدارة طلبات الانضمام العامة (Tenant Join Requests)

تُستخدم للطلبات القادمة من نموذج التسجيل المؤسسي الخارجي للمنصة (`POST /api/v1/tenant-requests`).

### 6.1 استعراض طلبات الانضمام
- **المسار:** `GET /api/v1/admin/tenant-requests`
- **المعاملات (Query Params):**
  - `status`: `pending` | `approved` | `rejected` | `all`
- **استجابة الخادم:**
```json
{
  "success": true,
  "data": [
    {
      "id": 7,
      "company_name": "مؤسسة الأفق للحلول",
      "sector": "تقنية المعلومات",
      "contact_name": "فهد الدوسري",
      "contact_email": "fahad@alofoq.sa",
      "contact_phone": "+966501234567",
      "status": "pending",
      "requested_plan": {
        "id": 2,
        "name": "الباقة الاحترافية",
        "price": 899.00
      }
    }
  ]
}
```

---

### 6.2 الموافقة التلقائية على الطلب وإنشاء الشركة وحساب المالك (Auto-Provisioning)
- **المسار:** `POST /api/v1/admin/tenant-requests/{id}/approve`
- **الوظيفة:**
  1. إنشاء شركة ومساحة عمل جديدة فوراً.
  2. إنشاء حساب مستخدم للمالك وربطه بدور `tenant_owner`.
  3. تفعيل الاشتراك للباقة المطلوبة.
- **جسم الطلب (اختياري لتغيير الباقة المخصصة):**
```json
{
  "plan_id": 2,
  "notes": "تم التحقق من بيانات الاتصال والسجل التجاري"
}
```
- **استجابة الخادم:**
```json
{
  "success": true,
  "message": "Tenant request approved and workspace created successfully",
  "data": {
    "tenant_id": 15,
    "company_name": "مؤسسة الأفق للحلول",
    "status": "active"
  }
}
```

---

### 6.3 رفض طلب الانضمام
- **المسار:** `POST /api/v1/admin/tenant-requests/{id}/reject`
- **جسم الطلب (Body):**
```json
{
  "reason": "البيانات المدخلة غير صحيحة"
}
```

---

## 7. إدارة باقات الاشتراك (SaaS Plans CRUD)

### 7.1 استعراض جميع الباقات (بما فيها غير النشطة للسوبر أدمن)
- **المسار:** `GET /api/v1/plans`
- **استجابة الخادم:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "الباقة الأساسية",
      "slug": "basic",
      "description": "مثالية للشركات الناشئة والمؤسسات الصغيرة",
      "price": 349.0,
      "currency": "SAR",
      "billing_interval": "monthly",
      "limits": {
        "max_users": 3,
        "max_keywords": 10,
        "max_sources": 30,
        "max_articles": 3000,
        "max_api_requests": 1000,
        "max_campaigns": 10,
        "max_articles_per_campaign": 100
      },
      "features": [
        "رصد المواقع الإخبارية ومنصات التواصل",
        "تحليل المشاعر التلقائي بالذكاء الاصطناعي",
        "حتى 3,000 منشور ومقال شهرياً"
      ],
      "has_news": false,
      "is_active": true
    }
  ]
}
```

---

### 7.2 إنشاء باقة جديدة (Create Plan)
- **المسار:** `POST /api/v1/plans`
- **جسم الطلب (Body):**
```json
{
  "name": "باقة النمو السريع",
  "slug": "growth",
  "description": "باقة مخصصة للشركات في طور التوسع السريع",
  "price": 599.00,
  "currency": "SAR",
  "billing_interval": "monthly",
  "limits": {
    "max_users": 6,
    "max_keywords": 20,
    "max_sources": 60,
    "max_articles": 8000,
    "max_api_requests": 5000,
    "max_campaigns": 20,
    "max_articles_per_campaign": 250
  },
  "features": [
    "رصد شبكات التواصل",
    "تحليل المشاعر بالذكاء الاصطناعي",
    "تصدير تقارير PDF"
  ],
  "has_news": false,
  "is_active": true
}
```
- **استجابة الخادم:** `201 Created` مع تفاصيل الباقة المنشأة.

---

### 7.3 تعديل باقة حالية (Update Plan)
- **المسار:** `PUT /api/v1/plans/{id}`
- **جسم الطلب (Body - يمكن إرسال الحقول المراد تعديلها فقط):**
```json
{
  "price": 649.00,
  "has_news": true,
  "limits": {
    "max_articles": 9000,
    "max_campaigns": 25
  }
}
```
- **استجابة الخادم:** `200 OK` مع الباقة المحدثة.

---

### 7.4 تفعيل / تعطيل الباقة (Toggle Active Status)
- **المسار:** `POST /api/v1/plans/{id}/toggle-active`
- **الوظيفة:** تبديل حالة الباقة بين نشطة (تظهر للعملاء) أو معطلة بنقرة زر واحدة.
- **استجابة الخادم:**
```json
{
  "success": true,
  "message": "Plan status toggled successfully",
  "data": {
    "id": 1,
    "is_active": false
  }
}
```

---

### 7.5 حذف باقة (Delete Plan)
- **المسار:** `DELETE /api/v1/plans/{id}`
- **شروط الأمان:** يمنع النظام حذف الباقة إذا كانت مرتبطة باشتراكات نشطة لشركات مسجلة.
- **استجابة الخادم:**
```json
{
  "success": true,
  "message": "Plan deleted successfully"
}
```

---

## 8. إعدادات النظام ووضع الصيانة (System Settings & Maintenance)

### 8.1 جلب إعدادات المنصة العامة
- **المسار:** `GET /api/v1/admin/settings`
- **استجابة الخادم:**
```json
{
  "success": true,
  "data": {
    "maintenance_mode": false,
    "ai_provider": "gemini-pro",
    "default_quota_limit": 20000,
    "security": {
      "enforce_2fa": true,
      "session_timeout_minutes": 60
    }
  }
}
```

---

### 8.2 تحديث إعدادات المنصة
- **المسار:** `PUT /api/v1/admin/settings`
- **جسم الطلب (Body):**
```json
{
  "ai_provider": "gemini-2.5-flash",
  "default_quota_limit": 25000,
  "security": {
    "enforce_2fa": true,
    "session_timeout_minutes": 120
  }
}
```
- **استجابة الخادم:** إرجاع الإعدادات المحدثة المحفوظة في قاعدة البيانات.

---

### 8.3 تشغيل / إيقاف وضع الصيانة للمنصة (Maintenance Mode)
- **المسار:** `POST /api/v1/admin/settings/maintenance`
- **جسم الطلب (Body):**
```json
{
  "enabled": true,
  "message": "النظام يخضع لأعمال صيانة وتحسينات مجدولة حالياً، سنعود قريباً."
}
```
- **استجابة الخادم:**
```json
{
  "success": true,
  "message": "Maintenance mode updated successfully",
  "data": {
    "maintenance_mode": true,
    "message": "النظام يخضع لأعمال صيانة وتحسينات مجدولة حالياً، سنعود قريباً."
  }
}
```

---

## 9. إدارة النسخ الاحتياطية (Database Backups Management)

تعتمد هذه الواجهات على جدول ونموذج `system_backups` في قاعدة البيانات.

### 9.1 استعراض النسخ الاحتياطية المسجلة
- **المسار:** `GET /api/v1/admin/backups`
- **استجابة الخادم:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "filename": "sada_backup_2026_10_08_210000.sql.gz",
      "disk": "local",
      "size_bytes": 18450000,
      "status": "completed",
      "triggered_by": "مدير النظام الفائق",
      "created_at": "2026-10-08T21:00:00.000000Z"
    }
  ]
}
```

---

### 9.2 إنشاء لقطة نسخ احتياطي حقيقية (Trigger Backup)
- **المسار:** `POST /api/v1/admin/backups/trigger`
- **الوظيفة:** إنشاء وتصدير ملف SQL حقيقي لقاعدة البيانات وتخزينه مضغوطاً بصيغة `.sql.gz` على القرص في المجلد `storage/app/backups/` وتسجيله في جدول `system_backups`.
- **مكان وجود الملف الفعلي على السيرفر:**  
  `storage/app/backups/{filename}` (مثال: `storage/app/backups/sada_backup_2026_10_08_235744.sql.gz`)
- **استجابة الخادم:** `201 Created`
```json
{
  "success": true,
  "message": "Database backup created successfully",
  "data": {
    "id": 2,
    "filename": "sada_backup_2026_10_08_235744.sql.gz",
    "disk": "local",
    "size_bytes": 109294,
    "status": "completed",
    "triggered_by": "مدير النظام الفائق"
  }
}
```

---

### 9.3 تحميل ملف النسخة الاحتياطية (Download Backup File)
- **المسار:** `GET /api/v1/admin/backups/{id}/download`
- **الوظيفة:** تحميل ملف الـ `.sql.gz` الحقيقي مباشرة من السيرفر بنقرة زر واحدة من لوحة التحكم لاسترجاع قاعدة البيانات في أي وقت.
- **استجابة الخادم:** تحميل ملف مضغوط بصيغة Binary (`application/gzip`).

---

## 10. إدارة مستخدمي المنصة (Platform User Deletion)

### 10.1 حذف مستخدم من المنصة
- **المسار:** `DELETE /api/v1/users/{userId}`
- **الوظيفة:** حذف المستخدم وفصل علاقاته بجميع مساحات العمل.
- **حماية أمنية:** يمنع النظام حذف حساب السوبر أدمن الرئيسي إذا كان الحساب الوحيد المتبقي.
- **استجابة الخادم:**
```json
{
  "success": true,
  "message": "User deleted successfully"
}
```

---

## 11. مقترح هيكلة صفحات لوحة التحكم (Frontend Pages Architecture)

يوصى وكيل الذكاء الاصطناعي للواجهة الأمامية ببناء اللوحة وفق الصفحات التالية:

1. **الصفحة الرئيسية (Dashboard Overview):**
   - بطاقات الـ KPIs الرئيسية من `GET /api/v1/admin/metrics`.
   - رسم بياني لنمو الشركات والـ MRR من `GET /api/v1/admin/analytics/company-growth`.
   - رسم بياني لتوزيع الباقات من `GET /api/v1/admin/analytics/packages-breakdown`.
   - جدول الشركات المعرضة للخطر (At-Risk) من `GET /api/v1/admin/tenants/at-risk`.
2. **صفحة إدارة الشركات (Companies Management):**
   - جدول الشركات مع فلاتر الحالة (`معلقة suspended` | `تجريبية trial` | `نشطة active`).
   - أزرار الموافقة والرفض للشركات المعلقة (`/approve` و `/reject`).
   - خيار ترقية الباقة المباشر (`/plan`).
3. **صفحة طلبات الترقية (Plan Upgrade Requests):**
   - جدول الطلبات من `GET /api/v1/admin/plan-requests`.
   - أزرار الموافقة الفورية أو الرفض مع مودال إدخال السبب.
4. **صفحة طلبات الانضمام الخارجية (Join Requests):**
   - إدارة طلبات التسجيل العامة من `GET /api/v1/admin/tenant-requests` وتفعيلها بزر واحد.
5. **صفحة باقات الاشتراك (SaaS Packages Manager):**
   - كروت الباقات الحالية مع إحصائيات كل باقة.
   - زر إضافة باقة جديدة وزر تبديل الحالة (Toggle Active).
   - نموذج تعديل حدود الكوتا والميزات وخاصية رادار الأخبار.
6. **صفحة الإعدادات والصيانة والنسخ الاحتياطي (Settings & Maintenance):**
   - مفتاح تبديل وضع الصيانة (Maintenance Mode Switch).
   - جدول النسخ الاحتياطية وزر أخذ لقطة فورية (Take Backup Now).
