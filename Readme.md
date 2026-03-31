# Mailroom System

A lightweight PHP + MySQL web application for managing incoming **documents**, **parcels**, and **newspapers** with live operational dashboards, filtering, and transaction history.

---

## 📌 Interactive README Navigation

Use this mini menu to jump to what you need:

1. [What’s in the app](#-whats-in-the-app)
2. [Quick setup](#-quick-setup)
3. [Run locally](#-run-locally)
4. [Database schema](#-database-schema)
5. [Feature walkthrough](#-feature-walkthrough)
6. [Page map](#-page-map)
7. [Troubleshooting](#-troubleshooting)

---

## ✨ What’s in the app

### Dashboard analytics
- Totals for documents, parcels, newspaper records, and categories.
- Daily/weekly/monthly counts for all major records.
- Operational totals such as pickups, distributions, and copies handled.
- Recent activity stream combining multiple workflows.

### Documents workflow
- Add documents through AJAX with validation.
- Auto serial numbers when `documents.serial_number` exists.
- Fallback compatibility when optional DB columns are missing.
- Type-based organization using `document_types`.

### Parcels workflow
- Receive parcels with generated tracking IDs (`PRCL-YYYYMMDD-XXXXXX`).
- Pickup recording with recipient details.
- Edit/delete controls for received parcels (including pending-state checks).
- Pending vs picked-up status views and pagination.

### Newspaper workflow
- Create and manage newspaper categories.
- Register newspaper issues with generated issue numbers.
- Manage available copies and mark distributed status automatically.
- Filter, search, sort, and paginate newspaper listings.

### Distribution visibility
- Dedicated document distribution pages and history views.
- Availability and distribution tracking pages for circulation operations.

---

## 🚀 Quick setup

1. **Copy/clone** this project into your web root.
2. **Create a database** (example: `mailroom_system`).
3. **Import schema** from:
   - `config/mailroom_system.sql`
4. **Configure DB credentials** in:
   - `config/db.php`
5. **Start your web server** and open the app.

---

## 🧪 Run locally

If you want to run quickly with PHP’s built-in server:

```bash
php -S localhost:8000
```

Open:

```text
http://localhost:8000
```

---

## 🗄️ Database schema

Core tables used by the current codebase include:

- `documents`
- `document_types`
- `document_distribution`
- `parcels_received`
- `parcels_pickup`
- `newspapers`
- `newspaper_categories`
- `distribution`

### Optional/compatibility columns detected at runtime
Some modules dynamically check for optional columns and adapt behavior if present:

- `documents.created_at`
- `documents.serial_number`
- `parcels_received.received_at`
- `parcels_pickup.picked_at`

---

## 🧭 Feature walkthrough

### 1) Start at dashboard
Visit `index.php` to see:
- KPIs and period summaries.
- Latest parcel receive/pick dates.
- Combined recent activities.

### 2) Manage documents
Visit `documents.php` to:
- Add document entries.
- View document stats and listings.
- Organize by document type.

### 3) Process parcels
Visit `parcels.php` to:
- Receive parcels.
- Mark pickup details.
- Edit/delete eligible parcel records.

### 4) Manage newspapers
Visit `list.php` to:
- Manage categories.
- Add and track newspaper issues.
- Update available copies with status automation.

### 5) Handle distributions
Use these pages for distribution operations:
- `distribution.php`
- `distribution_history.php`
- `available.php`
- `recipients.php`

---

## 🧩 Page map

- `index.php` — Dashboard and system-wide metrics.
- `documents.php` — Document intake and listing.
- `document_type.php` — Document type management.
- `parcels.php` — Parcel receiving, pickup, edit/delete.
- `list.php` — Newspaper/category management.
- `newspaper_categories.php` — Category-specific maintenance page.
- `distribution.php` — Distribution actions.
- `distribution_history.php` — Distribution history log.
- `available.php` — Available inventory views.
- `recipients.php` — Recipient records.
- `sidebar.php` — Shared navigation/layout.
- `config/db.php` — DB connection settings.
- `config/mailroom_system.sql` — Schema/bootstrap SQL.

---

## 🛠️ Troubleshooting

- **DB connection errors**
  - Re-check host/user/password/database in `config/db.php`.
- **Blank or partial pages**
  - Ensure required tables exist and match schema.
- **Unexpected missing timestamps/serials**
  - Verify optional columns listed above if those features are expected.
- **Production hardening**
  - Disable `display_errors` in production.
  - Move DB secrets to environment variables.
  - Add CSRF protection and stricter validation where needed.

---

## 📄 License

Add your preferred license (e.g., MIT) before external distribution.
