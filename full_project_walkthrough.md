# Mailroom Management System - Full Walkthrough

The Mailroom Management System is a centralized platform designed to handle the intake, tracking, and distribution of documents, parcels, and newspapers. Built with **PHP**, **MySQL**, and **Tailwind CSS**, it provides a responsive and premium experience for mailroom staff.

---

## 1. Dashboard & Analytics

The Dashboard is the control center of the application. It provides real-time statistics and a high-level overview of recent activities across all departments.

![Dashboard Overview](/home/hollali/.gemini/antigravity/brain/8b5c43d9-f01f-4c34-800b-9324a5ae02be/dashboard_page_1775930187047.png)

### Key Features:
- **Stat Cards**: Instant view of total documents, parcels, and newspapers.
- **Dynamic Stats**: Daily, weekly, and monthly growth indicators.
- **Recent Activity Ledger**: Tabbed interface showing the latest movements (Receiving vs. Distribution) for each module.
- **Quick Links**: Direct access to the most common actions (View Parcels, Open Documents).

---

## 2. Document Management

The Documents module handles incoming mail and formal documentation. It supports categorization and a robust set of management actions.

![Documents List View](/home/hollali/.gemini/antigravity/brain/8b5c43d9-f01f-4c34-800b-9324a5ae02be/documents_list_page_1775930197642.png)

### Core Capabilities:
- **Serial Numbering**: Automated generation of unique serial numbers (e.g., `DOC202612345`).
- **Advanced Actions**:
    - **View**: Modal-based detailed view without page reloads.
    - **Edit**: Update metadata (Name, Origin, Total Copies) with server-side validation.
    - **Delete**: Secure deletion with a confirmation modal and cascade protection.
- **Stock Tracking**: Real-time validation ensuring total copies never fall below the amount already distributed.

---

## 3. Parcel Tracking

Designed for the receipt and pickup of physical packages, the Parcel module ensures accountability for received items.

![Parcel Management](/home/hollali/.gemini/antigravity/brain/8b5c43d9-f01f-4c34-800b-9324a5ae02be/parcels_management_page_1775930208727.png)

### Workflow:
1. **Intake**: Generate tracking IDs and record sender/recipient details.
2. **Status Monitoring**: Track items as `Pending` or `Picked Up`.
3. **Pickup Recording**: Capture the picker's name, phone number, and designation to close the tracking loop.

---

## 4. Newspaper Circulation

The Newspaper module manages daily subscriptions and recipient-based distribution.

![Newspaper Distribution](/home/hollali/.gemini/antigravity/brain/8b5c43d9-f01f-4c34-800b-9324a5ae02be/newspaper_distribution_page_1775930218477.png)

### Features:
- **Subscription Management**: Define various newspaper categories and frequencies.
- **Recipient Registry**: Manage active recipients and maintain distribution history.
- **Daily Workflow**: Efficient distribution interface ensuring a subscription is only issued once per recipient per day.

---

## 📽 Video Walkthroughs

### Full System Overview
![System Overview](/home/hollali/.gemini/antigravity/brain/8b5c43d9-f01f-4c34-800b-9324a5ae02be/project_screenshots_1775930167257.webp)

### Document Management Deep Dive
![Document Management](/home/hollali/.gemini/antigravity/brain/8b5c43d9-f01f-4c34-800b-9324a5ae02be/verify_doc_actions_1775928457740.webp)

---

## Technical Architecture

### Database Schema
The system uses a relational database with key tables for **Documents**, **Parcels**, and **Daily Distributions**.
> [!NOTE]
> Foreign Key constraints with `ON DELETE CASCADE` are utilized to maintain data integrity when records are removed.

### Design System
- **Styling**: Vanilla CSS and Tailwind CSS for a premium, card-based layout.
- **Interactions**: FontAwesome icons for visual cues and Toastify JS for real-time feedback.
- **Performance**: AJAX-driven modals to reduce full-page reloads and improve staff efficiency.

---

## Getting Started

1. **Import Database**: Run `config/mailroom_system.sql` in your MySQL environment.
2. **Configure Connection**: Update `config/db.php` with your local credentials.
3. **Launch Server**: Deploy to an Apache/Nginx environment or use `php -S localhost:8000`.
