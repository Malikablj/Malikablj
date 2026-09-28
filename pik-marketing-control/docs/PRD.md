# Product Requirements: PIK Marketing Control

> **The original PRD was not provided with this project.** This document records the
> product requirements as they can be derived from the supplied *Technical Specification v1.0*
> ([TECHNICAL_SPEC.md](TECHNICAL_SPEC.md)). Where the specification is silent, the chosen
> behaviour is listed in [DECISIONS.md](DECISIONS.md) under "Open business decisions".
> Replace or extend this file when the PRD becomes available.

## Product

| | |
|---|---|
| Company | PT Permata Indo Kemas (PIK): plastic packaging manufacturer |
| Product | PIK Marketing Control: internal web app (desktop and phone) |
| Users | Marketing, Sales, Management, Admin, Viewer |
| Language | Bahasa Indonesia |
| Replaces | the AppSheet master database (`PIK_Master_Database_AppSheet.xlsx`) |

## Goals (derived)

1. One reliable place for customers, contacts and the sales pipeline (leads, activities, follow-ups).
2. Nobody forgets a follow-up: today's and overdue follow-ups are visible at a glance.
3. Purchase orders are tracked from order to delivery: ordered, delivered, returned and
   outstanding quantities are always computed from transactions, never typed by hand.
4. Management sees real KPIs and reports without asking for spreadsheets.
5. Legacy data is migrated without loss, with every questionable record reported.

## Roles

| Role | Purpose |
|---|---|
| Admin | manages users, operations data (deliveries, returns, products, stock, finance) and migration issues |
| Marketing | manages customers, contacts, leads, activities, follow-ups and their own POs |
| Sales | manages customers, contacts, leads, activities, follow-ups; reads operations |
| Management | reads everything relevant for decisions |
| Viewer | read-only (no financial data) |

Detailed matrix: Technical Specification §9 and DECISIONS.md (B3, B4).

## Functional scope

* **Customers**: list, search, filters, create, edit, archive; the customer page is the central
  workspace with tabs Overview, Contacts, Activities, Leads, Follow Ups, Purchase Orders,
  Deliveries, Returns.
* **Contacts**: per customer; one primary contact.
* **Leads**: pipeline New → Contacted → Qualified → Quotation → Negotiation → Won / Lost /
  Dormant; owner (PIC), priority, estimated value, expected closing date; Kanban and list views.
* **Activities**: WhatsApp, call, email, meeting, visit, quotation, sample, presentation,
  follow-up, complaint, note, other; linked to customer and optionally contact and lead.
* **Follow-ups**: date/time, owner, priority, status (Planned, Done, Reschedule, Cancelled,
  Overdue); Today / Upcoming / Overdue / Completed views.
* **Purchase orders**: header and lines (product, quantity, unit, price); statuses Open, On
  Process, Partial, Closed, Cancelled; delivered, returned and outstanding per line and PO.
* **Deliveries and returns**: linked to PO / PO line / product; statuses; return reasons.
* **Products and stock**: product master, category, customer, lead time; stock by type (FG,
  WIP, Ready, Reserved) and warehouse.
* **Finance**: invoices and payments per PO; PO financial summary.
* **Dashboard**: Total Customers, Active Leads, Follow Up Today, Overdue Follow Up, Open PO,
  Outstanding Quantity; lead pipeline, recent activities, upcoming follow-ups, open POs,
  delivery status.
* **Reports**: customers, leads, activities, follow-ups, purchase orders, deliveries, stock;
  filters (date range, customer, PIC, status) and CSV export.
* **Migration**: profile → dry run → migrate → verify; issue review in the app.

## Business rules

* Delivered quantity = Σ delivered deliveries; returned quantity = Σ returns received back.
* Outstanding quantity = MAX(0, ordered − delivered + returned) per PO line.
* A follow-up is overdue when its date is before today and it is not Done/Cancelled; it is
  "today" when its date is today and it is not Done/Cancelled.
* Important records are archived or cancelled, never deleted.

## Non-functional requirements

* Real persistence (PostgreSQL), authentication, role-based authorization enforced on the server.
* Usable on phones (priority flows: login, customer search, customer detail, add activity,
  add follow-up, today's follow-ups, update lead, view PO).
* Loading, empty, error and success states everywhere; confirmation before destructive actions.
* No business data hard-coded in the application.
* Minimal, quiet, professional visual design (design tokens in the Technical Specification §12).
