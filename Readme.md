# Mailroom System

A lightweight PHP + MySQL application for managing **documents**, **parcels**, and **newspaper circulation** from one shared dashboard for library or office mailroom operations.

---

## Overview

The current version of the app is organized around three operational workflows:

- **Documents** for intake, categorization, and one-time distribution logging
- **Parcels** for receipt, tracking, editing pending entries, and pickup recording
- **Newspapers** for subscription management, recipient management, daily distribution, and distribution history

The UI is built with Tailwind CSS and Font Awesome, with page-level dashboards, toast feedback, filters, and paginated tables.

---

## Current Features

### Dashboard
- System-wide totals for documents, parcels, newspapers, document types, and newspaper subscriptions
- Daily, weekly, and monthly activity metrics
- Total document distributions and parcel pickups
- Total document copies received versus distributed
- Latest parcel received date and latest parcel picked date
- Recent activity grouped into document, parcel, and newspaper streams

### Documents
- Add documents through AJAX with validation
- Organize documents by `document_types`
- Manage document types with duplicate checks and safe delete rules
- Auto-generate serial numbers when `documents.serial_number` exists
- Support optional timestamp compatibility through `documents.created_at`
- Allow a document to be distributed once only
- Show distribution summaries in the document listing

### Parcels
- Receive parcels with generated tracking IDs in the format `PRCL-YYYYMMDD-XXXXXX`
- Track parcel status as `Pending` or `Picked Up`
- Edit only pending parcels
- Record pickup details including picker name, phone number, and designation
- Delete parcel records transactionally along with related pickup records
- Filter and paginate parcel views

### Newspapers
- Manage newspaper subscriptions through `newspaper_categories.php`
- Manage recipients through `recipients.php`
- Deactivate recipients with existing history instead of deleting them
- Distribute selected subscriptions once per day per recipient
- Prevent duplicate same-day distribution
- Review searchable and filterable distribution history
- Surface newspaper intake totals and recent newspaper activity on the dashboard

---

## Project Structure

- `index.php` - Dashboard and combined activity overview
- `documents.php` - Document intake, listing, and distribution actions
- `document_type.php` - Document type management with pagination, search, and duplicate protection
- `distribution.php` - Document distribution history
- `parcels.php` - Parcel receiving, editing, pickup, filters, and status tracking
- `newspaper_categories.php` - Newspaper subscription management
- `recipients.php` - Recipient management
- `newspaper_distribution.php` - Daily newspaper distribution workflow
- `distribution_history.php` - Newspaper distribution history
- `sidebar.php` - Shared sidebar navigation and layout shell
- `config/db.php` - Database connection configuration
- `config/mailroom_system.sql` - Database schema and seed data

---

## Setup

1. Copy the project into your web root.
2. Create a MySQL database, for example `mailroom_system`.
3. Import [`config/mailroom_system.sql`](/var/www/html/mailroom_system/config/mailroom_system.sql).
4. Update database credentials in [`config/db.php`](/var/www/html/mailroom_system/config/db.php).
5. Start your web server and open the application in the browser.

If you want a quick local run with PHP's built-in server:

```bash
php -S localhost:8000
```

Then open `http://localhost:8000`.

---

## Database Notes

Core tables used by the current codebase include:

- `documents`
- `document_types`
- `document_distribution`
- `parcels_received`
- `parcels_pickup`
- `newspapers`
- `newspaper_categories`
- `recipients`
- `distribution`

Some pages also adapt dynamically when optional compatibility columns exist:

- `documents.created_at`
- `documents.serial_number`
- `parcels_received.received_at`
- `parcels_pickup.picked_at`

### Newspaper data note

The schema still contains a `newspapers` table, and the dashboard reads from it for newspaper totals and recent intake activity. In the current UI flow, however, newspaper operations are centered on:

- `newspaper_categories` for subscriptions
- `recipients` for who receives newspapers
- `distribution` for daily newspaper circulation history

---

## Workflow Guide

### Documents
Use [`documents.php`](/var/www/html/mailroom_system/documents.php) to register incoming documents, assign a type, store copies received, and trigger distribution when needed. The current workflow allows only one distribution record per document.

Use [`document_type.php`](/var/www/html/mailroom_system/document_type.php) to create, update, search, and paginate document types. The page also prevents deleting a type that is already in use by existing documents.

### Parcels
Use [`parcels.php`](/var/www/html/mailroom_system/parcels.php) to receive parcels, track pending items, edit pending records, and capture pickup details when items are collected.

### Newspapers
Use [`newspaper_categories.php`](/var/www/html/mailroom_system/newspaper_categories.php) to manage subscriptions, [`recipients.php`](/var/www/html/mailroom_system/recipients.php) to maintain recipient records, [`newspaper_distribution.php`](/var/www/html/mailroom_system/newspaper_distribution.php) to distribute subscriptions for the day, and [`distribution_history.php`](/var/www/html/mailroom_system/distribution_history.php) to review past circulation.

---

## Troubleshooting

- If the database connection fails, re-check credentials in [`config/db.php`](/var/www/html/mailroom_system/config/db.php).
- If pages load partially, confirm the imported schema matches [`config/mailroom_system.sql`](/var/www/html/mailroom_system/config/mailroom_system.sql).
- If serial numbers or timestamps do not appear, verify the optional compatibility columns listed above.
- For production use, disable `display_errors`, protect forms against CSRF, and move secrets out of committed config files.

---

## License

Add your preferred license before distribution.
