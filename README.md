# Mailroom Management System

A professional PHP + MySQL application for managing documents, parcels, and newspaper circulation from a centralized dashboard. Designed for library or office mailroom operations.

---

## 🚀 Overview

The Mailroom Management System streamlines the intake and distribution of all physical mail assets. It provides staff with real-time statistics, searchable history, and transactional security for all operations.

### Core Modules:
- **Documents**: Intake, categorization, and the new View/Edit/Delete actions.
- **Parcels**: Receipt tracking, pending item management, and pickup recording.
- **Newspapers**: Subscription management, daily circulation, and history logging.

---

## 📽 Demo Walkthroughs

### Overall System Walkthrough
![System Walkthrough](/home/hollali/.gemini/antigravity/brain/8b5c43d9-f01f-4c34-800b-9324a5ae02be/project_screenshots_1775930167257.webp)

### Document Management Features
![Document Management](/home/hollali/.gemini/antigravity/brain/8b5c43d9-f01f-4c34-800b-9324a5ae02be/verify_doc_actions_1775928457740.webp)

---

## ✨ Recent Enhancements

We have recently upgraded the system with several premium features:
- **Document Actions**: Per-row **View**, **Edit**, and **Delete** actions in the document list.
- **Document History**: A dedicated `documents_distribution_history.php` page to track and manage past distributions.
- **Data Integrity**: Added Foreign Key constraints with `ON DELETE CASCADE` to ensure history is cleaned up when primary records are removed.
- **Improved UI**: Enhanced modals with smooth transitions and real-time toast notifications.

---

## 🛠 Project Structure

- `index.php` - Dashboard and combined activity overview.
- `documents.php` - Document intake, listing, and advanced actions (View/Edit/Delete).
- `documents_distribution_history.php` - Log of past document distributions.
- `parcels.php` - Parcel receiving, tracking, and pickup.
- `newspaper_distribution.php` - Daily newspaper circulation workflow.
- `distribution_history.php` - Newspaper distribution history log.
- `document_type.php` - Document category management.
- `newspaper_categories.php` - Subscription management.
- `recipients.php` - Recipient registry for newspapers.
- `sidebar.php` - Shared navigation and layout component.
- `config/db.php` - Database connection settings.
- `config/mailroom_system.sql` - Core database schema.
- `config/document_distribution_history.sql` - Migration for history integrity.

---

## 📥 Setup Instructions

1. **Deploy to Web Root**: Copy the project files to your server's public directory (e.g., `/var/www/html`).
2. **Database Setup**:
   - Create a MySQL database (e.g., `mailroom_system`).
   - Import `config/mailroom_system.sql`.
   - Import `config/document_distribution_history.sql` to apply the latest integrity updates.
3. **Configure Connection**: Update your database credentials in `config/db.php`.
4. **Permissions**: Ensure the web server has read/write permissions for the project directory.

### Quick Start (PHP built-in server)
```bash
php -S localhost:8000
```
Then visit `http://localhost:8000` in your browser.

---

## 🔒 Security & Best Practices

- **Prepared Statements**: Used for all database mutations to prevent SQL injection.
- **Transactions**: Multi-table operations (like distribution) are wrapped in database transactions.
- **Toast Feedback**: Real-time success/error messaging using Toastify JS.
- **Responsive Design**: Built with Tailwind CSS for mobile and desktop compatibility.

---

## 📄 License

This project is currently provided for internal mailroom use. Please add a formal license (e.g., MIT) before public distribution.
