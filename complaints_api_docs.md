# Complaints & QR Code API Documentation

This document describes the design, database schema, data flow, API endpoints, and test `curl` commands for the Customer Complaints and QR Code Redirection Module.

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
│ Customer        │         │ Frontend Complaint Form│         │ (name, email, phone...) │
└─────────────────┘         └────────────────────────┘         └─────────────────────────┘
                                                                            │
                                                                            ▼
┌─────────────────┐         ┌────────────────────────┐         ┌─────────────────────────┐
│   Company Admin │ ──────> │ GET /api/v1/complaints │ <────── │ Saved in `complaints`   │
│  (Dashboard)    │         │ (Tenant Authenticated) │         │ DB table (tenant_id)    │
└─────────────────┘         └────────────────────────┘         └─────────────────────────┘
```

1. **Company QR Code Generation**:
   - Each company/tenant can request a QR code encoding their complaint submission link (e.g. `https://myfrontend.com/complaint?tenant_id=123`).
   - The endpoint generates a vector SVG image directly or a JSON payload containing base64 data.

2. **Public Complaint Submission**:
   - End-users submit complaints through the public page.
   - The API receives `name`, `email`, `phone`, `opinion`, and `tenant_id`.
   - The complaint is stored in the database with an initial status of `pending`.

3. **Tenant Complaints Management**:
   - Tenant administrators authenticate via Sanctum (`Bearer <TOKEN>`) and pass their workspace ID header (`X-Tenant-ID: <TENANT_ID>`).
   - Tenant scope (`BelongsToTenant` trait) automatically isolates records per company.
   - Admins can list complaints, search/filter, update resolution status (`pending`, `in_progress`, `resolved`, `closed`), or delete complaints.

---

## Database Schema (`complaints` table)

| Column | Type | Attributes | Description |
| :--- | :--- | :--- | :--- |
| `id` | BigInteger | Primary Key, Auto Increment | Unique record ID |
| `tenant_id` | Foreign Key | Foreign key to `tenants.id`, indexed | Target company/tenant |
| `name` | String | Required | Complainant's full name |
| `email` | String | Required | Complainant's email address |
| `phone` | String | Nullable | Complainant's phone number |
| `opinion` | Text | Required | Complaint content/details |
| `status` | String | Default: `'pending'` | Resolution status (`pending`, `in_progress`, `resolved`, `closed`) |
| `created_at` | Timestamp | Nullable | Creation timestamp |
| `updated_at` | Timestamp | Nullable | Last update timestamp |

---

## API Endpoints Reference

### 1. Generate QR Code (Public)
Generates a QR code redirecting to the frontend complaint form.

- **URL**: `GET /api/v1/public/qr-code`
- **Authentication**: None (Public)
- **Query Parameters**:
  - `url` (string, required): Full target redirection URL (must be valid URL format).
  - `format` (string, optional): `svg` (default, returns `image/svg+xml`), `json` or `base64` (returns JSON payload).
  - `size` (integer, optional): Pixel dimension (default: `300`).

---

### 2. Submit Complaint (Public)
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
  "opinion": "I experienced a delay in receiving my order confirmation."
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
  - `search` (string, optional): Search keyword matching `name`, `email`, `phone`, or `opinion`.
  - `per_page` (integer, optional): Items per page (default: `20`).

---

### 4. Get Single Complaint Details (Authenticated Tenant)
- **URL**: `GET /api/v1/complaints/{id}`
- **Authentication**: `Bearer <TOKEN>`
- **Headers**: `X-Tenant-ID: <TENANT_ID>`

---

### 5. Update Complaint Status (Authenticated Tenant)
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

### 6. Delete Complaint (Authenticated Tenant)
- **URL**: `DELETE /api/v1/complaints/{id}`
- **Authentication**: `Bearer <TOKEN>`
- **Headers**: `X-Tenant-ID: <TENANT_ID>`

---

## Ready-to-use cURL Commands

### 1. Generate SVG QR Code (Direct Image Response)
```bash
curl -X GET "http://localhost:8000/api/v1/public/qr-code?url=https://myfrontend.com/complaint?tenant_id=1&size=300" \
     --output qrcode.svg
```

### 2. Generate Base64 JSON QR Code
```bash
curl -X GET "http://localhost:8000/api/v1/public/qr-code?url=https://myfrontend.com/complaint?tenant_id=1&format=json" \
     -H "Accept: application/json"
```

### 3. Submit a Public Complaint
```bash
curl -X POST "http://localhost:8000/api/v1/public/complaints" \
     -H "Content-Type: application/json" \
     -H "Accept: application/json" \
     -d '{
           "tenant_id": 1,
           "name": "Ahmed Hassan",
           "email": "ahmed@example.com",
           "phone": "+201012345678",
           "opinion": "Order feedback: The delivery was delayed by 2 days."
         }'
```

### 4. List Tenant Complaints (Authenticated Tenant Admin)
```bash
curl -X GET "http://localhost:8000/api/v1/complaints?status=pending&per_page=15" \
     -H "Authorization: Bearer YOUR_SANCTUM_TOKEN" \
     -H "X-Tenant-ID: 1" \
     -H "Accept: application/json"
```

### 5. View Specific Complaint Details
```bash
curl -X GET "http://localhost:8000/api/v1/complaints/1" \
     -H "Authorization: Bearer YOUR_SANCTUM_TOKEN" \
     -H "X-Tenant-ID: 1" \
     -H "Accept: application/json"
```

### 6. Update Complaint Status to Resolved
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

### 7. Delete a Complaint
```bash
curl -X DELETE "http://localhost:8000/api/v1/complaints/1" \
     -H "Authorization: Bearer YOUR_SANCTUM_TOKEN" \
     -H "X-Tenant-ID: 1" \
     -H "Accept: application/json"
```
