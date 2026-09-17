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
- **Audit Trail**: Every create / edit / delete / distribution / pickup / backup action across all modules is recorded in a `audit_logs` table and viewable on the `audit_trail.php` page — filter by module, action, operator, search term, and date range, with paginated, newest-first output and optional full-log clearing. An **Operator name** set in **Settings → System** identifies who performed each action.
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
| `audit_trail.php` | Append-only audit log viewer (filters, search, pagination). |
| `settings.php` | Backup management, appearance/app settings (tabbed), operator identity. |
| `sidebar.php` | Shared navigation and layout component. |
| `assets/app.css` | Design system (design tokens, component styles). |
| `assets/app.js` | Shared JS: modals, drawers, toasts, sidebar, PWA, display controls. |
| `manifest.json` | PWA web app manifest (name, theme, icons). |
| `sw.js` | Service worker (asset caching + offline fallback). |
| `offline.html` | Offline fallback page shown when the server is unreachable. |
| `images/icons/` | Generated app icons (192, 512, maskable, apple-touch). |
| `includes/helpers.php` | Shared helper utilities. |
| `includes/csrf.php` | CSRF token generation/validation. |
| `includes/audit.php` | Audit logging helpers (`audit_log`, `audit_diff`, `audit_user`). |
| `config/db.php` | Database connection (reads `.env` or environment variables). |
| `config/mailroom_system.sql` | Core database schema (schema + integrity migrations included). |
| `api/index.php` | Read-only REST JSON API (X-API-Key auth). |
| `api/mcp.php` | Model Context Protocol (JSON-RPC 2.0) endpoint — tools and resources. |
| `api/bootstrap.php` | Shared API bootstrap: auth, CORS, JSON helpers. |
| `api/query.php` | Shared read-only query layer for both API endpoints. |
| `includes/mcp_client.php` | MCP client for calling an external MCP server (e.g. Parliament_MCP). |
| `.env.example` | Template for database credentials and API config. |

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

## 🔌 API & MCP Integration

The system exposes a read-only REST JSON API and a Model Context Protocol (MCP) endpoint, and can itself talk to an external MCP server (e.g. Parliament_MCP). All API routes require an API key sent as `X-API-Key` (or `Authorization: Bearer`) header; `/api/index.php/ping` is public.

### Configuration (`.env`)

```env
MAILROOM_API_KEY=your-long-random-secret
MCP_URL=https://parliament-mcp.example.com/mcp   # external MCP server the mailroom calls
MCP_API_KEY=                                     # optional key sent to that server
```

### REST API — `api/index.php/<route>`

| Route | Description |
| --- | --- |
| `GET /ping` | Health check (no auth). |
| `GET /stats` | Dashboard KPIs, counts, recent activity. |
| `GET /search?q=` | Global search across all modules. |
| `GET /documents` · `GET /documents/{id}` | List / fetch documents (filters: `q`, `type_id`). |
| `GET /document-types` | Document categories. |
| `GET /document-distributions` | Document distribution records. |
| `GET /parcels` · `GET /parcels/{id}` | List / fetch parcels (filters: `q`, `status`). |
| `GET /parcels/tracking/{tracking_id}` | Parcel lookup by tracking ID. |
| `GET /newspapers` · `GET /newspapers/{id}` | List / fetch newspapers (filters: `q`, `status`, `category_id`). |
| `GET /newspaper-categories` | Newspaper subscription categories. |
| `GET /distributions` · `GET /distributions/{id}` | Newspaper distribution records. |
| `GET /recipients` · `GET /recipients/{id}` | List / fetch recipients (filters: `q`, `active`). |
| `GET /audit` | Audit log (filters: `module`, `action_type`, `q`). |

Lists support `?limit=` (max 100) and `?offset=`. If `PATH_INFO` is unavailable, pass `?route=documents/5`.

```
curl -H "X-API-Key: $MAILROOM_API_KEY" http://localhost:8000/api/index.php/parcels?status=pending
```

### MCP endpoint — `api/mcp.php`

A JSON-RPC 2.0 MCP server (protocol `2025-03-26` / `2025-06-18`) exposing **17 tools** (`get_stats`, `search_all`, `list_*`/`get_*` for documents, parcels, newspapers, distributions, recipients, audit) and **resources** under the `mailroom://` scheme (stats, collections, and `{id}`/`{trackingId}` templates). POST a JSON-RPC request; notifications return HTTP 202.

```
curl -X POST -H "X-API-Key: $MAILROOM_API_KEY" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"track_parcel","arguments":{"tracking_id":"PRCL-20260308-7D19FA"}}}' \
  http://localhost:8000/api/mcp.php
```

### MCP client — `includes/mcp_client.php`

`ParliamentMcpClient` sends JSON-RPC 2.0 to an external MCP server using `MCP_URL` / `MCP_API_KEY`:

```php
require 'includes/mcp_client.php';
$mcp = new ParliamentMcpClient();
$mcp->initialize();
$result = $mcp->callTool('some_parliament_tool', ['issue' => 'budget-2026']);
$tools  = $mcp->listTools();
```

### Security notes for the API

- **Fail closed**: if `MAILROOM_API_KEY` is not set, both endpoints return HTTP 503.
- **Read-only**: only `GET` (REST) and validated JSON-RPC 2.0 tool calls (MCP) are processed.
- **No secrets**: recorded audit entries are never exposed with extra credentials, and DB credentials stay in the gitignored `.env`.

---

## 🔒 Security & Best Practices

- **Prepared Statements**: Used for all database mutations to prevent SQL injection.
- **CSRF Protection**: Every state-changing form carries a CSRF token.
- **Transactions**: Multi-table operations (like distribution) are wrapped in database transactions.
- **Audit Logging**: Destructive and administrative actions are recorded with operator, IP, timestamp, and field-level change details; clearing the log itself is logged.
- **Secrets**: Database credentials live in `.env` (gitignored); never commit real credentials.
- **Toast Feedback**: Real-time success/error messaging using Toastify JS.
- **Responsive Design**: Built with Tailwind CSS for mobile and desktop compatibility.
- **Print Friendly**: Print layouts collapse the sidebar, hide interactive controls, and reset text scaling.

---

## 📄 License

This project is currently provided for internal mailroom use. Please add a formal license (e.g., MIT) before public distribution.