# ملخص التحديثات الشاملة والتعديلات البرمجية والمالية
# Comprehensive System Updates & Changelog

**تاريخ التحديث:** أكتوبر 2026  
**المشروع:** منصة صدى (Sada Backend & SaaS Engine)  
**حالة الاختبارات:** 16/16 اختبار ناجح (120 Assertions) ✅

---

## فهرس المحتويات (Table of Contents)
1. [نظام تسجيل الشركات والموافقة والفترة التجريبية (Registration & Trial)](#1-نظام-تسجيل-الشركات-والموافقة-والفترة-التجريبية)
2. [نظام طلب الباقات واعتمادها من الإدارة (Plan Requests)](#2-نظام-طلب-الباقات-واعتمادها-من-الإدارة)
3. [حصر ميزة رادار الأخبار اليومي (Premium News Gate)](#3-حصر-ميزة-رادار-الأخبار-اليومي)
4. [تحديث الباقات والأسعار الجديدة في قاعدة البيانات (PlanSeeder)](#4-تحديث-الباقات-والأسعار-الجديدة-في-قاعدة-البيانات)
5. [تحسين كشط إنستغرام وتفادي تكلفة الهاشتاقات (Instagram Optimization)](#5-تحسين-كشط-إنستغرام-وتفادي-تكلفة-الهاشتاقات)
6. [نظام التخزين المؤقت الذكي (Caching Engine)](#6-نظام-التخزين-المؤقت-الذكي)
7. [ملخص الأرقام والجدوى المالية (Financial Summary & Unit Economics)](#7-ملخص-الأرقام-والجدوى-المالية)
8. [سجل الملفات المعدلة والمنشأة (Modified & Created Files)](#8-سجل-الملفات-المعدلة-والمنشأة)

---

## 1. نظام تسجيل الشركات والموافقة والفترة التجريبية

### المنطق التشغيلي السابق:
- كان تسجيل الشركات يفعل الحسابات تلقائياً أو يعطي تجربة دون قيود واضحة، مع تشغيل فوري لكشط البيانات دون فحص أمني أو تدقيق.

### التعديلات المنفذة:
1. **تسجيل معلق تلقائياً (`status = 'suspended'`):**
   - عند إنشاء حساب جديد عبر `POST /api/v1/auth/register`، يتم إنشاء الشركة بحالة **`suspended` (معلقة)**.
   - لا تبدأ الفترة التجريبية ولا يتم تشغيل أي وظائف كشط أو استهلاك API.
   - إذا حاول المستخدم تسجيل الدخول، يُسمح له بالوصول فقط لصفحة الانتظار `pending-approval`.
2. **موافقة المشرف وتفعيل التجربة (5 أيام):**
   - أضيف مسار مخصص للسوبر أدمن:  
     `POST /api/v1/admin/tenants/{id}/approve`
   - عند الموافقة:
     - تتحول حالة الشركة إلى `status = 'trial'`.
     - يتم ضبط تاريخ انتهاء التجربة: `trial_ends_at = now()->addDays(5)`.
     - يتم إطلاق مهمة الذكاء الاصطناعي `InitializeTenantIntelligenceJob` للبدء بالرصد الأولي.
3. **قيود صارمة على الفترة التجريبية:**
   - **الحد الأقصى للحملات:** **5 حملات فقط** (`max_campaigns = 5`).
   - **الحد الأقصى للمقالات/التعليقات:** **25 مقال أو تعليق لكل حملة كحد أقصى**.
   - إجمالي ما يمكن كشطه للشركة طوال الـ 5 أيام هو **125 منشور فقط**.
4. **انتهاء التجربة (Trial Expiration):**
   - بعد مرور الـ 5 أيام، تحظر الميدلوير `ResolveTenant` و `CheckTenantStatus` العمليات التعديلية أو الإنشائية، وترجع استجابة `403 Forbidden` بكود `trial_expired` لتوجيه العميل لاختيار باقة مدفوعة.

---

## 2. نظام طلب الباقات واعتمادها من الإدارة

### المنطق التشغيلي:
1. **تقديم طلب باقة من العميل:**
   - تم إنشاء جدول ونموذج `PlanRequest` وكنترولر `PlanRequestController`.
   - مسار العميل: `POST /api/v1/plans/request` مع إرسال `plan_id` وملاحظات اختيارية `notes`.
   - مسار استعراض طلبات الشركة: `GET /api/v1/plans/my-requests`.
2. **إدارة الطلبات من قبل السوبر أدمن:**
   - استعراض جميع طلبات الترقية المعلقة: `GET /api/v1/admin/plan-requests`
   - الموافقة على الطلب: `POST /api/v1/admin/plan-requests/{id}/approve`
     - تُحدث باقة الشركة وتخرج الشركة من التجربة وتصبح **`status = 'active'`**.
     - تُنشأ أو تُحدّث اشتراكات الشركة `Subscription`.
   - رفض الطلب: `POST /api/v1/admin/plan-requests/{id}/reject` مع سبب الرفض `rejection_reason`.

---

## 3. حصر ميزة رادار الأخبار اليومي

### التعديلات:
1. **عمود جديد للباقات:**
   - إضافة عمود `has_news` من نوع `boolean` في جدول `plans`.
2. **ميدلوير الفحص البرمجي (`EnsureFeatureAccess`):**
   - إنشاء ميدلوير `App\Http\Middleware\EnsureFeatureAccess`.
   - تسجيل الاسم المستعار: `'feature' => \App\Http\Middleware\EnsureFeatureAccess::class` في `bootstrap/app.php`.
   - حماية مسارات الأخبار:
     ```php
     Route::prefix('industry-news')->middleware('feature:news')->group(...);
     ```
   - إذا كانت باقة العميل لا تتضمن الأخبار (`has_news = false`) أو كان الحساب تجريبياً، يرجع الخادم `403` مع كود `feature_not_included` ورسالة ترقية مخصصة.

---

## 4. تحديث الباقات والأسعار الجديدة في قاعدة البيانات

تم تحديث وزرع الباقات في [`database/seeders/PlanSeeder.php`](file:///c:/Users/workstation/Documents/sada/database/seeders/PlanSeeder.php) وفق الأرقام الأكثر ربحية وملاءمة للسوق:

| الباقة | السعر الشهري | الكوتا الشهرية | الحد الأقصى للحملات | سقف الحملة الواحدة | المستخدمين / الكلمات | رادار الأخبار | التكلفة القصوى (COGS) | صافي الربح الشهري | هامش الربح |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **الباقة الأساسية (Basic)** | **349 ر.س** ($93) | 3,000 منشور | 10 حملات | 100 منشور | 3 مستخدمين / 10 كلمات | ❌ غير متوفر | 54.00 ر.س | **+295.00 ر.س** | **84.5%** |
| **الباقة الاحترافية (Pro)** | **899 ر.س** ($240) | 12,000 منشور | 30 حملة | 400 منشور | 10 مستخدمين / 30 كلمة | ✅ مشمول بالكامل | 241.00 ر.س | **+658.00 ر.س** | **73.2%** |
| **باقة المؤسسات (Enterprise)** | **2,499 ر.س** ($666) | 35,000 منشور | 100 حملة | 1,000 منشور | 25 مستخدم / 80 كلمة | ✅ رادار متقدم + API | 680.00 ر.س | **+1,819.00 ر.س** | **72.8%** |

*ملاحظة: في الاستهلاك الواقعي الطبيعي (متوسط 60% من الكوتا)، تتراوح هوامش الأرباح الصافية بين **83.2% و 90.5%**.*

---

## 5. تحسين كشط إنستغرام وتفادي تكلفة الهاشتاقات

في ملف [`app/Services/ApifyScraperService.php`](file:///c:/Users/workstation/Documents/sada/app/Services/ApifyScraperService.php):

1. **اكتشاف البند الأغلى في الفاتورة:**
   - استعلام الهاشتاق في إنستغرام (Actor `reGe1ST3OBgYZSsZJ`) كان يكلف **$0.015** لكل استعلام.
   - استخراج البوستات العادية (Actor `VLKR1emKm1YGLmiuZ`) يكلف فقط **$0.0005** لكل منشور.
   - الفارق: استعلام الهاشتاق أغلى بـ **30 ضعفاً**!
2. **الحلول المنفذة:**
   - في دالة `fetchInstagramByKeywords`: تجريد علامة `#` تلقائياً من الكلمات المفتاحية (`ltrim($kw, '#')`) لاستدعاء محرك البوستات العادية الرخيص.
   - في دالة `fetchInstagramPostsTrending`: إضافة معامل `$explicitHashtags = false`؛ حيث يتم استخدام محرك البوستات العادية تلقائياً ما لم يطلب العميل الهاشتاق صراحة، مما حقق **وفراً بنسبة 96.7%** في مسار إنستغرام.
   - في دالة `fetchInstagramComments`: حذف إعداد `"searchType" => "hashtag"` لمنع أي فوترة على مستوى الهاشتاقات عند كشط تعليقات الروابط المباشرة.

---

## 6. نظام التخزين المؤقت الذكي

1. **الكاش على مستوى واجهات الاستعلامات (Service Caching):**
   - تم دمج واجهة الكاش في `ApifyScraperService` باستخدام `Cache::remember` و `Cache::put` لمدة **6 ساعات**.
   - يشمل الكاش:
     - `fetchXByKeywords`
     - `fetchInstagramByKeywords`
     - `fetchTiktokByKeywords`
     - `fetchFacebookByKeywords`
     - `fetchFacebookPostsTrending`
     - `fetchInstagramPostsTrending`
     - `fetchTiktokTrending`
     - `fetchTwitterTrends`
     - كشط التعليقات لكافة المنصات (`Instagram`، `Facebook`، `TikTok`، `Twitter`).
   - تكرار الاستعلام لنفس الكلمات أو إعادة مزامنة الحملة لا يتصل بمخدمات Apify ولا يخصم أي مبالغ إضافية.
2. **منع تكرار المنشورات في قاعدة البيانات (Post Deduplication):**
   - في [`app/Jobs/ScrapeKeywordsJob.php`](file:///c:/Users/workstation/Documents/sada/app/Jobs/ScrapeKeywordsJob.php):
   - التحقق من وجود المنشور مسبقاً للعميل (`$isExistingArticle = $article->exists`).
   - إذا كان المنشور مسجلاً ومحللاً مسبقاً، يتم ربطه فوراً بالحملة دون إعادة تشغيل نموذج تحليل المشاعر بالذكاء الاصطناعي أو إعادة حفظه كمنشور جديد، مما يخفض الاستهلاك مع مرور الوقت بنسبة **40% إلى 70%**.

---

## 7. ملخص الأرقام والجدوى المالية

| البند | القيمة بالدولار (USD) | القيمة بالريال السعودي (SAR) | التفاصيل والمصدر |
| :--- | :--- | :--- | :--- |
| **تكلفة كشط المنشور الواحد (متوسط مدمج)** | $0.0045 | 0.017 ر.س | فاتورة Apify لشهر أكتوبر |
| **تكلفة تحليل المشاعر بالـ AI للمنشور** | $0.0003 | 0.001 ر.س | Google Gemini 2.5 Flash |
| **إجمالي تكلفة المنشور المحلل بالكامل** | **$0.0048** | **0.018 ر.س** | الأساس المعتمد للوحدة (COGS) |
| **تكلفة الشركة في الفترة التجريبية (5 أيام)** | **$0.60** | **2.25 ر.س** | سقف 125 منشور كحد أقصى |
| **تكلفة 100 شركة في الفترة التجريبية** | **$60.00** | **225.00 ر.س** | معدل أمان مالي 100% |

---

## 8. سجل الملفات المعدلة والمنشأة

### أ) ملفات تم إنشاؤها (New Files):
1. [`database/migrations/2026_10_08_220000_update_plans_and_tenants_and_create_plan_requests_table.php`](file:///c:/Users/workstation/Documents/sada/database/migrations/2026_10_08_220000_update_plans_and_tenants_and_create_plan_requests_table.php): هجرة الجداول لإضافة حقول الباقات والتجربة وجدول طلبات الباقات.
2. [`app/Models/PlanRequest.php`](file:///c:/Users/workstation/Documents/sada/app/Models/PlanRequest.php): نموذج طلب الباقات.
3. [`app/Http/Controllers/Api/V1/PlanRequestController.php`](file:///c:/Users/workstation/Documents/sada/app/Http/Controllers/Api/V1/PlanRequestController.php): إدارة طلبات الباقات للعميل والسوبر أدمن.
4. [`app/Http/Middleware/EnsureFeatureAccess.php`](file:///c:/Users/workstation/Documents/sada/app/Http/Middleware/EnsureFeatureAccess.php): ميدلوير التحقق من الميزات المدفوعة مثل الأخبار.
5. [`tests/Feature/SaaS/CompanyApprovalAndTrialFlowTest.php`](file:///c:/Users/workstation/Documents/sada/tests/Feature/SaaS/CompanyApprovalAndTrialFlowTest.php): اختبارات شاملة لتدفق الموافقة والتجربة والباقات.
6. [`public/financial_analysis_and_pricing_strategy.html`](file:///c:/Users/workstation/Documents/sada/public/financial_analysis_and_pricing_strategy.html): تقرير التحليل المالي التنفيذي بصيغة HTML باللغة العربية.
7. [`FINANCIAL_ANALYSIS_AND_PRICING_STRATEGY.html`](file:///c:/Users/workstation/Documents/sada/FINANCIAL_ANALYSIS_AND_PRICING_STRATEGY.html): نسخة من التقرير المالي في جذر المشروع.
8. [`public/FRONTEND_PLANS_TRIAL_NEWS_INTEGRATION_GUIDE.md`](file:///c:/Users/workstation/Documents/sada/public/FRONTEND_PLANS_TRIAL_NEWS_INTEGRATION_GUIDE.md): دليل تكامل الفرونت إند المفصل للواجهات.
9. [`UPDATES_AND_CHANGELOG.md`](file:///c:/Users/workstation/Documents/sada/UPDATES_AND_CHANGELOG.md): هذا الملف الشامل لجميع التعديلات.

### ب) ملفات تم تعديلها وتطويرها (Modified Files):
1. [`app/Services/ApifyScraperService.php`](file:///c:/Users/workstation/Documents/sada/app/Services/ApifyScraperService.php): إضافة كاش 6 ساعات، وإلغاء استعلام الهاشتاقات في إنستغرام لخفض التكلفة 96.7%.
2. [`app/Jobs/ScrapeKeywordsJob.php`](file:///c:/Users/workstation/Documents/sada/app/Jobs/ScrapeKeywordsJob.php): منع تكرار كشط ومعالجة المقالات الموجودة مسبقاً.
3. [`database/seeders/PlanSeeder.php`](file:///c:/Users/workstation/Documents/sada/database/seeders/PlanSeeder.php): تحديث أسعار وحدود الباقات الجديدة وزرعها.
4. [`app/Actions/Auth/RegisterUser.php`](file:///c:/Users/workstation/Documents/sada/app/Actions/Auth/RegisterUser.php): تعيين حالة الشركة الجديدة لتكون `suspended`.
5. [`app/Http/Controllers/Api/V1/SuperAdminController.php`](file:///c:/Users/workstation/Documents/sada/app/Http/Controllers/Api/V1/SuperAdminController.php): إضافة دالة `approveTenant` لتفعيل تجربة الـ 5 أيام.
6. [`app/Http/Controllers/Api/V1/CollectionController.php`](file:///c:/Users/workstation/Documents/sada/app/Http/Controllers/Api/V1/CollectionController.php): تطبيق سقف الـ 5 حملات و 25 تعليق في الفترة التجريبية.
7. [`app/Http/Resources/PlanResource.php`](file:///c:/Users/workstation/Documents/sada/app/Http/Resources/PlanResource.php): إرجاع حقول `has_news` و `limits`.
8. [`app/Models/Plan.php`](file:///c:/Users/workstation/Documents/sada/app/Models/Plan.php): إضافة الحقول الجديدة والـ Casts.
9. [`app/Models/Tenant.php`](file:///c:/Users/workstation/Documents/sada/app/Models/Tenant.php): إضافة علاقات وحقول التجربة والطلبات.
10. [`routes/api/v1.php`](file:///c:/Users/workstation/Documents/sada/routes/api/v1.php): تسجيل مسارات الموافقة، طلبات الباقات، وحماية مسار الأخبار.
11. [`bootstrap/app.php`](file:///c:/Users/workstation/Documents/sada/bootstrap/app.php): تسجيل ميدلوير الميزات `feature`.
12. [`lang/ar/messages.php`](file:///c:/Users/workstation/Documents/sada/lang/ar/messages.php) & [`lang/en/messages.php`](file:///c:/Users/workstation/Documents/sada/lang/en/messages.php): إضافة نصوص ورسائل الخطأ والنجاح باللغتين.
