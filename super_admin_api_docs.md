# Sada Platform - Super Admin API Documentation

> **Base URL**: `https://floralwhite-dolphin-332081.hostingersite.com/api/v1`  
> **Target Audience**: AI Agents & Frontend Developers building the Super Admin Dashboard.

---

## 1. Authentication & Base Headers

All requests (except public endpoints like `/health`, `/plans`, and `/auth/login`) require the **Bearer token** returned upon login.

### Standard Request Headers
```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer <SANCTUM_BEARER_TOKEN>
```

### Standard Response Envelope
```json
{
  "success": true,
  "message": "Operation completed successfully.",
  "data": { ... }
}
```

---

## 2. Authentication & Profile Endpoints

### 2.1 Login Super Admin
- **HTTP Method**: `POST`
- **Endpoint**: `/auth/login`
- **Request Body**:
```json
{
  "email": "superadmin@sada.com",
  "password": "YourPasswordHere"
}
```
- **Response `200 OK`**:
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "token": "1|abcdef1234567890...",
    "user": {
      "id": 1,
      "name": "Super Admin",
      "email": "superadmin@sada.com",
      "role": "super_admin",
      "status": "active",
      "current_tenant_id": 1
    }
  }
}
```

### 2.2 Get Super Admin Profile (`/me`)
- **HTTP Method**: `GET`
- **Endpoint**: `/me`
- **Response `200 OK`**:
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Super Admin",
    "email": "superadmin@sada.com",
    "phone": null,
    "avatar": null,
    "status": "active",
    "role": "super_admin",
    "companyName": "إدارة منصة صدى",
    "current_tenant_id": 1,
    "current_tenant": {
      "id": 1,
      "ulid": "01J85G8XXXX...",
      "name": "إدارة منصة صدى",
      "slug": "sada-system"
    }
  }
}
```

### 2.3 Logout
- **HTTP Method**: `POST`
- **Endpoint**: `/auth/logout`

---

## 3. Super Admin Specific Management Endpoints (`/admin`)

### 3.1 Get System-Wide Metrics & Statistics
Returns high-level statistics across all tenants, users, and scraped articles on the platform.

- **HTTP Method**: `GET`
- **Endpoint**: `/admin/metrics`
- **Response `200 OK`**:
```json
{
  "success": true,
  "data": {
    "tenants": {
      "total": 12,
      "active": 10
    },
    "articles": {
      "total": 142050
    },
    "users": {
      "total": 45
    }
  }
}
```

---

### 3.2 List All Platform Tenants (System-wide)
Lists all workspace tenants across the entire platform, including their current active subscription and plan details.

- **HTTP Method**: `GET`
- **Endpoint**: `/admin/tenants?page=1`
- **Response `200 OK`**:
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "ulid": "01J85G8XXXX...",
      "name": "شركة صدى للتقنية",
      "slug": "sada-tech",
      "logo": null,
      "status": "active",
      "is_owner": false,
      "settings": null,
      "created_at": "2026-09-20T14:22:02.000000Z",
      "updated_at": "2026-09-20T14:22:02.000000Z"
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

### 3.3 Directly Assign or Upgrade Tenant Plan
Allows a Super Admin to override or assign a SaaS plan directly to any tenant workspace.

- **HTTP Method**: `POST`
- **Endpoint**: `/admin/tenants/{tenantId}/plan`
- **Request Body**:
```json
{
  "plan_id": 2
}
```
- **Response `200 OK`**:
```json
{
  "success": true,
  "message": "Plan assigned to tenant successfully."
}
```

---

## 4. Tenant Workspaces Management

### 4.1 Get Specific Tenant Details
- **HTTP Method**: `GET`
- **Endpoint**: `/tenants/{id_or_ulid}`
- **Response `200 OK`**:
```json
{
  "success": true,
  "data": {
    "id": 1,
    "ulid": "01J85G8XXXX...",
    "name": "شركة صدى للتقنية",
    "slug": "sada-tech",
    "logo": "https://...",
    "status": "active",
    "settings": {
      "theme": "dark"
    },
    "created_at": "2026-09-20T14:22:02.000000Z"
  }
}
```

### 4.2 Create New Tenant Workspace
- **HTTP Method**: `POST`
- **Endpoint**: `/tenants`
- **Request Body**:
```json
{
  "name": "المؤسسة الوطنية للإعلام",
  "slug": "national-media",
  "logo": null,
  "settings": {}
}
```
- **Response `201 Created`**:
```json
{
  "success": true,
  "message": "Resource created successfully",
  "data": {
    "id": 2,
    "ulid": "01J85HX...",
    "name": "المؤسسة الوطنية للإعلام",
    "slug": "national-media",
    "status": "trial"
  }
}
```

### 4.3 Update Tenant Workspace
- **HTTP Method**: `PUT`
- **Endpoint**: `/tenants/{id_or_ulid}`
- **Request Body**:
```json
{
  "name": "شركة صدى للتقنية المتقدمة",
  "status": "active"
}
```

### 4.4 Switch Active Tenant Context
Super Admins can switch their active context into any tenant to view or audit tenant-level resources.
- **HTTP Method**: `POST`
- **Endpoint**: `/tenants/{id_or_ulid}/switch`

---

## 5. SaaS Plans Management

### 5.1 List All SaaS Plans
- **HTTP Method**: `GET`
- **Endpoint**: `/plans`
- **Response `200 OK`**:
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "الباقة الأساسية",
      "slug": "basic",
      "description": "مثالية للشركات الناشئة والمؤسسات الصغيرة",
      "price": 299,
      "currency": "SAR",
      "billing_interval": "monthly",
      "limits": {
        "max_users": 5,
        "max_keywords": 15,
        "max_sources": 50,
        "max_articles": 10000,
        "max_api_requests": 1000
      },
      "features": ["رصد المواقع الإخبارية", "تقارير أسبوعية"],
      "is_active": true
    },
    {
      "id": 2,
      "name": "الباقة الاحترافية",
      "slug": "pro",
      "price": 799,
      "currency": "SAR",
      "limits": {
        "max_users": 20,
        "max_keywords": 50,
        "max_sources": 200,
        "max_articles": 50000,
        "max_api_requests": 10000
      },
      "is_active": true
    },
    {
      "id": 3,
      "name": "باقة المؤسسات",
      "slug": "enterprise",
      "price": 2499,
      "currency": "SAR",
      "limits": {
        "max_users": 100,
        "max_keywords": 500,
        "max_sources": 1000,
        "max_articles": 500000,
        "max_api_requests": 100000
      },
      "is_active": true
    }
  ]
}
```

### 5.2 Get Plan Details
- **HTTP Method**: `GET`
- **Endpoint**: `/plans/{slug_or_id}`

---

## 6. User Management & Roles

### 6.1 List Tenant / Platform Users
- **HTTP Method**: `GET`
- **Endpoint**: `/users`

### 6.2 Invite User
- **HTTP Method**: `POST`
- **Endpoint**: `/users/invite`
- **Request Body**:
```json
{
  "email": "newadmin@company.com",
  "name": "محمد علي",
  "role_id": 3
}
```

### 6.3 Update User Status or Profile
- **HTTP Method**: `PUT`
- **Endpoint**: `/users/{userId}`
- **Request Body**:
```json
{
  "status": "suspended"
}
```

### 6.4 Assign Role to User
- **HTTP Method**: `POST`
- **Endpoint**: `/users/{userId}/roles`
- **Request Body**:
```json
{
  "role_id": 1
}
```

---

## 7. System Permissions & Roles Overview

### 7.1 List System Permissions
- **HTTP Method**: `GET`
- **Endpoint**: `/permissions`
- **Response `200 OK`**:
```json
{
  "success": true,
  "data": [
    { "slug": "view_dashboard", "name": "عرض لوحة التحكم", "category": "dashboard" },
    { "slug": "manage_keywords", "name": "إدارة الكلمات المفتاحية", "category": "keywords" },
    { "slug": "manage_sources", "name": "إدارة المصادر", "category": "sources" },
    { "slug": "manage_users", "name": "إدارة المستخدمين", "category": "users" },
    { "slug": "manage_billing", "name": "إدارة الاشتراكات والفواتير", "category": "billing" },
    { "slug": "manage_settings", "name": "إدارة الإعدادات", "category": "settings" }
  ]
}
```

---

## 8. Platform Health & Audit Logs

### 8.1 Health Check (Public)
- **HTTP Method**: `GET`
- **Endpoint**: `/health`
- **Response `200 OK`**:
```json
{
  "success": true,
  "message": "Sada API is running",
  "data": {
    "version": "v1",
    "timestamp": "2026-09-20T14:30:00.000Z"
  }
}
```

### 8.2 Audit Logs
- **HTTP Method**: `GET`
- **Endpoint**: `/audit-logs`
