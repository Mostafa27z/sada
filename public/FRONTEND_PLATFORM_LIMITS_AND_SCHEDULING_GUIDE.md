# Frontend Integration Guide: Per-Platform Article Limits & Daily Scheduling

> **Target Audience:** Frontend AI Agent & Frontend Developers  
> **Backend Version:** Sada API v1  
> **Last Updated:** October 2026

---

## 1. Executive Summary

This update introduces two major architectural enhancements:
1. **Daily Scheduling:**
   * Automated scraping and syncing (`sada:sync-all`) now runs **once daily at midnight (00:00 KSA)** instead of every 4 hours.
   * `sada:scrape-keywords` has been merged into `sada:sync-all` and removed from the recurring daily schedule.
2. **User-Selected Per-Platform Article Limits (`platform_limits`):**
   * Users can now customize how many articles/posts they want to scrape for **each platform individually** (e.g., 50 for X, 30 for Instagram, 20 for TikTok).
   * **Plan Limits Protection:** The total sum of requested articles across all platforms is strictly capped by the user's active plan limit (`max_articles_per_campaign`) and remaining monthly allowance.

---

## 2. Plan Limits Reference

Every campaign's total articles (sum of all platform limits) is restricted according to the tenant's plan tier:

| Plan Tier | Max Articles per Campaign (`max_articles_per_campaign`) | Monthly Quota (`max_articles`) |
| :--- | :---: | :---: |
| **5-Day Free Trial** | **25 articles max** | 125 lifetime |
| **Basic Plan** | **100 articles max** | 3,000 / month |
| **Pro Plan** | **400 articles max** | 12,000 / month |
| **Enterprise Plan** | **1,000 articles max** | 35,000 / month |

> [!IMPORTANT]
> If a user requests a total exceeding their plan limit, the backend automatically clamps the values proportionally so the campaign creation succeeds safely without failing or exceeding quotas.
> You can also enforce this client-side in the UI using the tenant's plan limit.

---

## 3. TypeScript Interfaces

```typescript
export type Platform = 'x' | 'instagram' | 'facebook' | 'tiktok' | 'web';

export interface PlatformLimits {
  x?: number;
  instagram?: number;
  facebook?: number;
  tiktok?: number;
  web?: number;
}

export interface StoreCollectionPayload {
  name: string;
  description?: string;
  keywords?: string[]; // e.g. ["سدايا", "الذكاء الاصطناعي"]
  platforms?: Platform[]; // e.g. ["x", "instagram", "tiktok"]
  
  // Option A: Explicit breakdown per platform (Recommended)
  platform_limits?: PlatformLimits; // e.g. { "x": 50, "instagram": 30, "tiktok": 20 }
  
  // Option B: Uniform limit applied to each selected platform
  platform_limit?: number; // e.g. 20 (if 3 platforms selected = 60 total)
  
  // Option C: Legacy global limit (divided automatically among platforms)
  comments_limit?: number | string; // e.g. 100
  
  country?: string; // Default "SA"
  date_from?: string; // e.g. "2026-10-01"
  date_to?: string; // e.g. "2026-10-16"
  sentiment_rubric?: Record<string, string>;
}

export interface CollectionResponse {
  id: number;
  name: string;
  description?: string;
  platforms: Platform[];
  platform_limits: PlatformLimits | null; // <-- NEW
  comments_limit: string; // Total count (e.g. "100")
  status: 'pending' | 'completed' | 'failed' | 'scheduled';
  results_count: number;
  articles_count: number;
  comments_count: number;
  positive_count: number;
  neutral_count: number;
  negative_count: number;
  created_at: string;
}
```

---

## 4. API Endpoints & Request Examples

### A. Creating a Campaign with Per-Platform Limits
**Endpoint:** `POST /api/v1/collections`  
**Headers:** `Authorization: Bearer <token>`, `Accept: application/json`

#### Request Payload (Option 1: Recommended Custom Breakdown):
```json
{
  "name": "حملة رصد قطاع التقنية",
  "keywords": ["التحول الرقمي", "سدايا"],
  "platforms": ["x", "instagram", "tiktok"],
  "platform_limits": {
    "x": 50,
    "instagram": 30,
    "tiktok": 20
  },
  "country": "SA",
  "date_from": "2026-10-01",
  "date_to": "2026-10-16"
}
```

#### Request Payload (Option 2: Uniform per-platform limit):
```json
{
  "name": "حملة رصد وزارة الصحة",
  "keywords": ["مستشفيات تبوك"],
  "platforms": ["x", "facebook"],
  "platform_limit": 25,
  "country": "SA"
}
```

#### Response (HTTP 201 Created):
```json
{
  "data": {
    "id": 15,
    "name": "حملة رصد قطاع التقنية",
    "description": null,
    "link": "",
    "platform": "x,instagram,tiktok",
    "platforms": ["x", "instagram", "tiktok"],
    "platform_limits": {
      "x": 50,
      "instagram": 30,
      "tiktok": 20
    },
    "comments_limit": "100",
    "country": "SA",
    "status": "pending",
    "results_count": 0,
    "articles_count": 0,
    "created_at": "2026-10-16T22:45:00.000000Z"
  }
}
```

---

### B. Updating an Existing Campaign
**Endpoint:** `PUT /api/v1/collections/{id}`  
```json
{
  "name": "حملة رصد قطاع التقنية - محدثة",
  "platforms": ["x", "instagram"],
  "platform_limits": {
    "x": 60,
    "instagram": 40
  }
}
```

---

### C. Triggering Keyword Scraping Directly
**Endpoint:** `POST /api/v1/keywords/scrape`  
```json
{
  "keywords": ["الذكاء الاصطناعي"],
  "platforms": ["x", "instagram", "tiktok"],
  "platform_limits": {
    "x": 40,
    "instagram": 20,
    "tiktok": 20
  },
  "country": "SA"
}
```

---

## 5. Frontend UI/UX Recommendations

### 1. Per-Platform Limit Inputs inside Campaign Modal
When the user checks platform checkboxes in the "Create Campaign / Collection" modal:
* Dynamically display an input/slider next to or under each checked platform:
  * **X (Twitter):** `[ 50 ]` مقال
  * **Instagram:** `[ 30 ]` مقال
  * **TikTok:** `[ 20 ]` مقال
* Display a **Total Counter badge**:
  ```
  إجمالي المقالات المطلوبة: 100 / 100 (الحد الأقصى لباقاتكم: 100)
  ```
* If the user selects numbers whose sum exceeds their plan limit, display a helpful hint:
  ```
  تنبيه: الحد الأقصى لباقة شركتكم هو 100 مقال لكل حملة. سيتم ضبط الأرقام تلقائياً لتناسب باقتكم.
  ```

### 2. Campaign Card & Details View
* On campaign cards or analytics drawers, show the platform limits tags:
  * `X: 50` | `Instagram: 30` | `TikTok: 20`
* In campaign status:
  * The automated sync now runs **daily at 00:00 midnight**, so you can display:
    ```
    المزامنة القادمة: يومياً الساعة 12:00 منتصف الليل
    ```

---

## 6. Backward Compatibility
* Existing campaigns that do not have `platform_limits` continue to work normally using `comments_limit`.
* If a frontend sends only `comments_limit` (e.g. `comments_limit = 50`), the backend automatically splits it evenly across the selected platforms.

---

## 7. Multi-Platform Independent Scraping (No Fallback Overwriting)

### Architecture Principles:
1. **Zero Cross-Platform Fallback:**
   * Platforms never substitute for one another. If 20 articles are requested for Twitter and 20 for Facebook, finding 20 Twitter articles will **never** stop Facebook scraping or consume Facebook's quota.
   * Every platform selected (`x`, `facebook`, `instagram`, `tiktok`, `web`) is queried and harvested independently up to its defined limit.
2. **Accumulative Storage (No Detachment Overwriting):**
   * Multi-keyword campaigns sync articles using `syncWithoutDetaching`, ensuring that subsequent keyword scraping runs accumulate new articles without overwriting or deleting articles from earlier platforms or keywords.
3. **Unified Display in Collection Articles:**
   * In `GET /api/v1/collections/{id}`, all scraped articles from all platforms appear together chronologically.
   * Each article's platform is identified in `raw_data.platform` and `platform`. Frontend tabs/filters can easily group them:
     ```typescript
     const xArticles = collection.articles.filter(a => a.raw_data?.platform === 'x');
     const fbArticles = collection.articles.filter(a => a.raw_data?.platform === 'facebook');
     const igArticles = collection.articles.filter(a => a.raw_data?.platform === 'instagram');
     const ttArticles = collection.articles.filter(a => a.raw_data?.platform === 'tiktok');
     const webArticles = collection.articles.filter(a => a.raw_data?.platform === 'web');
     ```

