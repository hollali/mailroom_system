# Mailroom Management System

A professional PHP + MySQL application for managing documents, parcels, and newspaper circulation from a centralized dashboard. Designed for library or office mailroom operations.

---

## 🚀 Overview

The Mailroom Management System streamlines the intake and distribution of all physical mail assets. It provides staff with real-time statistics, searchable history, and transactional security for all operations.

### Core Modules:

- **Documents**: Intake, categorization, and View/Edit/Delete actions.
- **Parcels**: Receipt tracking, pending item management, and pickup recording.
- **Newspapers**: Subscription management, daily circulation, and history logging.

### ✨ Features

- **Progressive Web App (PWA)**: Installable as a standalone app with its own window, icon, and offline fallback page. A service worker (`sw.js`) caches static assets for fast loading and serves a branded page when the server is unreachable.
- **Display Controls**: A floating **Aa** widget (bottom-right on every page) plus a **Settings → Appearance** tab let you change the interface font family (Default / Serif / Modern / Mono) and text size (A− to A+++). Preferences persist per browser and apply instantly across all pages.
- **Organized Settings**: The Settings page is split into **Appearance**, **Backup**, and **System** tabs — your last active tab is remembered.
- **Document Actions**: Per-row **View**, **Edit**, and **Delete** actions in the document list.
- **Document History**: A dedicated `documents_distribution_history.php` page to track and manage past distributions.
- **Data Integrity**: Foreign Key constraints with `ON DELETE CASCADE` keep history in sync when primary records are removed.
- **Accurate Statistics**: Column-aligned totals row on the newspaper statistics table.
- **Polished UI**: Responsive Tailwind layout, collapsible sidebar, smooth modals/drawers, and real-time toast notifications.

---

## 📽 Demo Walkthroughs

A guided presentation script with screenshots and talking points is available in [`PRESENTATION_SCRIPT.md`](PRESENTATION_SCRIPT.md). It covers the dashboard, document management (View/Edit/Delete), parcel workflows, and newspaper distribution.

---

## 🛠 Project Structure

| Path | Purpose |
| --- | --- |
| `index.php` | Dashboard and combined activity overview. |
| `list.php` | Newspaper management with Newspapers / Statistics tabs. |
| `newspaper_distribution.php` | Daily newspaper circulation workflow. |
| `distribution_history.php` | Newspaper distribution history log. |
| `newspaper_categories.php` | Subscription/label management. |
| `recipients.php` | Recipient registry for newspapers. |
| `documents.php` | Document intake, listing, and advanced actions. |
| `distribution.php` | Document distribution workflow. |
| `documents_distribution_history.php` | Log of past document distributions. |
| `document_type.php` | Document category management. |
| `parcels.php` | Parcel receiving, tracking, and pickup. |
| `settings.php` | Backup management, appearance/app settings (tabbed). |
| `sidebar.php` | Shared navigation and layout component. |
| `assets/app.css` | Design system (design tokens, component styles). |
| `assets/app.js` | Shared JS: modals, drawers, toasts, sidebar, PWA, display controls. |
| `manifest.json` | PWA web app manifest (name, theme, icons). |
| `sw.js` | Service worker (asset caching + offline fallback). |
| `offline.html` | Offline fallback page shown when the server is unreachable. |
| `images/icons/` | Generated app icons (192, 512, maskable, apple-touch). |
| `includes/helpers.php` | Shared helper utilities. |
| `includes/csrf.php` | CSRF token generation/validation. |
| `config/db.php` | Database connection (reads `.env` or environment variables). |
| `config/mailroom_system.sql` | Core database schema (schema + integrity migrations included). |
| `.env.example` | Template for database credentials. |

---

## 📥 Setup Instructions

1. **Deploy to Web Root**: Copy the project files to your server's public directory (e.g., `/var/www/html`).

2. **Database Setup**:
   - Create a MySQL database (e.g., `mailroom_system`).
   - Import `config/mailroom_system.sql` into that database.

3. **Configure Connection**:
   - Copy `.env.example` to `.env`.
   - Set your database credentials in `.env`:
     ```env
     DB_HOST=localhost
     DB_USER=root
     DB_PASS=your_password
     DB_NAME=mailroom_system
     ```
   - `config/db.php` reads these values (it also honors real environment variables).

4. **Permissions**: Ensure the web server can read the project directory. Backup files are written under `config/backups/` (gitignored).

### Quick Start (PHP built-in server)

```bash
php -S localhost:8000
```

Then visit `http://localhost:8000` in your browser.

### PWA / Installable App

- The service worker and install prompt require a **secure context**: `https://` (any modern host) or `http://localhost`.
- On HTTPS, an **Install app** button appears in **Settings → Appearance** and in the floating **Aa** widget once the browser signals the app is installable.

---

## 🔒 Security & Best Practices

- **Prepared Statements**: Used for all database mutations to prevent SQL injection.
- **CSRF Protection**: Every state-changing form carries a CSRF token.
- **Transactions**: Multi-table operations (like distribution) are wrapped in database transactions.
- **Secrets**: Database credentials live in `.env` (gitignored); never commit real credentials.
- **Toast Feedback**: Real-time success/error messaging using Toastify JS.
- **Responsive Design**: Built with Tailwind CSS for mobile and desktop compatibility.
- **Print Friendly**: Print layouts collapse the sidebar, hide interactive controls, and reset text scaling.

---

## 📄 License

This project is currently provided for internal mailroom use. Please add a formal license (e.g., MIT) before public distribution.