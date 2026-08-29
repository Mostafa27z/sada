# Sada Media Monitoring SaaS Platform Backend

API-First multi-tenant SaaS backend for an **Arabic Media Monitoring and Media Intelligence Platform** built using Laravel.

---

## 🚀 Key Architectural Features
- **API-First Design**: Unified JSON API responses return standardized envelopes (`{ success, message, data, errors, meta }`).
- **Multi-Tenancy Workspace Isolation**: Full isolation of keywords, private sources, articles, alert rules, and audit logs. Resolved via `X-Tenant-ID` header.
- **Role-Based Access Control (RBAC)**: Fine-grained permissions mapping to 5 system roles (`super_admin`, `tenant_owner`, `tenant_admin`, `analyst`, and `viewer`).
- **Billing & Resource Limits**: Middleware checking tenant usage quotas dynamically (user seats, keyword tracking, sources limit).
- **HMAC Secure Webhooks**: Asynchronous ingestion of articles and NLP sentiment extraction from external scrapers using secure SHA-256 signatures (`X-Sada-Signature`).
- **Intelligence Dashboard & Reports**: High-level statistics calculation, sentiment trend reports, and custom PDF/CSV export generation.

---

## 🛠️ Requirements & Setup

### Requirements
- PHP 8.2+
- MySQL 8.0+
- Composer

### Installation
1. Clone the project and install dependencies:
   ```bash
   composer install
   ```

2. Copy environment file and configure database:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. Run migrations and database seeders:
   ```bash
   php artisan migrate:fresh --seed --seeder=PlanSeeder
   php artisan db:seed --class=RoleAndPermissionSeeder
   ```

4. Run the automated test suite:
   ```bash
   php artisan test --testdox
   ```

---

## 📖 API Reference Documentation

For detailed cURL examples of every single REST endpoint and webhook signature calculation, please refer to the complete API reference guide:

📄 **[Sada API Reference Guide](file:///C:/Users/workstation/.gemini/antigravity-ide/brain/1ae2d57e-d087-464e-8271-7a64aefda1e9/api_reference.md)**
# sada
