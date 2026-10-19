# Frontend Integration Guide: Google Maps Reviews & Locations

> **Target Audience:** Frontend AI Agent & Frontend Developers  
> **Backend Version:** Sada API v1  
> **Feature:** Google Maps Reviews Management (GBP OAuth + Apify Fallback)  
> **Last Updated:** October 2026

---

## 1. Feature Overview & User Journey

This feature empowers tenants to track, analyze, and respond to Google Maps reviews for their branches, stores, or monitored locations.

### Two Modes of Operation (Handled Automatically by Backend):
1. **Google Business Profile (GBP) Mode (`provider: "gbp"`):**
   * Tenant authenticates their Google Account via OAuth.
   * Enables **two-way interaction**: reading reviews AND posting replies directly from the app.
   * Free & instant sync.
2. **Apify Scraping Fallback (`provider: "apify"`):**
   * Tenant simply pastes any public Google Maps URL or search term (e.g. competitor or unverified location).
   * **Read-only**: reviews and ratings are scraped and synced periodically.
   * The "Reply" action is disabled with an explanatory tooltip.

---

## 2. TypeScript Data Models & Types

```typescript
export type LocationProvider = 'gbp' | 'apify';
export type SyncStatus = 'idle' | 'pending' | 'syncing' | 'failed' | 'completed';

export interface TenantLocation {
  id: number;
  tenant_id: number;
  business_name: string;
  address?: string;
  google_place_id?: string;
  maps_url: string;
  provider: LocationProvider;
  is_verified_owner: boolean;
  can_reply: boolean; // TRUE only if provider === 'gbp' with valid OAuth
  sync_status: SyncStatus;
  last_synced_at: string | null; // ISO 8601 string
  stats: {
    total_reviews: number;
    average_rating: number; // e.g. 4.6
    rating_distribution: {
      1: number;
      2: number;
      3: number;
      4: number;
      5: number;
    };
    unanswered_reviews: number;
  };
  created_at: string;
}

export interface LocationReview {
  id: number;
  tenant_location_id: number;
  google_review_id: string;
  author_name: string;
  author_photo_url: string | null;
  rating: 1 | 2 | 3 | 4 | 5;
  text: string | null;
  published_at: string; // ISO 8601 string
  relative_time_description?: string; // e.g. "3 days ago"
  likes_count: number;
  response: {
    text: string | null;
    published_at: string | null;
    is_responded: boolean;
  };
  source: LocationProvider;
}

export interface ReviewFilters {
  page?: number;
  per_page?: number;
  rating?: number; // 1 to 5
  status?: 'all' | 'responded' | 'unresponded';
  sort_by?: 'newest' | 'highest_rating' | 'lowest_rating';
  search?: string;
}

export interface AddLocationPayload {
  maps_url: string;
  business_name?: string;
  google_place_id?: string;
}

export interface PostReplyPayload {
  reply_text: string;
}
```

---

## 3. API Endpoints Reference

All endpoints require the standard Tenant Bearer Token header:
```http
Authorization: Bearer <tenant_token>
Accept: application/json
```

### 3.1 Locations Management

#### `GET /api/v1/tenant/locations`
Fetch all locations configured for the current tenant.
* **Response (200 OK):**
```json
{
  "success": true,
  "data": [
    {
      "id": 12,
      "tenant_id": 4,
      "business_name": "Sada Roastery - Riyadh Olaya",
      "address": "King Fahd Rd, Al Olaya, Riyadh",
      "google_place_id": "ChIJN1t_tDeuEmsRUsoyG83frY4",
      "maps_url": "https://maps.app.goo.gl/xyz123",
      "provider": "gbp",
      "is_verified_owner": true,
      "can_reply": true,
      "sync_status": "idle",
      "last_synced_at": "2026-10-19T20:00:00Z",
      "stats": {
        "total_reviews": 142,
        "average_rating": 4.7,
        "rating_distribution": { "1": 2, "2": 3, "3": 10, "4": 35, "5": 92 },
        "unanswered_reviews": 4
      }
    }
  ]
}
```

#### `POST /api/v1/tenant/locations`
Add a new location to monitor via public Google Maps link.
* **Payload:**
```json
{
  "maps_url": "https://maps.app.goo.gl/abc789",
  "business_name": "Optional Custom Name"
}
```
* **Response (201 Created):** Returns the newly created `TenantLocation` object with `sync_status: "pending"`. The backend triggers an initial background sync immediately.

#### `DELETE /api/v1/tenant/locations/{id}`
Remove a location and its stored reviews from the tenant.

---

### 3.2 Google Business Profile (OAuth Connect)

#### `GET /api/v1/tenant/locations/google/connect-url`
Returns the Google OAuth consent URL. The frontend opens this in a popup or redirects the user.
* **Response (200 OK):**
```json
{
  "success": true,
  "auth_url": "https://accounts.google.com/o/oauth2/v2/auth?client_id=...&scope=https://www.googleapis.com/auth/business.manage..."
}
```

#### `POST /api/v1/tenant/locations/google/callback`
Send the `code` received from the OAuth redirect to complete the connection:
```json
{
  "code": "4/0AeanS0...",
  "state": "..."
}
```

---

### 3.3 Reviews & Actions

#### `GET /api/v1/tenant/locations/{location_id}/reviews`
Fetch paginated reviews for a specific location.
* **Query Parameters:** `?page=1&per_page=15&rating=1&status=unresponded&sort_by=newest`
* **Response (200 OK):**
```json
{
  "success": true,
  "data": [
    {
      "id": 412,
      "tenant_location_id": 12,
      "google_review_id": "ChZDSUhNMG9nS0VJQ0FnSUN4eE9EZFlREAE",
      "author_name": "Ahmed Al-Mansoor",
      "author_photo_url": "https://lh3.googleusercontent.com/a/photo.jpg",
      "rating": 5,
      "text": "Best specialty coffee in Olaya. The seating area is calm and very welcoming.",
      "published_at": "2026-10-18T14:30:00Z",
      "relative_time_description": "Yesterday",
      "likes_count": 3,
      "response": {
        "text": "Thank you Ahmed! We are glad you enjoyed the ambiance.",
        "published_at": "2026-10-18T16:00:00Z",
        "is_responded": true
      },
      "source": "gbp"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 10,
    "total": 142
  }
}
```

#### `POST /api/v1/tenant/locations/{location_id}/sync`
Manually trigger an on-demand sync for a location.
* **Response (200 OK):**
```json
{
  "success": true,
  "message": "Sync job dispatched successfully",
  "sync_status": "syncing"
}
```

#### `POST /api/v1/tenant/locations/{location_id}/reviews/{review_id}/reply`
Submit or edit a response to a review (Only works when `can_reply: true`).
* **Payload:**
```json
{
  "reply_text": "Thank you for visiting us! We appreciate your feedback."
}
```
* **Response (200 OK):**
```json
{
  "success": true,
  "message": "Reply posted successfully",
  "data": {
    "response": {
      "text": "Thank you for visiting us! We appreciate your feedback.",
      "published_at": "2026-10-19T21:45:00Z",
      "is_responded": true
    }
  }
}
```
* **Error Response (403 Forbidden):**
```json
{
  "success": false,
  "error": "REPLY_UNAVAILABLE",
  "message": "Posting replies requires connecting this location with an authorized Google Business Profile account."
}
```

---

## 4. UI / UX Design Specifications

### Component 1: Location Header & Status Badges
Display provider and sync state prominently:
* **GBP Verified Badge:** Green pill `● Google Verified (Two-way Reply Active)`
* **Apify Scraped Badge:** Gray/Blue pill `● Scraped via Google Maps (Read-Only)`
* **Sync Button:**
  * When `sync_status === 'syncing'`: Show spinning icon with label `"Syncing reviews..."` (disable button).
  * Auto-poll location status every 5 seconds until `sync_status` returns to `idle` or `completed`.

### Component 2: Add Location Modal
Provide two tabs:
1. **Tab 1: Connect with Google (Recommended for Owned Stores):**
   * Button: `"Sign in with Google Business"` ➔ Launches Google OAuth.
   * Auto-imports verified business locations directly.
2. **Tab 2: Paste Google Maps URL (For Competitors & Unverified):**
   * Input: Google Maps URL.
   * Helper text: `"Paste any public Google Maps link (e.g. https://maps.app.goo.gl/...) to track reviews."`

### Component 3: Review Item Card
* **Header:**
  * Author Avatar + Name.
  * Star Rating: 5 gold stars for 5/5, 1 red star for 1/5, etc.
  * Relative date (e.g., "2 days ago") + Tooltip with exact ISO date.
* **Body:**
  * Review text with `"Read more"` truncation if text exceeds 3 lines.
* **Owner Reply Section:**
  * If replied: Display a nested bubble containing the owner's response + reply timestamp.
  * If not replied:
    * If `can_reply === true`: Show a `"Reply"` button opening an inline textarea with `"Send Reply"`.
    * If `can_reply === false`: Show a subtle disabled badge or tooltip: `"Connect Google Business Profile to reply directly to this customer"`.

### Component 4: Analytics & Rating Breakdown Widget
* Top card displaying:
  * Overall average rating (e.g., `4.7 ★`).
  * Total reviews count.
  * Unanswered reviews count.
  * 5 horizontal bars showing count and percentage per star tier (1★ to 5★). Clicking any star bar filters the list below by that rating!

---

## 5. Error Handling & Edge Cases

| Scenario | UI Behavior |
| :--- | :--- |
| **User pastes an invalid link** | Show inline input validation error: *"Please enter a valid Google Maps link (e.g., maps.app.goo.gl or google.com/maps)"*. |
| **OAuth token expired or revoked** | The location badge transitions to `"Re-authentication Required"`. Clicking prompts the user to reconnect Google OAuth. |
| **Location currently syncing** | Display subtle progress bar or skeleton loaders on the reviews table/cards. |
| **Initial sync in progress (0 reviews yet)** | Empty state message: *"We are currently fetching historical reviews for this location. This usually takes 15–45 seconds. Feel free to refresh."* |
