# Mailroom System Presentation Script

## Opening

Good morning everyone.

Today I’m presenting our **Mailroom System**, a simple web application built with PHP and MySQL to help us manage three core activities in one place:
- document intake and distribution,
- parcel receipt and pickup,
- and newspaper subscription distribution.

What makes this system useful is that it replaces scattered manual records with a single dashboard and clear transaction history.

---

## Why This System Matters

In many offices or libraries, these activities are often tracked manually. That usually leads to missing entries, duplicate work, weak traceability, and delayed reporting.

With this system, we can answer questions like:
- What documents came in today?
- Which parcels are still pending?
- Who already received newspapers today?

So the main value is visibility, accountability, and speed.

---

## How I’ll Demo It

I’ll walk through the system in four parts:

1. the dashboard,
2. documents,
3. parcels,
4. and newspapers.

---

## Part 1: Dashboard

I’ll start from the dashboard because it gives the fastest overview of the whole system.

On this page, we can see:
- total documents,
- total parcels received,
- pending parcels,
- total newspapers,
- total document types,
- total newspaper subscriptions,
- activity for today, this week, and this month,
- total document distributions,
- total parcel pickups,
- and copy-level document totals.

The dashboard also shows:
- the latest parcel received date,
- the latest parcel picked date,
- and recent activity grouped by documents, newspapers, and parcels.

This means management or staff can understand the current workload at a glance.

---

## Part 2: Documents Module

Next, I’ll open the Documents module.

Here, I can register a document by entering:
- the document name,
- the type,
- the origin,
- the number of copies received,
- and the date received.

The system validates the required fields before saving.

If the database supports serial numbers, the application can also generate one automatically. That helps make records easier to reference.

After saving, the document appears in the list together with:
- its type,
- received timestamp,
- copy count,
- and distribution information.

For distribution, the current workflow is controlled very carefully:
- each document can only be distributed once,
- and the system records that distribution against the document.

That means the app helps prevent duplicate distribution entries.

There is also a separate document distribution page where history can be reviewed and managed.

---

## Part 3: Parcel Module

From there, I move to the Parcel Management module.

This module covers the full parcel lifecycle.

When receiving a parcel, I enter:
- description,
- sender,
- addressed to,
- received by,
- and the date or time received.

Once saved, the system generates a tracking ID automatically in this format:

`PRCL-YYYYMMDD-XXXXXX`

The parcel is then listed as **Pending**.

One good control here is that only pending parcels can be edited. That protects record integrity after handover.

When the parcel is collected, I record:
- the person who picked it up,
- their phone number,
- their designation,
- and the pickup timestamp.

The status immediately changes to **Picked Up**, and the same parcel cannot be picked again.

If a parcel has to be deleted, the system also handles related pickup records carefully using a transaction.

So this module gives us proper traceability from receipt to final handover.

---

## Part 4: Newspaper Workflow

The newspaper side of the system now focuses more on **subscriptions, recipients, and daily distribution control**.

The first page is **Newspaper Subscription**.

Here, I can add and manage the different newspaper subscriptions or categories.

The next page is **Newspaper Recipients**.

This is where I maintain the list of people or offices that receive newspapers. A useful detail here is that if a recipient already has distribution history, the system deactivates them instead of deleting the record completely.

Then I move to **Newspaper Distribution**.

On this page, I select:
- the recipient,
- the staff member distributing,
- and one or more subscriptions for that day.

Before saving, the system checks whether that recipient has already received newspapers today.

If they already received them, the app blocks the action. If not, the distribution is saved successfully.

Finally, I can open **Newspaper History** to review past distributions. That page supports searching and filtering by department and date, which makes reporting easier.

---

## Technical Summary

Technically, the system is built with:
- PHP,
- MySQL or MariaDB,
- Tailwind CSS,
- and Font Awesome.

It uses dedicated pages for each workflow and relies on common relational tables such as:
- `documents`,
- `document_types`,
- `document_distribution`,
- `parcels_received`,
- `parcels_pickup`,
- `newspapers`,
- `newspaper_categories`,
- `recipients`,
- and `distribution`.

Prepared statements are used in many actions, and transactions are used where data consistency matters most.

Another nice detail is that some pages can adapt when optional timestamp or serial number columns exist in the database.

---

## Business Value

From a business point of view, the system improves operations in four ways:

1. **Better accountability**
   Every key action is tied to a record.

2. **Faster daily work**
   Staff can capture and retrieve information quickly.

3. **Better control**
   The system prevents duplicate actions in important workflows.

4. **Better visibility**
   Supervisors can monitor activity from one dashboard.

---

## Closing

To conclude, this Mailroom System turns document handling, parcel tracking, and newspaper distribution into one connected digital workflow.

It helps the team work faster, keeps records clearer, and gives management better visibility into daily operations.

Thank you, and I’m ready for your questions.

---

## Short Q&A Prompts

**Q: What is new in the newspaper section?**  
A: It now focuses on subscription management, recipients, once-per-day distribution control, and searchable history.

**Q: Can the system stop duplicate entries?**  
A: Yes. For example, it blocks duplicate newspaper distribution for the same recipient on the same day, and documents are controlled so they are distributed once.

**Q: How are parcels tracked?**  
A: Each parcel gets a tracking ID and moves from pending to picked up with full pickup details recorded.
