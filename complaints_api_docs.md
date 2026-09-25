# Complaints, AI Insights & QR Code API Documentation

This document describes the design, database schema, AI processing flow, API endpoints, and test `curl` commands for the Customer Complaints, Gemini AI Analysis, and QR Code Module.

---

## Architecture & Data Flow Logic

```
┌─────────────────┐         ┌────────────────────────┐         ┌─────────────────────────┐
│   Company Admin │ ──────> │ GET /public/qr-code    │ ──────> │ QR Code Image / Data    │
│  (Frontend App) │         │ (target redirection URL│         │ (Encodes Complaint Form)│
└─────────────────┘         └────────────────────────┘         └─────────────────────────┘
                                                                            │
                                                                            ▼
┌─────────────────┐         ┌────────────────────────┐         ┌─────────────────────────┐
│ End-User /      │ ──────> │ Scans QR Code & opens  │ ──────> │ POST /public/complaints │
│ Customer        │         │ Frontend Complaint Form│         │ (name, email, rate 1-5, │
└─────────────────┘         └────────────────────────┘         │  phone, opinion)        │
                                                               └─────────────────────────┘
                                                                            │
                                                                            ▼
                                                                (Asynchronous Background)
                                                               ┌─────────────────────────┐
                                                               │  ProcessComplaintAiJob  │
                                                               └─────────────────────────┘
                                                                            │
                                           ┌────────────────────────────────┴────────────────────────────────┐
                                           ▼                                                                 ▼
                            ┌─────────────────────────────┐                                   ┌─────────────────────────────┐
                            │ 1. AI Sentiment/Priority    │                                   │ 2. Incremental Summary      │
                            │    & Handling Recommendation│                                   │    & Action Solutions       │
                            │    ('positive'/'neutral'/   │                                   │    (`tenant_complaint_    │
                            │     'negative'/'crisis')    │                                   │     summaries` table)     │
                            └─────────────────────────────┘                                   └─────────────────────────────┘
                                           │                                                                 │
                                           ▼                                                                 ▼
                            ┌─────────────────────────────┐                                   ┌─────────────────────────────┐
                            │ GET /api/v1/complaints      │                                   │ GET /api/v1/complaints/     │
                            │ (Includes AI fields)        │                                   │     summary                 │
                            └─────────────────────────────┘                                   └─────────────────────────────┘
```

---

## Database Schemas

### 1. `complaints` table

| Column | Type | Attributes | Description |
| :--- | :--- | :--- | :--- |
| `id` | BigInteger | Primary Key, Auto Increment | Unique record ID |
| `tenant_id` | Foreign Key | Foreign key to `tenants.id`, indexed | Target company/tenant |
| `name` | String | Required | Complainant's full name |
| `email` | String | Required | Complainant's email address |
| `phone` | String | Nullable | Complainant's phone number |
| `opinion` | Text | Required | Complaint content/details |
| `rate` | TinyInteger | Unsigned, Required (1 to 5) | Customer satisfaction score |
| `priority` | String | Default: `'neutral'` | AI Priority (`positive`, `neutral`, `negative`, `crisis`) |
| `ai_recommendation` | Text | Nullable | Actionable AI recommendation for support agents |
| `status` | String | Default: `'pending'` | Resolution status (`pending`, `in_progress`, `resolved`, `closed`) |
| `created_at` | Timestamp | Nullable | Creation timestamp |
| `updated_at` | Timestamp | Nullable | Last update timestamp |

---

### 2. `tenant_complaint_summaries` table

| Column | Type | Attributes | Description |
| :--- | :--- | :--- | :--- |
| `id` | BigInteger | Primary Key, Auto Increment | Unique record ID |
| `tenant_id` | Foreign Key | Unique foreign key to `tenants.id` | Target company/tenant |
| `summary` | Text | Nullable | Overall AI executive summary of complaint trends |
| `recommended_solutions` | JSON | Nullable | List of 3-5 recommended solutions for management |
| `total_complaints_analyzed` | Integer | Default `0` | Count of total complaints analyzed |
| `last_complaint_id` | Foreign Key | Nullable foreign key to `complaints.id` | Last processed complaint |
| `updated_at` | Timestamp | Nullable | Timestamp of last AI summary refresh |

---

## API Endpoints Reference

### 1. Generate QR Code (Public)
Generates a QR code redirecting to the frontend complaint form.

- **URL**: `GET /api/v1/public/qr-code`
- **Authentication**: None (Public)
- **Query Parameters**:
  - `url` (string, required): Full target redirection URL.
  - `format` (string, optional): `svg` (default) or `json`/`base64`.
  - `size` (integer, optional): Pixel dimension (default: `300`).

---

### 2. Submit Complaint with Rating (Public)
Submits a public customer complaint for a company.

- **URL**: `POST /api/v1/public/complaints`
- **Authentication**: None (Public)
- **Request Body** (`application/json`):
```json
{
  "tenant_id": 1,
  "name": "Ahmed Hassan",
  "email": "ahmed@example.com",
  "phone": "+201012345678",
  "opinion": "I experienced a delay in receiving my order confirmation and support was unhelpful.",
  "rate": 1
}
```

---

### 3. List Tenant Complaints (Authenticated Tenant)
Retrieves a paginated list of complaints for the active tenant.

- **URL**: `GET /api/v1/complaints`
- **Authentication**: `Bearer <TOKEN>`
- **Headers**: `X-Tenant-ID: <TENANT_ID>`
- **Query Parameters**:
  - `status` (string, optional): Filter by status (`pending`, `in_progress`, `resolved`, `closed`).
  - `priority` (string, optional): Filter by AI priority (`positive`, `neutral`, `negative`, `crisis`).
  - `rate` (integer, optional): Filter by rating (1 to 5).
  - `search` (string, optional): Search keyword matching `name`, `email`, `phone`, or `opinion`.
  - `per_page` (integer, optional): Items per page (default: `20`).

---

### 4. Get Tenant Complaint AI Summary & Solutions (Authenticated Tenant)
Returns the aggregated AI summary and recommended action plan for management.

- **URL**: `GET /api/v1/complaints/summary`
- **Authentication**: `Bearer <TOKEN>`
- **Headers**: `X-Tenant-ID: <TENANT_ID>`
- **Response** (`200 OK`):
```json
{
  "success": true,
  "message": null,
  "data": {
    "id": 1,
    "tenant_id": 1,
    "summary": "The majority of recent customer complaints revolve around order dispatch delays and delayed customer support responses.",
    "recommended_solutions": [
      "Set up automated email notifications for order status updates.",
      "Increase customer service staff during peak hours (12 PM - 5 PM).",
      "Implement a priority response protocol for customer ratings of 1 or 2."
    ],
    "total_complaints_analyzed": 14,
    "last_complaint_id": 42,
    "updated_at": "2026-09-25T08:15:00.000000Z"
  }
}
```

---

### 5. On-Demand Regenerate Tenant AI Summary (Authenticated Tenant)
Triggers an immediate recalculation of the tenant AI summary.

- **URL**: `POST /api/v1/complaints/summary/regenerate`
- **Authentication**: `Bearer <TOKEN>`
- **Headers**: `X-Tenant-ID: <TENANT_ID>`

---

### 6. Get Single Complaint Details (Authenticated Tenant)
- **URL**: `GET /api/v1/complaints/{id}`
- **Authentication**: `Bearer <TOKEN>`
- **Headers**: `X-Tenant-ID: <TENANT_ID>`

---

### 7. Update Complaint Status (Authenticated Tenant)
- **URL**: `PATCH /api/v1/complaints/{id}/status`
- **Authentication**: `Bearer <TOKEN>`
- **Headers**: `X-Tenant-ID: <TENANT_ID>`
- **Request Body** (`application/json`):
```json
{
  "status": "resolved"
}
```

---

### 8. Delete Complaint (Authenticated Tenant)
- **URL**: `DELETE /api/v1/complaints/{id}`
- **Authentication**: `Bearer <TOKEN>`
- **Headers**: `X-Tenant-ID: <TENANT_ID>`

---

## Ready-to-use cURL Commands

### 1. Submit Public Complaint with Rating (1-5)
```bash
curl -X POST "http://localhost:8000/api/v1/public/complaints" \
     -H "Content-Type: application/json" \
     -H "Accept: application/json" \
     -d '{
           "tenant_id": 1,
           "name": "Ahmed Hassan",
           "email": "ahmed@example.com",
           "phone": "+201012345678",
           "opinion": "Order feedback: The delivery was delayed by 2 days and support was unreachable.",
           "rate": 1
         }'
```

### 2. Get Aggregated Tenant AI Summary & Solutions
```bash
curl -X GET "http://localhost:8000/api/v1/complaints/summary" \
     -H "Authorization: Bearer YOUR_SANCTUM_TOKEN" \
     -H "X-Tenant-ID: 1" \
     -H "Accept: application/json"
```

### 3. Force Regenerate Tenant AI Summary
```bash
curl -X POST "http://localhost:8000/api/v1/complaints/summary/regenerate" \
     -H "Authorization: Bearer YOUR_SANCTUM_TOKEN" \
     -H "X-Tenant-ID: 1" \
     -H "Accept: application/json"
```

### 4. List Tenant Complaints (Filtered by Priority & Rating)
```bash
curl -X GET "http://localhost:8000/api/v1/complaints?priority=crisis&rate=1&per_page=15" \
     -H "Authorization: Bearer YOUR_SANCTUM_TOKEN" \
     -H "X-Tenant-ID: 1" \
     -H "Accept: application/json"
```

### 5. Update Complaint Status to Resolved
```bash
curl -X PATCH "http://localhost:8000/api/v1/complaints/1/status" \
     -H "Authorization: Bearer YOUR_SANCTUM_TOKEN" \
     -H "X-Tenant-ID: 1" \
     -H "Content-Type: application/json" \
     -H "Accept: application/json" \
     -d '{
           "status": "resolved"
         }'
```
