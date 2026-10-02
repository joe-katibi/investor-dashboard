# Outage Management System (OMS)

A centralised operational platform for detecting, recording, escalating, coordinating, restoring and reporting
network and service outages — **FTTH, 4G Mobility, Core (SEACOM) and System (CRM, Call Center, Dialer)** — with
priority-aware **FIFO assignment**, SLA automation, customer-impact (SMS / credit) tracking, real-time dashboards,
scheduled management e-mails and a complete audit trail.

Repository: <https://github.com/joe-katibi/outagemangementsystem>

---

## Table of contents

1. [Getting started](#getting-started)
2. [Technology stack](#technology-stack)
3. [Requirements](#requirements)
4. [Installation](#installation)
5. [Running the application](#running-the-application)
6. [Demo accounts](#demo-accounts)
7. [Features](#features)
8. [Outage lifecycle](#outage-lifecycle) · [4G NOC module](#4g-noc-module)
9. [FIFO assignment engine](#fifo-assignment-engine)
10. [SLA management](#sla-management)
11. [Notifications](#notifications)
12. [Reports and exports](#reports-and-exports)
13. [Scheduled e-mails and templates](#scheduled-e-mails-and-templates)
14. [Real-time updates (Reverb)](#real-time-updates-reverb)
15. [Roles and permissions](#roles-and-permissions)
16. [Seeded reference data](#seeded-reference-data)
17. [Configuration screens](#configuration-screens)
18. [Data model](#data-model)
19. [Project structure](#project-structure)
20. [Artisan commands and scheduler](#artisan-commands-and-scheduler)
21. [Environment variables](#environment-variables)
22. [Testing](#testing)
23. [Production deployment and load balancing](#production-deployment-and-load-balancing)
24. [Performance](#performance)
25. [Security](#security)
26. [Troubleshooting](#troubleshooting)

---

## Getting started

```
composer run dev
```

This starts the queue worker, Reverb and the scheduler. Demo logins are `admin@oms.test`, `noc.tier1@oms.test`, `cx.agent@oms.test`, `infra.dispatch1@oms.test`, `tech.north1@oms.test` and others, all with password `Password123!`. Demo accounts are never created in production; there, create your first admin with `php artisan oms:create-admin`.

Clone the project with `git clone https://github.com/joe-katibi/outagemangementsystem.git`. Setup, deployment, load-balancing and security details are in this `README.md`.

---

## Technology stack

| Layer | Technology |
|---|---|
| Framework | Laravel 13 (PHP 8.4) |
| Admin / operations UI | Filament 5 — free, MIT-licensed core only (no paid plugins) |
| Real-time | Laravel Reverb + Filament database notifications (Livewire Echo listeners) |
| Database | **PostgreSQL** (recommended) or **MySQL 8 / MariaDB** — one set of migrations, portable SQL only |
| Queue / cache / sessions | Database drivers out of the box; Redis recommended in production |
| Authorisation | Laravel policies + permission catalogue (`spatie/laravel-permission`) |
| MFA | Filament authenticator-app MFA with recovery codes |
| Excel export | `openspout/openspout` (.xlsx, streamed) |
| PDF export | `barryvdh/laravel-dompdf` |
| Tests | PHPUnit 12 (feature tests on SQLite in-memory) |

## Requirements

* PHP 8.4 with `pdo_mysql` or `pdo_pgsql`, `intl`, `mbstring`, `zip`, `gd`, `sodium`
* Composer 2
* MySQL 8+ / MariaDB 10.6+ **or** PostgreSQL 14+
* Redis (optional locally, recommended for production / multiple servers)
* Node.js is **not** required — Filament ships its compiled assets

## Installation

```bash
git clone https://github.com/joe-katibi/outagemangementsystem.git
cd outagemangementsystem
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env` and choose **one** database block:

```dotenv
# MySQL
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=outage_management
DB_USERNAME=oms
DB_PASSWORD=secret

# or PostgreSQL
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=outage_management
DB_USERNAME=oms
DB_PASSWORD=secret
```

Configure Reverb (generates app id / key / secret) and install:

```bash
php artisan reverb:install         # or fill REVERB_APP_ID / REVERB_APP_KEY / REVERB_APP_SECRET manually
php artisan migrate --seed         # reference data (+ demo data outside production)
php artisan filament:assets
```

Set `APP_URL` to the address you browse (e.g. `http://outagemanagement.test` with Laravel Herd) and
`APP_TIMEZONE` to your operating timezone (e.g. `Africa/Blantyre`) **before** entering real data.

## Running the application

`composer run dev` starts everything needed for development. To run the pieces individually:

| Process | Command | Purpose |
|---|---|---|
| Web | Herd / Valet / PHP-FPM / `php artisan serve` | UI at `/admin` (the site root redirects there) |
| Queue worker | `php artisan queue:work --tries=3` | Notifications, real-time broadcasts, scheduled reports |
| Websockets | `php artisan reverb:start` | Real-time updates (default port 8090) |
| Scheduler | `php artisan schedule:work` (dev) / cron `* * * * * php artisan schedule:run` | SLA checks, FIFO timeouts, e-mails |

Create an administrator (required in production):

```bash
php artisan oms:create-admin
```

## Demo accounts

Seeded only when `APP_ENV` is not `production` (and `OMS_SEED_DEMO=true`). Password for all: `Password123!`

| E-mail | Role | Notes |
|---|---|---|
| `admin@oms.test` | Super Admin | Everything |
| `exec@oms.test` | Executive | Read-only command centre and reports |
| `noc.manager@oms.test` | NOC Manager | |
| `noc.lead@oms.test` | NOC Team Leader | Can override FIFO |
| `noc.tier1@oms.test`, `noc.tier2@oms.test` | NOC Tier I / II | Receive NOC queue tickets |
| `cx.agent@oms.test`, `cx.lead@oms.test` | Customer Experience | Report outages, SMS / credit |
| `cx.tier2@oms.test`, `cx.tier2b@oms.test` | CX Tier II | Receive NOC-rejected tickets: customer callback / site visit |
| `dispatch@oms.test`, `dispatch.lead@oms.test` | Dispatcher / Dispatch Team Leader | Dispatch leader is told about site visits and support requests |
| `support1@oms.test`, `support2@oms.test` | Support/Installation Technician | Receive support requests (drop cable → customer premises) |
| `it.manager@oms.test`, `it.lead@oms.test` | IT Manager / IT Team Leader | Can override FIFO in the IT queues |
| `it.eng1@oms.test`, `it.eng2@oms.test` | IT Engineer | Receive system outages first (IT Review / IT Confirmation) |
| `infra.manager@oms.test`, `infra.lead@oms.test` | Infrastructure Manager / Team Leader | |
| `infra.dispatch1@oms.test`, `infra.dispatch2@oms.test` | Infrastructure Dispatcher | Receive Infrastructure queue tickets |
| `tech.north1@oms.test`, `tech.north2@oms.test` | Outage Technician (North Crew) | Cover Salaama, Kanengo OLTs |
| `tech.south1@oms.test`, `tech.south2@oms.test` | Outage Technician (South Crew) | Cover Area 47, Njewa OLTs |
| `vendor.tech1@oms.test` | Outage Technician (outsourced) | FibreTech Contractors |

Demo data also includes teams / sub-teams, 7 days of shifts for every user and skills. It creates **no outage
tickets and no topology**: tickets start empty, and the real OLT → FDT → Slot → Port mapping is loaded from the
mapping spreadsheet (see [Importing the network topology](#importing-the-network-topology-olt--fdt--slot--port)).

---

## Features

### Command centre dashboard
Live tiles for **Network, Core, System and 4G Mobility** outages; KPIs (total, active, resolved, critical,
customers affected, Regular / Emergency / Planned counts); SLA & customer impact (compliant, approaching breach,
breached, resolved within SLA %, SMS sent / not sent, credits shared / due); 7-day trend (volume, customer impact,
resolved); outages by status and by priority; live Network / Core / System outage tables (priority, impact, SLA,
elapsed time, owner, latest action); FIFO queue health; team performance; top impacted OLTs and services; recent
outages. Filters: date range, vertical, major type. Updates in real time.

### Outages
* Guided **Report outage** form: title, major type, outage type, vertical, start time, cascading
  **OLT → FDT → Slot → Port** multi-selects (children filtered to selected parents, inactive elements hidden, full
  path labels), customers affected with live rule-driven priority, optional priority override, location, affected
  services (filtered by vertical), 4G fields (sites down, cells down, power faults), description, team routing hint,
  SMS / credit flags.
* Automatic ticket numbers per outage type: `OUT-00000000001`, `EMO-…`, `PLM-…`, `CORE-…`, `SYS-…`.
* Outage list with tabs (Active, My tickets, Network, Core, System, Critical, SLA breached / at risk, Closed, All),
  filters (major type, outage type, vertical, priority, status, SLA state, team, OLT, service, date range), global
  search and **Export Excel** of the current view.
* Outage page: summary, SLA countdown, FIFO ownership, customer communication, lifecycle timestamps, final
  reason / RFO, plus tabs for timeline & updates, CX follow-up (callbacks and site visits), support technician
  requests, status history, FIFO assignments (with *Why?* explanation), assignment history, SMS & credit history,
  SLA events, notifications and audit trail.
* Workflow actions (only shown when both permission and workflow state allow them): Accept / Reject assignment,
  NOC Confirm, NOC Reject (reason mandatory), Record customer callback, Dispatch, Request support technician,
  Support work done, Cancel support request, Escalate to NOC (IT), Resolve (IT) / IT confirm resolved,
  Resolved — return to IT (NOC), Not resolved — back to NOC (IT), Add update, Report restored, NOC Restore
  Confirmed, Restoration not confirmed, Resolve (final reason when required), Set status, SMS / Credit,
  Assign / override FIFO.

### FIFO Queues screen
One tab per queue with live counts; each ticket shows queue position, ticket, priority, queue age, customers,
type, vertical, team, sub-team, SLA state, status and the assigned or **next eligible user**. *Why?* shows every
candidate against every eligibility rule. Managers can *Run FIFO now* and *Assign* (audited override). Filters:
department, team, sub-team, priority, outage type, vertical.

### My Work
Tickets FIFO-assigned to the signed-in user with acknowledgement deadlines, one-click *Accept*, all workflow
actions, and an availability switch (**Available / Busy / On Break / Offline**) that controls whether FIFO offers
new tickets. Shows current shift and leave status.

### Reports
17 reports with filters, on-screen view and **CSV / Excel (.xlsx) / PDF** export — see
[Reports and exports](#reports-and-exports).

### Administration
Verticals, affected services, major outage types, outage types, statuses, impact / priority rules, SLA policies,
FIFO queues, FIFO & SLA settings, notification rules, e-mail templates & schedules, OLTs / FDTs / Slots / Ports
(with team coverage), departments, teams & sub-teams, users (skills, shifts, leave, availability history), roles &
permissions, notification log (with retry) and audit trail.

---

## Outage lifecycle

```
REPORT → SUPPORT UNCONFIRMED → NOC FIFO QUEUE → NOC REVIEW → NOC CONFIRM / REJECT
       → INFRASTRUCTURE FIFO QUEUE → DISPATCH → TECHNICIAN FIFO ASSIGNMENT → TECHNICIAN ACTION
       → NOC RESTORE CONFIRMATION (FIFO) → OUTAGE RESOLVED → FINAL REASON / RFO
       → CUSTOMER SMS / CREDIT CONFIRMATION → REPORTING
```

| Step | What happens |
|---|---|
| 1. Report | Ticket number generated, priority from impact rules, SLA clock started, status *Support Unconfirmed Outage*. |
| 2. NOC escalation | `noc_escalated_at` recorded; ticket placed in the **NOC Review** FIFO queue and assigned to the next eligible NOC user. |
| 3. NOC review | Assignee accepts (or rejects the assignment with a reason → requeued). |
| 4. NOC Reject | Reason mandatory. The ticket goes back to **Customer Experience (Tier II)**: status *NOC Rejected – CX Follow-up*, **CX Follow-up** FIFO queue, SLA paused, reporter notified. See [NOC reject → CX follow-up](#noc-reject--cx-tier-ii-follow-up). |
| 5. NOC Confirm | Confirmation recorded; for types requiring Infrastructure the outage moves to *Assigned to Infrastructure* and into the **Infrastructure** FIFO queue. |
| 6. Dispatch | Dispatcher selects team type, team, sub-team and either a specific **eligible** technician or automatic FIFO; status *Team Dispatched*. The field team can then [request a support technician](#support-technician-on-the-same-ticket) on the same ticket. |
| 7. Field action | Technician posts updates, may set operational statuses (Partial Restore, Awaiting Field Access, Vendor Escalated, …) and reports restoration → *Restore Reported – Awaiting NOC* and the **NOC Restore** FIFO queue. |
| 8. NOC Restore Confirmed | Restoration time recorded; or *Restoration not confirmed* sends it back to the field team. |
| 9. Resolved | Final Reason / RFO required (status flag); SLA finalised as *met* / *missed*; all queue items closed. |
| 10. Notifications | Configured SLA, credit, SMS and operational notifications; dashboards update live. |

System outages follow their own flow — see [System outages: IT first](#system-outages-it-first).

### NOC reject → CX Tier II follow-up

```
NOC REJECT (reason) → NOC REJECTED – CX FOLLOW-UP → CX FOLLOW-UP FIFO QUEUE (CX Tier II)
       → RECORD CUSTOMER CALLBACK (+ optional SITE VISIT) → NOC REJECT (closed, SLA N/A)
```

* The rejected ticket is **not** closed straight away. It is assigned by FIFO to a **CX Tier II** agent
  (permission `queue.work_cx`, Customer Experience department) so they can call the customer back.
* **Record customer callback** form: when they called, the call outcome (reached — working / still affected /
  CPE fault, no answer, wrong number, call back later) and notes. Optionally **Raise a site visit** with the
  scheduled date and time, a site visit reference / job number and the action raised.
* *Follow-up complete — close the ticket* (on by default) closes it as *NOC Reject*. Turn it off to keep the ticket
  for another call (for example no answer); every attempt is kept on the **CX follow-up** tab.
* The SLA clock is paused while it waits (it is not a confirmed outage) and finalised as *N/A*.
* Alerts: the reporter and CX Team Leader hear about the rejection; CX / NOC team leaders about the callback;
  Dispatch leaders (and CX Team Leader) about a **site visit raised**.

### Support technician on the same ticket

```
TEAM DISPATCHED → REQUEST SUPPORT TECHNICIAN (work, customer, premises)
       → SUPPORT TECHNICIAN FIFO QUEUE (parallel) → SUPPORT WORK DONE
       → outage technician: REPORT RESTORED → NOC RESTORE CONFIRMATION → …
```

* While an outage is dispatched, the Infrastructure / maintenance team (Outage Technician, Infrastructure
  Dispatcher / Team Leader / Manager — permission `outage.request_support`) can ask for a **support technician**
  who handles the **drop cable to the customer premises**.
* The request goes to the **Support Technician** FIFO queue (Dispatch department, permission
  `queue.work_support`) and runs **in parallel**: the outage technician keeps the ticket and the live line, the
  support technician gets their own assignment on the same ticket (visible on *My Work* and in the *Support
  technician* panel / tab).
* The support technician (or a Dispatch leader) records **Support work done**; the requester and the outage
  technician are notified.
* **Report restored** is blocked while a support request is still open, so the ticket is only restored when both
  the live line and the drop cable are done. The requester or a leader can **Cancel support request** if it is no
  longer needed. Closing the outage cancels any open request.

### System outages: IT first

```
REPORT → IT REVIEW (IT Review FIFO queue)
   ├─ IT resolves → RESOLVED (IT feedback shared with the team and the reporter)
   └─ core / network cause → ESCALATE TO NOC → ESCALATED TO NOC BY IT (NOC Review FIFO queue)
          → NOC: RESOLVED — RETURN TO IT → NOC RESOLVED – AWAITING IT CONFIRMATION (IT Confirmation queue)
          ├─ IT confirm resolved (feedback) → RESOLVED
          └─ not resolved → back to NOC
```

* Controlled by the **IT reviews first** flag on the major outage type (seeded on *System Outage*). New tickets go
  to the **IT** department's queue instead of NOC (permission `queue.work_it`; roles IT Manager, IT Team Leader,
  IT Engineer — permission `outage.it_review`).
* IT either fixes it and **resolves with feedback**, or **escalates to NOC** with the reason (core / network).
* NOC works it from the NOC Review queue and uses **Resolved — return to IT** with what was fixed.
* IT checks: **IT confirm resolved** closes the ticket with the feedback as the Final Reason / RFO, sent to the
  resolution alert rules and directly to the person who reported it; **Not resolved — back to NOC** returns it.
* System outages already open before this change keep the NOC flow they started with.

### Planned maintenance (CRF & sign-off)

```
INFRASTRUCTURE: REQUEST PLANNED MAINTENANCE → MAINTENANCE REQUESTED – NOC REVIEW (NOC Review FIFO queue)
   → NOC confirms affected customers, completes the CRF, picks signatories → CRF – AWAITING SIGN-OFF
        stakeholders + signatories: "CRF for review" notice (CRF attached)
        each signatory: personal "Sign CRF" e-mail (no login) → view CRF, type name, draw signature
        ├─ Request changes → back to NOC (other links withdrawn) → NOC updates and re-issues
        └─ everyone approved → signed CRF e-mailed to all → ASSIGNED TO INFRASTRUCTURE (dispatch FIFO queue)
   → reminders 24h / 12h / 6h / 3h / 1h / 30m / 15m / 5m before, and a "started" alert when the window opens
   → DISPATCH (team / technician) → TECHNICIAN: REPORT WORK COMPLETED → NOC RESTORE CONFIRMATION queue
        ├─ customers not all up → back to Infrastructure
        └─ all customers up + final status (Successful / Rolled back / Partial) → CX CUSTOMER NOTIFICATION queue
   → CX: CUSTOMERS NOTIFIED — CLOSE → RESOLVED (completion e-mail with the final CRF)
```

* **Request** — *Operations → Request maintenance* (or *Request maintenance* on the outage list), permission
  `maintenance.request` (Infrastructure Manager / Team Leader / Dispatcher). The form follows the CRF: title,
  window, initiator, department, lead, emergency contact, escalation / rollback time, OLT → FDT → Slot → Port (with
  ALL), estimated customers, services, risk tier, description of work, fallback plan, prior tests, risks & mitigation
  and an optional customer list (Excel / CSV / PDF). A `PLM-…` ticket is created and routed to NOC. Planned
  maintenance can no longer be raised through *Report outage*.
* **CRF & sign-off** — NOC (`maintenance.manage`) uses **Complete CRF & send for sign-off**: confirms the number of
  affected customers (priority and SLA follow it), completes the CRF, ticks the operational checklist and chooses the
  signatories (defaults from *Maintenance Settings*; a user or any e-mail, e.g. the CTO). Each signatory gets a
  personal link — a signed URL with a single-use token — to view the CRF and **Approve & sign** (typed name + drawn
  signature, recorded with date and IP) or **Request changes**. *Re-send sign-off links* issues new links to those
  who have not signed. The **CRF sign-off** tab shows every signature.
* **CRF document** — generated as a PDF in the layout of the paper form (general details, risk rating, technical
  scope & fallback, operational checklist, post-maintenance outcome, sign-offs with signatures). **View CRF** /
  **Download CRF** on the ticket, attached to the maintenance e-mails, and always current (signatures appear as
  they are given, the outcome once NOC records it).
* **Reschedule** (`maintenance.reschedule`, until the work is reported done) — new window + reason: the ticket start
  time, SLA and reminders move with it and everyone gets the updated CRF. Optionally collect the signatures again
  (setting). **Cancel maintenance** closes it and notifies everyone.
* **Reminders & start** — sent by `oms:maintenance-tick` (every minute) at the times set in *Maintenance Settings*,
  in-app to stakeholder roles and by e-mail per template. Each reminder goes out once per window; one whose moment
  passed while the scheduler was down is skipped rather than sent late.
* **SLA** — the *Planned Maintenance – window* SLA policy: **6 hours** from the scheduled start until CX has notified
  customers. Change it under *Configuration → SLA Policies* (re-seeding never overwrites it).
* **E-mails** — *Configuration → Maintenance E-mails*: every e-mail is an editable template with its **trigger** (CRF
  issued, sign-off request, fully signed, changes requested, reminder, a set number of minutes before the start,
  started, rescheduled, completed, cancelled), recipients (stakeholders, signatories, extra TO / CC), CRF attachment,
  subject and body with `**bold**` and placeholders such as `{{title}}`, `{{scheduled_date}}`, `{{start_time}}`,
  `{{olt_fdt}}`, `{{customers}}`, `{{time_left}}`, `{{sign_button}}`, `{{ticket_button}}`. The seeded *CRF for review*
  notice follows the usual "Please find attached the Change Request Form…" e-mail.
* **Settings** — *Configuration → Maintenance Settings* (`maintenance.configure`): reminder times, stakeholder roles
  and e-mails, default signatories, re-approval on reschedule, sign link validity and the SLA shortcut.
* **Reports** — *Planned Maintenance Report*: window, reschedules, risk, lead, area, customers, CRF status, signed
  x / y and signatories, approval time, team, technician, completion, final status, outcome, customer notification
  and SLA (on screen, CSV, Excel, PDF).

### Preventive & corrective maintenance (raised on site, with photos)

```
PREVENTIVE: technician RAISES (issue, FAT / FDT label, proposed downtime, 2 photos) → AWAITING APPROVAL
   ├─ Dispatcher approves + Infrastructure Manager approves (two different people)
   │     → everyone notified of the approved downtime (in-app + e-mail, photos attached) → TEAM DISPATCHED to the technician
   └─ either rejects → PREVENTIVE MAINTENANCE – REJECTED (closed, SLA N/A, technician told)
CORRECTIVE (fallen pole, dropped cable…): technician RAISES (2 photos) → TEAM DISPATCHED at once, everyone notified
   └─ optional "Request a support technician" (drop cable → premises) in the same form, worked in parallel
BOTH: REPORT WORK COMPLETED with 2 "after" photos → NOC restore confirmation → resolved
```

* **Raise field maintenance** — *Operations → Raise field maintenance* (or the button on the outage list), permission
  `field_maintenance.raise` (Outage Technician, Infrastructure Team Leader / Manager). Works on a phone: the photo
  fields open the camera or gallery. Required: title, what was found / happened, FAT / FDT label, and two photos —
  **outside the FAT / FDT with the label** and **inside**. Preventive also needs the proposed downtime window.
  Tickets are `PVM-…` (preventive) and `CRM-…` (corrective); these types can't be raised through *Report outage*.
* **Approval** (preventive) — **Approve downtime** / **Reject** on the ticket. The Dispatcher approval needs
  `preventive.approve_dispatch` (Dispatchers, Dispatch / Infrastructure Dispatcher roles) and the manager approval
  `preventive.approve_manager` (Infrastructure Manager); one person gives at most one approval. Approvers are told
  (in-app + e-mail with the photos) when a request is raised.
* **Everyone** — the stakeholder roles and e-mails in *Maintenance Settings* (NOC, CX, Infrastructure leaders by
  default) plus the technician and approvers, in-app and by e-mail, and any notification rules for the events
  `preventive_approved` / `corrective_raised`.
* **Closing** — *Report work completed* asks for the two "after" photos (outside with the label, inside) and cannot
  be submitted without them. The ticket page shows all four photos (before / after); photos are stored privately and
  only signed-in users who can see the ticket can open them.

Rules enforced everywhere (UI, future APIs, integrations) by `App\Services\Outages\OutageWorkflow` +
`App\Policies\OutagePolicy`:

* every transition checks **permission and current workflow state** (even for Super Admin), locks the row, runs in
  one transaction and writes status history (with a snapshot of priority, team, technician, impact, queue, SLA);
* statuses flagged **Prompt for Final Reason** cannot be set without one;
* changing customers affected re-evaluates priority, recalculates SLA and re-ranks open FIFO items;
* topology selections are sanitised (children must belong to selected parents; inactive items cannot be newly attached);
* SMS / Credit are explicit YES/NO fields with *who* and *when*, plus a change history.

## 4G NOC module

4G outages have their **own menu, statuses, workflow, severity rules, SLA targets and notification settings** —
separate from FTTH, but on the same engine (SLA clock, notification rules, alert lists, SMS, audit, reports). A 4G
ticket is an outage whose type carries a 4G workflow; it is listed under **4G NOC → Outages**, never under
*Operations → Outages*, and the FTTH *Report outage* form no longer offers the 4G vertical.

```
INTERNAL (eNodeB Outage 4G-ENB-…, Transmission Outage 4G-TRX-…)
  4G NOC creates → NEW → 4G ENGINEER FIFO QUEUE → next available Engineer / Technician (or one the NOC picks) → ASSIGNED
     └─ rejected / not accepted in time / engineer unavailable → OPEN, back in the queue → the next one → ASSIGNED
  → engineer accepts and investigates → IN PROGRESS (→ MONITORING)
  → engineer records root cause + resolution → RESOLVED – PENDING NOC CONFIRMATION → 4G NOC VERIFICATION FIFO QUEUE
     ├─ NOC verifies the service → CLOSED
     └─ NOC: not restored → back to the same engineer (IN PROGRESS)

VENDOR (Power Outage 4G-PWR-…)
  4G NOC creates and selects the vendor → VENDOR ASSIGNED → the vendor is e-mailed automatically → VENDOR NOTIFICATION SENT
  → the vendor works outside the system; the NOC records each reply with "Add vendor update"
     (VENDOR ACKNOWLEDGED → VENDOR INVESTIGATING → MONITORING)
  → vendor reports "Power Restored" / "Resolved" → VENDOR RESOLVED – PENDING NOC VERIFICATION
     ├─ NOC verifies the site → CLOSED (the vendor gets a closure e-mail)
     └─ NOC: not restored → VENDOR INVESTIGATING

Any time: CANCELLED (duplicates / false alarms, SLA N/A) · Closed tickets: REOPEN with a reason → OPEN
(a reopened engineer ticket goes straight back into the 4G engineer FIFO queue)
```

* **FIFO** — 4G uses the same FIFO engine as FTTH (availability, shift, leave, workload, acknowledgement timeout,
  requeue on reject / timeout, *FIFO Queues* screen, *My Work*), with two queues of its own: **4G Engineer /
  Technician** and **4G NOC Verification**. A new eNodeB / transmission ticket goes to the engineer queue and the
  longest-waiting available engineer or technician gets it; the ticket follows the queue item on its own (Assigned
  when someone holds it, Open when it is back in the queue). The NOC can still pick a person on the form or with
  **Assign / Reassign**, send a ticket back to the queue, or limit the queue to one team of the 4G NOC department
  (e.g. Transmission). Set the queue to *Manual* under *Configuration → FIFO Queues* (4G Engineer / Technician Queue) to always assign by hand.
  Power tickets are with the vendor, so they only enter FIFO for the NOC verification step.
* **Only the NOC closes.** Engineers and technicians (`mobility.work`) can accept, update, attach files and mark a
  ticket *Resolved – Pending NOC Confirmation*; *Verify & close* needs `mobility.verify`. Vendors are **records, not
  users** — no login; only the NOC (`mobility.vendor_update`) records what they say.
* **Report 4G outage** — outage type, title, outage start and detection time, the **eNodeB sites** and / or
  **transmission nodes** that are down, cells down, location, services, description and service impact. Transmission
  types also take a link ID and type (microwave / fibre / other). Power types need the **vendor** (pre-selected from
  the tower company of the first site); internal types can be assigned to an engineer on the form.
* **Severity** is calculated live from what is selected (and can be overridden). A transmission node counts
  everything below it — RSG → ASG → CSG → SSG → eNodeB — so a CSG ring brings its SSGs and their sites with it.
  The rules are data under **4G NOC → Severity Rules** (seeded as given by the 4G NOC; when several match, the most
  severe wins — so 3 eNodeBs is Major):

  | Fault | Minor | Major | Critical |
  |---|---|---|---|
  | eNodeB | any cells / 1 eNodeB | — | — |
  | Transmission | 1–3 eNodeBs | 3–6 eNodeBs, 1–2 SSGs | > 6 eNodeBs, ≥ 3 SSGs, ≥ 1 ASG, ≥ 1 RSG |
  | Power | 1–3 eNodeBs | 3–6 eNodeBs, 1 SSG | > 6 eNodeBs, ≥ 2 SSGs, ≥ 1 ASG, ≥ 1 RSG |

  FTTH keeps its own levels (Low / Medium / High / Critical by customers affected) under *Configuration → Impact /
  Priority Rules*; every level now belongs to a vertical.
* **SLA** — 4G policies are matched on the 4G vertical + severity (seeded: Critical 4 h, Major 8 h, Minor 24 h —
  starting values, change them under *Configuration → SLA Policies*) and can be narrowed to an outage type or a
  **vendor**. The ticket shows the deadline, state (within / approaching / breached) and time to acknowledge,
  respond and resolve. A reopened ticket's clock continues from the original outage start.
* **Vendors** (*4G NOC → Vendors*) — name, code, type, contact person, e-mail, CC addresses, phone, active flag.
  ATC, TOA and UTEL are seeded **without contact details: add each vendor's e-mail before the first power ticket**.
  Inactive vendors cannot be selected. If a vendor has no address (or the mail fails) the ticket stays *Vendor
  Assigned*, the timeline and the NOC are told, and **Send vendor notification** retries.
* **Vendor e-mails** (*4G NOC → Vendor E-mail Templates*) — editable subject / body per trigger (ticket created,
  vendor changed, reminder, reopened, closed, cancelled, manual), to the vendor's e-mail + CC plus any fixed
  addresses, with `{{ticket_number}}`, `{{site_id}}`, `{{site_name}}`, `{{location}}`, `{{outage_type}}`,
  `{{priority}}`, `{{outage_start_time}}`, `{{detection_time}}`, `{{affected_services}}`, `{{description}}`,
  `{{vendor_name}}`, `{{sla}}`, `{{noc_contact}}`, `{{ticket_url}}` and more. Every e-mail is in the notification
  log (ticket, vendor, recipient, CC, subject, template, time, status, error) and on the ticket timeline.
* **Vendor updates** — date / time, communication method, contact person, vendor status (Acknowledged,
  Investigating, Technician Dispatched, Technician On Site, Awaiting Parts, Awaiting Access, Power Restored,
  Monitoring, Unable to Resolve, Escalated, Resolved), ETA, next update time, comments and attachments.
* **Escalation** (*4G NOC → 4G Settings & Alerts*) — no vendor update for X minutes (default 60) reminds the NOC;
  still nothing after Y minutes (default 120) notifies the escalation roles / addresses and e-mails the vendor a
  reminder. A "next update expected" time on a vendor update replaces X. **Escalate** on a ticket notifies the same
  people at once; SLA policies add their own escalation time and roles.
* **Notifications** — 4G tickets fire **only notification rules whose Vertical is 4G** and the **4G alert e-mail /
  SMS lists** (4G Settings), never the FTTH rules or lists. Seeded 4G rules cover created, updates, vendor notified
  / failed / update / silent, resolved-pending, verification failed, closed, reopened, cancelled, escalated and SLA.
* **Hourly update** — *Configuration → Email Templates & Schedules → 4G Hourly Outage Update* (every 60 minutes,
  seeded inactive: set recipients and activate). Loops `{{#for each active 4G outage}}`, `{{#for each closed 4G
  outage}}` (also 4G eNodeB / transmission / power outage, 4G vendor ticket) and `g4_*` totals.
* **Timeline** — every ticket lists creation, assignment, status changes, updates, vendor assignment, e-mails,
  vendor updates, attachments, verification, closure and reopening, each marked **System / NOC / Engineer /
  Technician**. Reopening keeps who, when, why and the previous closure.
* **Audit trail** — every 4G action is written to the audit trail as a named entry on the ticket with the user (or
  *System*), the action, the status, the time and the comment: created, queued, FIFO assigned / returned, engineer
  assigned, investigation started, update posted, resolved, verification failed, status set, status changed
  (previous → new), vendor assigned / changed, vendor e-mail sent / failed / not sent, vendor update, no vendor
  update / escalated, attachment added, escalated, closed, reopened and cancelled — next to the field-by-field
  changes of the ticket. Vendor updates, attachments, reopenings, vendors, sites, transmission nodes, severity
  rules, vendor templates and the 4G settings are audited too. See it on the ticket (*Audit trail* tab), under
  **4G NOC → 4G Audit Trail** (the audit trail filtered to the module) and as the **4G Audit Trail** report;
  all need the `audit.view` permission.
* **Dashboard and reports** — *4G NOC → Dashboard* (open, critical, pending confirmation, per category, SLA, MTTR,
  vendor and engineer performance). *Reporting → Reports*: 4G Outage, Power, Transmission, eNodeB, Vendor
  Performance, Engineer Performance, SLA Compliance, MTTR and Root Cause Analysis (CSV / Excel / PDF).
* **Topology** — *4G NOC → eNodeB Sites* and *Transmission Nodes*, loaded from the 4G workbook:

  ```bash
  php artisan db:seed --class=MobilityTopologySeeder --force   # bundled database/seeders/data/4g-*.csv
  php artisan oms:import-4g-topology "4G NOC TOPOLOGY.xlsx" --dry-run
  ```

  or *eNodeB Sites → Import 4G topology*. The hierarchy comes from the hostname numbering (`ssg-1234-…` → CSG 123 →
  ASG 12 → RSG 1); SSGs without a site are imported inactive. Re-importing updates, never duplicates or removes.
* **New 4G outage types** (fibre cut, battery, generator, microwave…) — *Configuration → Outage Types*: pick one of
  the two workflows, a fault domain and a prefix; then add its severity rules, SLA policy, notification rules and
  vendor template. No code change.
* **Roles** — `4G NOC Manager`, `4G NOC`, `4G Engineer`, `4G Technician` (permissions `mobility.*`); put 4G staff in
  the **4G NOC** department. FTTH roles do not see 4G tickets and the other way round (Executive sees both).

**Adding the module to a production system that already holds data** — run the migration, then the 4G-only seeder
(not `ReferenceDataSeeder` / `RolesAndPermissionsSeeder`, which also re-apply FTTH defaults):

```bash
php artisan migrate --force
php artisan db:seed --class=MobilitySeeder --force
```

`MobilitySeeder` only **creates what is missing** and updates nothing: existing priorities, statuses, outage types,
queues, SLA policies, notification rules, e-mail templates, departments and role permissions stay exactly as they
are. It adds the 4G permissions and roles, the 4G NOC department, the 4G outage types, statuses, severity levels and
rules, SLA policies, queues, notification rules, the hourly template, the vendors (ATC, TOA, UTEL), the vendor
e-mail templates and the eNodeB sites / transmission nodes. It can be run again safely. *4G Settings & Alerts*
needs no seeding — it starts from built-in defaults until saved on screen.

## FIFO assignment engine

Implemented as UI-independent domain services — `App\Services\Fifo\FifoAssignmentEngine` and
`App\Services\Fifo\EligibilityEvaluator` — so the same logic can be driven by the UI, the scheduler, APIs,
monitoring integrations or a mobile app.

**Queues** (configurable under *Configuration → FIFO Queues*): NOC Review, Infrastructure Dispatch, Technician,
NOC Restore Confirmation, CX Follow-up (CX Tier II), Support Technician, IT Review, IT Confirmation and CX Customer
Notification (planned maintenance). Each queue
definition sets department, optional team, routing (major type / vertical), required permission, automatic or
manual mode, acknowledgement timeout and escalation threshold. The **Support Technician** queue is *parallel*: its
assignment never replaces the ticket's main queue / owner.

**Ordering — priority-aware FIFO**: Critical → High → Medium → Low (rank editable per priority), oldest first within
each priority using microsecond queue timestamps with the id as final tie-break. Can be switched to strict FIFO.

**Eligibility rules** (each can be toggled in *FIFO & SLA Settings*):

| Rule | Requirement |
|---|---|
| User status | Active users only |
| Availability | Status *Available* |
| Department | Belongs to the queue's department |
| Permission | Holds the queue permission (`queue.work_noc`, `queue.work_infrastructure`, `queue.work_technician`, `queue.work_cx`, `queue.work_support`, `queue.work_it`) |
| Team / sub-team | Member of the required team (or its sub-teams) / sub-team |
| Shift | Inside an active scheduled shift |
| Leave | Not on approved leave |
| Skill (optional) | Skill for an affected service or the vertical |
| OLT coverage | **Off by default** (every technician can take any OLT). When "Restrict technicians to their teams’ OLT coverage" is on: a team of the user covers the affected OLT — Infrastructure, Technician and Support Technician queues |
| Workload | Below the maximum active tickets (per user or global); optional Critical bypass |
| Previous attempts | Has not already rejected / timed out on this ticket |

**User selection**: the eligible user who has waited longest since their last assignment (never-assigned first),
then earliest availability, then user id.

**Lifecycle**: acknowledgement timeout → requeue and notify leaders; rejection with reason → requeue to the next
user; user becomes unavailable / goes on leave / shift ends → configured requeue rule; repeated rejections or
timeouts → escalation to managers; when requeue is disabled the ticket is held for manual assignment; authorised
manager override is recorded with who, when, why and which rules the target failed. A returned ticket keeps its
original place in line.

**Audit & explainability**: every assignment stores queue time, priority at assignment, queue position, rules
applied, the eligible ranking and exclusion counts; every queue / assign / accept / reject / timeout / release /
override event is written to the assignment history.

**Workflow enforcement**: when a step is assigned, only its assignee (or a `queue.override` holder) can perform it;
acting on it implicitly accepts the assignment.

**Clusters**: each queue is processed under a distributed cache lock, so every node can run the engine safely.

## SLA management

* Policies match on major type, outage type, vertical, priority and service (empty = any); the **most specific
  active policy** wins, with a configurable fallback.
* Deadline = start time + resolution target (+ paused time). Pre-breach time = deadline − warning window.
* States: Compliant → Approaching Breach → Breached while open; Resolved within / outside SLA on closure; N/A for
  NOC-rejected outages.
* Statuses flagged **Pauses SLA** (e.g. Scheduled Maintenance) pause and resume the clock (per-policy opt-out).
* `oms:sla-check` (every minute) sends **pre-breach**, **breach**, **credit-due** and **time-based escalation**
  notifications exactly once per outage, and records SLA events.
* Policies can override the FIFO acknowledgement timeout.

## Notifications

* Event-driven rules (*Configuration → Notification Rules*) filtered by major type, outage type, vertical, priority
  and status; recipients by role, user, e-mail, phone, current assignee and team leaders / managers.
* Channels: **in-app** (Filament bell + real-time toast), **e-mail**, **SMS** (Africa's Talking, Twilio, generic HTTP or `log`).
* Events: outage created, NOC escalation, NOC confirmed / rejected, Infrastructure escalation, dispatched, status
  changed, progress update posted, restoration reported / confirmed, resolved, priority changed, assigned,
  assignment rejected, acknowledgement timeout, queue escalation, SLA pre-breach / breach / escalation, credit due,
  credit shared, SMS sent, scheduled report.
* Every recipient × channel is logged (recipient, event, time, status, attempts, failure reason); failed deliveries
  can be retried from *Monitoring → Notification Log*.
* **In-app** alerts are written to the bell immediately after the ticket change commits — they do not wait for a
  queue worker (a failure falls back to the queue for retries). The bell also polls every 30 s.
* **E-mail, SMS and the live Reverb push / toast** are delivered by queued jobs with retry and backoff, so a queue
  worker (and `reverb:start` for live updates) must be running — `composer run dev` starts both.
* A recipient gets one message per channel per event, even when several rules (and the alert list) match them.

### Lifecycle alerts (in-app + e-mail + SMS)

Each stage below is delivered in-app, by e-mail and by SMS (SMS reaches users with a phone number on their profile).

| Stage | Event | Who is alerted (seeded defaults) |
|---|---|---|
| Ticket created | `outage_created` | NOC Manager, NOC Team Leader (+ managers / executives for Critical) |
| Assigned to NOC | `noc_escalation` + `assigned` | NOC Team Leader / Tier II / Tier I, and the NOC user FIFO assigned |
| NOC rejected | `noc_rejected` | CX Team Leader |
| Assigned to Infrastructure | `infrastructure_escalation` + `assigned` | Infrastructure Team Leader / Dispatchers, and the assigned dispatcher |
| Dispatched to field team | `dispatched` + `assigned` | Infrastructure Team Leader, team leaders, and the assigned technician |
| Progress update posted | `update_posted` | Team leaders of the assigned team |
| Closed by outage technician | `restore_reported` | NOC Team Leader, NOC Manager, team leaders |
| Resolved and closed | `resolved` | NOC Manager, CX Team Leader, team leaders |
| NOC rejected → CX | `noc_rejected` + `assigned` | CX Team Leader, the reporter, and the CX Tier II agent FIFO assigned |
| Customer callback recorded | `cx_follow_up` | CX Team Leader, NOC Team Leader (in-app + e-mail) |
| Site visit raised | `site_visit_raised` | Dispatch Team Leader / Manager, CX Team Leader |
| Support technician requested | `support_requested` + `assigned` | Dispatch Team Leader / Manager, team leaders, and the support technician assigned |
| Support work completed | `support_completed` | Infrastructure Team Leader, team leaders, the requester and the outage technician |
| System outage to IT | `it_escalation` + `assigned` | IT Team Leader / Manager, and the IT engineer assigned |
| IT escalated to NOC | `noc_escalation` + `assigned` | NOC Team Leader / Tier II / Tier I, and the NOC user assigned |
| NOC resolved → IT | `returned_to_it` + `assigned` | IT Team Leader, and the IT engineer assigned to confirm |
| IT confirmed resolved | `resolved` | IT Manager / Team Leader, NOC Manager, CX Team Leader, and the reporter (with IT's feedback) |

**Super Admin** follows every stage above in the bell through the *Admin feed – …* rules (in-app only, so
administrators get no extra e-mail / SMS). Deactivate them under *Configuration → Notification Rules* if not wanted.

### Alert e-mails & SMS (specific addresses and numbers)

*Configuration → Alert E-mails & SMS* (permission `notifications.manage`), with an **E-mail** and an **SMS** tab:

* **Alert e-mail addresses / phone numbers** — any number of addresses (or distribution lists) and phone numbers
  that receive every selected event, independent of roles and rules.
* **Events** — chosen separately for e-mail and SMS (both default to: created, assigned to NOC, NOC confirmed /
  rejected, assigned to Infrastructure, dispatched, updates, closed by technician, restore confirmed, resolved, SLA
  breach). Untick events you only want by e-mail to control SMS cost.
* **E-mail / SMS the assignee** — the person FIFO (or a dispatcher / manager) assigns a ticket to also gets an e-mail
  and an SMS (SMS uses the phone number on their user profile).
* **Send test e-mail / Send test SMS** — check the configuration; the screen warns when mail or SMS is only written
  to the log.

**Phone numbers** are stored in international (E.164) format; local numbers such as `0991 234 567` become
`+265991234567` using `SMS_DEFAULT_COUNTRY_CODE`. Invalid numbers are rejected on the form and skipped when sending.
SMS text is short (ticket, stage, title, impact, status, owner) and capped at `SMS_MAX_LENGTH` characters.

**SMS gateways** (`SMS_DRIVER`):

| Driver | Settings | Notes |
|---|---|---|
| `log` | — | Development: messages are written to the log, nothing is sent |
| `africastalking` | `AFRICASTALKING_USERNAME`, `AFRICASTALKING_API_KEY`, `AFRICASTALKING_SANDBOX`, `SMS_SENDER` | Per-recipient rejections (e.g. insufficient balance) are recorded as failures |
| `twilio` | `TWILIO_SID`, `TWILIO_TOKEN`, `TWILIO_FROM` | REST API, no SDK |
| `http` | `SMS_URL`, `SMS_TOKEN`, `SMS_SENDER` | Generic JSON `POST {to, message, sender}` with a bearer token |

Failed SMS (like e-mail) are retried with backoff and visible, with the provider's reason, in *Monitoring →
Notification Log*.

Notification rules are seeded with `firstOrCreate`, so re-running the seeders never overwrites rules an
administrator has edited.

## Reports and exports

*Reporting → Reports* — filters: date range, state, status, priority, major type, outage type, vertical, OLT, FDT,
service, team / sub-team, technician, SLA state. Every report exports to **CSV, Excel (.xlsx) and PDF**.

| Report | Content |
|---|---|
| Outage Register | Full row per outage (spec §17 columns + title, priority, services, duration, team, technician, SLA) |
| Summary Report | Exact spec §17 structure |
| SLA Compliance | Policy, due, end, duration, state, breach / margin minutes; compliance % |
| Critical / Major Incident | Critical and High incidents with owner, escalations and latest action |
| Customer Impact | Customers, SMS, credit per outage; totals |
| OLT / FDT Impact | Outages, customers and average restoration per OLT / FDT |
| Service Impact | Outages, customers, active count per service |
| Team Performance | Assigned, acknowledged, rejected, timed out, restored, breached, avg ack / restoration |
| Technician Performance | Same metrics per technician |
| SMS & Credit | YES/NO with who / when |
| Core Outage | Register filtered to Core |
| 4G Site / Cell Outage | Sites down, cells down, power faults |
| System Outage | Register filtered to System |
| Recurring Outage | Locations with two or more outages in the period |
| Planned Maintenance | CRF window, reschedules, risk, sign-off, outcome, customer notification, SLA |
| FIFO Assignment History | Every queue / assignment event |
| Audit Trail | Every recorded change with user, trigger and IP |

The outage list's **Export Excel** button exports exactly the current tab, filters, search and sort. CSV and
Excel exports stream (no row limit); PDF is capped at 2,000 rows; on-screen view shows `OMS_REPORT_SCREEN_LIMIT` rows.

## Scheduled e-mails and templates

*Configuration → Email Templates & Schedules*: template name, report type, schedule (every N minutes, fixed daily
times, or cron), reporting period, TO, CC, subject, body, optional Excel attachment, active flag, last / next run,
**Preview** and **Send now**.

Seeded (inactive until recipients are set):

| Template | Schedule | Content |
|---|---|---|
| Two-Hour Outage Update | Every 120 minutes | Spec §15 operational update |
| Executive Summary | 00:00, 06:00, 12:00, 18:00 | Spec §14 / §16 executive format with trend and top risk areas |
| Summary Report | Daily 07:00 | Spec §17 table + Excel attachment |

Placeholders (values are HTML-escaped; `*_table` values are OMS-generated tables):

```
{{report_time}} {{report_date}} {{period_start}} {{period_end}} {{active_outages}} {{new_outages}}
{{resolved_outages}} {{critical_outages}} {{customers_affected}} {{sla_compliant}} {{sla_at_risk}}
{{sla_breached}} {{sms_sent}} {{sms_pending}} {{credits_shared}} {{credits_pending}} {{oldest_active}}
{{sites_down}} {{cells_down}} {{power_faults}} {{other_4g}} {{core_summary_or_no_core_outages}}
{{system_summary_or_no_system_outages}} {{major_incidents_text}} {{closed_summary}}
{{summary_table}} {{trend_table}} {{top_olts_table}} {{top_services_table}} {{active_table}}
```

Loops: `{{#for each active network outage}} … {{/for}}` (also *major incident*, *core outage*, *system outage*,
*4G outage*, *FTTH outage*, *closed outage*) or `{{#each network_outages}} … {{/each}}`. Item keys: `ticket, title,
location, olt_fdt, start_time, end_time, customers, priority, noc_time, maintenance_time, sms_status,
credit_status, sla_status, status, owner, latest_action, final_reason, sites_down, cells_down, power_faults, services`.

**Bold:** wrap text in double asterisks on one line — `**LIVE NETWORK OUTAGES – FTTH**`, or inside a loop
`**{{ticket}} | {{location}}**` to bold each outage's heading line. Values from the data are always escaped and can
never turn bold or inject HTML; an unmatched `**` stays as typed; subjects simply drop the markers. The seeded
templates use bold for section headers and each outage's ticket line.

Each schedule slot is claimed atomically, so a report is never sent twice even with several servers.

Templates are seeded only when missing: re-running the seeders never overwrites a template's recipients, schedule,
activation or wording. An untouched default body is upgraded to the latest default (e.g. the bold headers).

## Real-time updates (Reverb)

Broadcast events: `OutageCreated`, `OutageUpdated`, `OutageAssigned`, `OutageAccepted`, `OutageRejected`,
`AssignmentTimeout`, `OutageStatusChanged`, `PriorityChanged`, `TechnicianAvailabilityChanged`.

* Queued and dispatched **after the database commit** — a websocket problem never blocks or rolls back work.
* Sent on the private `oms.operations` channel (active users with `outage.view`) and each involved user's private
  channel; payloads contain identifiers only.
* Dashboard widgets, outage list / page, FIFO Queues and My Work re-render on events; polling (60–120 s) is only a fallback.
* Assignees get Filament notifications (bell + toast) in real time.
* Filament's Echo client is configured in `config/filament.php` from the `REVERB_*` / `VITE_REVERB_*` variables.

## Roles and permissions

Access is **permission-based**; roles are editable bundles (*People → Roles & Permissions*). Roles cannot be
deleted from the UI. Super Admin holds every permission but still obeys workflow-state rules.

| Group | Permissions |
|---|---|
| Outages | `outage.view`, `outage.create`, `outage.edit`, `outage.delete`, `outage.confirm`, `outage.dispatch`, `outage.update_progress`, `outage.update_restoration`, `outage.confirm_restoration`, `outage.close`, `outage.change_status`, `outage.update_credit_sms`, `outage.override_priority`, `outage.cx_follow_up`, `outage.request_support`, `outage.support_work`, `outage.it_review` |
| Maintenance | `maintenance.request`, `maintenance.manage`, `maintenance.reschedule`, `maintenance.notify_customers`, `maintenance.configure`, `field_maintenance.raise`, `preventive.approve_dispatch`, `preventive.approve_manager` |
| FIFO Queues | `queue.view`, `queue.work_noc`, `queue.work_infrastructure`, `queue.work_technician`, `queue.work_cx`, `queue.work_support`, `queue.work_it`, `queue.override` |
| Configuration | `topology.manage`, `sla.manage`, `templates.manage`, `notifications.manage`, `master_data.manage`, `config.admin` |
| People | `users.manage`, `teams.manage`, `roles.manage`, `workforce.manage` |
| Reporting & Audit | `reports.view`, `reports.export`, `audit.view` |

Seeded roles (spec §4.1):

| Department | Roles |
|---|---|
| NOC | NOC Manager, NOC Team Leader, NOC Tier II, NOC Tier I |
| Customer Experience | CX Manager, CX Team Leader, CX Tier II (NOC-rejected callbacks), CX Agent |
| Dispatch | Dispatch Manager, Dispatch Team Leader, Dispatcher, Support/Installation Technician (support requests) |
| Infrastructure | Infrastructure Manager, Infrastructure Team Leader, Infrastructure Dispatcher, Outage Technician |
| IT | IT Manager, IT Team Leader, IT Engineer (system outages) |
| Management | Executive (read-only), Super Admin |

The role seeder only **adds** missing permissions to existing roles (it never removes any), so on an existing
installation review *Support/Installation Technician* — it no longer needs `queue.work_technician`,
`outage.update_restoration` or `outage.change_status` now that it works its own Support Technician queue.

## Importing the network topology (OLT → FDT → Slot → Port)

The mapping is a spreadsheet (`.xlsx` or `.csv`) whose first row is the header **OLT | FDT | SLOT | PORT** (one row
per OLT port). The current network is bundled as `database/seeders/data/topology.csv` (20 OLTs, 425 FDTs, 840
slots, 3,527 ports) and seeded by **`TopologySeeder`**, which runs with `php artisan db:seed`:

```bash
# Seeder: the bundled mapping (or storage/app/private/topology/mapping.xlsx|csv when present — it takes precedence)
php artisan db:seed --class=TopologySeeder --force

# Command, for any file (preview first, then import)
php artisan oms:import-topology /path/to/MAPPING.xlsx --dry-run
php artisan oms:import-topology /path/to/MAPPING.xlsx --missing=remove
```

…or **Configuration → OLTs → Import topology** (permission `topology.manage`) with a *Preview only* switch.

* **Safe to re-run** — existing items are matched case-insensitively and reused; only missing ones are created.
* **Tidying** — names are trimmed and spaces collapsed; FDT names are upper-cased so `Ntd FDT 1` / `NTD FDT 1` become
  one FDT. The same FDT name under different OLTs (e.g. `FDT 24`) stays separate. Slots / ports must be numbers.
* **Bad rows** (empty OLT / FDT, non-numeric slot or port) stop the whole import and are listed with row numbers.
* **Items not in the file** (`--missing`): `keep` (default); `deactivate` (hidden from selection lists); `remove`
  (deleted — except those still on tickets, which are deactivated so ticket history keeps its OLT / FDT / port).
* Each import writes one `topology_imported` entry to the audit trail with the counts.
* **Updating the bundled mapping** — replace `database/seeders/data/topology.csv` (same four columns) and re-seed,
  or drop a newer file in `storage/app/private/topology/` (git-ignored) on the server.
* The bundled mapping describes the live network: keep the repository **private**.

## Seeded reference data

`php artisan db:seed` always seeds the reference data below (idempotent — safe to re-run on every deploy); demo
users and outages are added only outside production. Each seeder can also be run on its own, e.g.
`php artisan db:seed --class=PrioritySeeder --force`.

| Seeder | Seeds |
|---|---|
| `RolesAndPermissionsSeeder` | Permission catalogue + roles above |
| `DepartmentSeeder` | NOC, Customer Experience, Dispatch, Infrastructure, IT, Management |
| `VerticalSeeder` | FTTH, 4G Mobility |
| `ServiceSeeder` | FTTH: Internet, Voice (VoIP), IPTV, Enterprise Fibre · 4G: Mobile Data, Voice, SMS, Fixed Wireless (MiFi) · Cross-vertical: CRM, Call Center, Dialer, Billing (Savbill), International Transit (SEACOM) |
| `MajorOutageTypeSeeder` | Network (topology, NOC + Infrastructure), Core (NOC + Infrastructure), System (IT first, NOC on escalation) |
| `OutageTypeSeeder` | Regular `OUT`, Emergency `EMO`, Planned Maintenance `PLM`, SEACOM / Core `CORE`, System `SYS`, Preventive Maintenance `PVM`, Corrective Maintenance `CRM` |
| `OutageStatusSeeder` | Support Unconfirmed, NOC Confirmed, NOC Reject, Assigned to Infrastructure, Infrastructure Confirmed, Team Dispatched, Awaiting Field Access, Vendor Escalated, Partial Restore, Restore Reported – Awaiting NOC, NOC Restore Confirmed, Awaiting Customer Confirmation, Outage Resolved, Scheduled Maintenance, NOC Rejected – CX Follow-up (pauses SLA), IT Review, Escalated to NOC by IT, NOC Resolved – Awaiting IT Confirmation |
| `PrioritySeeder` | Low 0–9, Medium 10–49, High 50–199, Critical 200+ customers; FIFO rank Critical 1 → Low 4 |
| `SlaPolicySeeder` | Critical 4h, High 8h, Medium 12h, Low 24h (pre-breach, ack timeout, escalation), Planned Maintenance window |
| `QueueDefinitionSeeder` | NOC Review (NOC, 10 min ack), Infrastructure Dispatch (INFRA, 15), Technician (INFRA, 20), NOC Restore (NOC, 15), CX Tier II Follow-up (CX, 30), Support Technician (DISPATCH, 20), IT Review (IT, 15), IT Confirmation (IT, 15), CX Customer Notification (CX, 30) |
| `NotificationRuleSeeder` | In-app + e-mail + SMS lifecycle alerts: ticket created, assigned to NOC, NOC rejected, CX callback, site visit raised, assigned to Infrastructure, dispatched, support technician requested / completed, assigned to IT, returned to IT, progress update, closed by technician, resolved (plus IT feedback for system outages); critical outages, queue escalation, credit due; in-app *Admin feed* for Super Admin |
| `EmailTemplateSeeder` | Two-Hour Update, Executive Summary, Summary Report |
| `MaintenanceTemplateSeeder` | Planned maintenance e-mails: CRF for review, sign-off request, fully signed, changes requested, reminder, started, rescheduled, completed, cancelled (created only when missing) |
| `DemoDataSeeder` | Non-production only: teams, users for every role, shifts, skills (no tickets, no topology) |
| `TopologySeeder` | OLTs, FDTs, slots and ports from `database/seeders/data/topology.csv` (runs with `db:seed`; safe to re-run, never removes) |

## Configuration screens

| Menu | Screen | Notes |
|---|---|---|
| Operations | Command Centre, My Work, Outages, FIFO Queues | |
| Reporting | Reports | |
| Topology | OLTs, FDTs, Slots, Ports | Hierarchical; OLT team coverage; deactivate instead of delete |
| Configuration | FIFO & SLA Settings | All spec §27.8 options, SLA fallbacks, pause switch |
| Configuration | Verticals, Affected Services, Major Outage Types, Outage Types (prefixes), Statuses (final reason / pauses SLA / manual / closed flags), Impact / Priority Rules (thresholds + FIFO rank), SLA Policies, FIFO Queues, Notification Rules, Alert E-mails & SMS, Email Templates & Schedules | Notification rules cannot be deleted — deactivate them |
| People | Departments, Teams & Sub-teams (leader, manager, members, OLT coverage), Users (roles, teams, skills, shifts incl. recurring generator, leave, availability history, MFA reset), Roles & Permissions | |
| Monitoring | Notification Log (retry failed), Audit Trail | |

Every master record stores created by / at and edited by / at. Master data referenced by history is deactivated,
never deleted (restrict-on-delete foreign keys).

## Data model

| Area | Tables |
|---|---|
| People | `users`, `departments`, `teams` (sub-teams via `parent_id`), `team_user`, `user_skills`, `shift_assignments`, `user_leaves`, `user_availabilities`, spatie `roles` / `permissions` tables |
| Master data | `verticals`, `services`, `major_outage_types`, `outage_types`, `outage_statuses`, `priorities`, `sla_policies` |
| Topology | `olts`, `fdts`, `slots`, `ports`, `olt_team` |
| Outages | `outages`, `outage_olt`, `outage_fdt`, `outage_slot`, `outage_port`, `outage_service`, `outage_status_histories`, `outage_updates`, `outage_flag_events`, `sla_events`, `ticket_sequences` |
| FIFO | `queue_definitions`, `outage_queue_entries`, `outage_assignments`, `outage_assignment_histories` |
| Notifications & reports | `notification_rules`, `notification_logs`, `email_templates`, `notifications` (Filament bell) |
| Audit & settings | `audit_logs`, `settings` |
| Framework | `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens` |

All migrations use column types that behave identically on MySQL and PostgreSQL (no enums, `dateTime`,
portable booleans and JSON), with composite indexes on the FIFO ordering, SLA and dashboard hot paths.

## Project structure

```
app/
  Console/Commands/        oms:sla-check, oms:fifo-timeouts, oms:fifo-sweep, oms:send-scheduled-reports, oms:maintenance-tick, oms:create-admin
  Enums/                   QueueType, SlaState, AssignmentStatus, AvailabilityStatus, NotificationEvent, StatusCode, …
  Events/                  Reverb broadcast events (OmsBroadcastEvent base)
  Filament/
    Concerns/              RefreshesOnOmsEvents (Echo listeners), GuardsWithPermission
    Pages/                 Dashboard, MyWork, FifoQueue, Reports, FifoSettings
    Resources/             Outages (form, actions, relation managers) + all configuration resources
    Widgets/               Command centre widgets
  Http/Middleware/         SecurityHeaders, EnsureUserIsActive
  Jobs/                    SendNotificationJob, SendScheduledReportJob
  Mail/                    OutageNotificationMail, ScheduledReportMail
  Models/                  Eloquent models (+ Concerns/Auditable, HasAuditColumns)
  Policies/                OutagePolicy
  Services/
    Fifo/                  FifoAssignmentEngine, EligibilityEvaluator
    Notifications/         NotificationDispatcher, message factory, SMS gateways
    Outages/               OutageWorkflow, TicketNumberGenerator, PriorityResolver
    Reports/               ReportRegistry, ReportExporter, OperationalSnapshot, DashboardMetrics,
                           TemplateRenderer, ScheduledReportService
    Sla/                   SlaService
  Support/                 Settings, Permissions, AuditContext
config/oms.php             Queues, SMS, security, trusted proxies, report limits
database/migrations/       Schema
database/seeders/          Reference data + demo data
public/css/oms.css         Custom styles (loaded with a content-hash URL, no build step)
resources/views/           E-mails, PDF report, custom Filament views, brand, layout override (tab title)
public/images, favicon*     App icon set (generated from resources/images/oms-icon-source.webp)
routes/console.php         Scheduler
routes/channels.php        Broadcast channel authorisation
tests/Feature/             FIFO, workflow, SLA, reports, panel & security tests
```

## Artisan commands and scheduler

| Command | Schedule | Purpose |
|---|---|---|
| `oms:sla-check` | every minute | SLA states, pre-breach / breach / credit-due / escalation notices |
| `oms:fifo-timeouts` | every minute | Requeue / escalate unacknowledged assignments |
| `oms:fifo-sweep` | every minute | Release tickets of users who became ineligible; assign waiting tickets |
| `oms:send-scheduled-reports` | every minute | Queue due scheduled e-mails |
| `oms:maintenance-tick` | every minute | Planned-maintenance reminders, before-start e-mails and "started" alerts |
| `oms:mobility-tick` | every minute | 4G NOC: remind the NOC / escalate to management when a vendor has not updated a ticket |
| `oms:import-4g-topology {file} [--dry-run]` | manual | Import the 4G workbook: eNodeB sites and transmission nodes (RSG → ASG → CSG → SSG) |
| `oms:prune-expired-cache` (scheduled closure) | hourly | Clean expired rows when the database cache driver is used |
| `queue:prune-failed`, `queue:prune-batches` | daily | Housekeeping |
| `oms:create-admin` | manual | Create a Super Admin |
| `oms:import-topology {file} [--dry-run] [--missing=keep\|deactivate\|remove]` | manual | Import the OLT → FDT → Slot → Port mapping |
| `oms:purge-outages [--force]` | manual | **Delete all outage tickets** and their data (history, queues, notifications, CRFs, photos) and restart ticket numbering. Asks you to type PURGE; back up first |

All scheduled tasks use `onOneServer()` and `withoutOverlapping()`.

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `APP_TIMEZONE` | `UTC` | Timezone for storage and display |
| `APP_FORCE_HTTPS` | `false` | Force https URLs behind a TLS load balancer |
| `DB_CONNECTION` | `mysql` | `mysql` or `pgsql` |
| `SESSION_DRIVER` / `CACHE_STORE` / `QUEUE_CONNECTION` | `database` | Use `redis` for multiple servers |
| `SESSION_ENCRYPT` | `true` | Encrypted session payloads |
| `TRUSTED_PROXIES` | empty | Load balancer IPs / CIDRs (or `*` behind a trusted LB) |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | — | Reverb credentials |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | `localhost`, `8090`, `http` | Where browsers / the app reach Reverb |
| `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT` | `0.0.0.0`, `8090` | Where `reverb:start` listens |
| `REVERB_SCALING_ENABLED` | `false` | Redis pub/sub for multiple Reverb servers |
| `OMS_REQUIRE_MFA` | `false` | Enforce authenticator-app MFA for all users |
| `OMS_HSTS` | `false` | Send Strict-Transport-Security |
| `OMS_SEED_DEMO` | `true` | Seed demo data (never in production) |
| `OMS_REPORT_SCREEN_LIMIT` | `500` | Rows rendered on the Reports page |
| `OMS_NOTIFICATIONS_QUEUE`, `OMS_REPORTS_QUEUE` | `default` | Dedicated queues for scaling workers |
| `SMS_DRIVER` | `log` | SMS gateway: `log`, `africastalking`, `twilio` or `http` |
| `SMS_SENDER`, `SMS_DEFAULT_COUNTRY_CODE`, `SMS_MAX_LENGTH` | `OMS`, `265`, `306` | Sender ID, calling code for local numbers, message length cap |
| `AFRICASTALKING_USERNAME`, `AFRICASTALKING_API_KEY`, `AFRICASTALKING_SANDBOX` | `sandbox`, —, `true` | Africa's Talking credentials |
| `TWILIO_SID`, `TWILIO_TOKEN`, `TWILIO_FROM` | — | Twilio credentials |
| `SMS_URL`, `SMS_TOKEN` | — | Generic HTTP provider |
| `MAIL_*` | — | Mail transport for notifications and scheduled reports |

## Testing

```bash
php artisan test
```

29 feature tests (174 assertions) covering: ticket numbering, rule-driven priority, SLA start / pre-breach / breach,
NOC reject rules, the full lifecycle, permission and workflow-state enforcement (including Super Admin), topology
sanitisation, SMS / credit audit, final-reason enforcement, every FIFO acceptance criterion (queueing, eligibility
exclusions, workload, priority-aware ordering, longest-waiting user, rejection, timeout, override, assignee
enforcement, dispatcher eligibility, availability changes), all 17 reports + Excel export, template rendering and
escaping, scheduled-report de-duplication, every panel page rendering, access control, inactive users and security
headers.

## Production deployment and load balancing

```
            ┌──────────── Load balancer (TLS, websocket upgrade for /app) ────────────┐
            │                                                                         │
   web nodes 1..n (PHP-FPM, stateless)            Reverb node(s) (REVERB_SCALING_ENABLED=true)
            │                                                                         │
            └── Redis (sessions, cache, locks, queues, Reverb pub/sub) ── PostgreSQL / MySQL
   worker nodes: queue:work (scale per queue)       every node: schedule:run (onOneServer)
```

Checklist:

1. `APP_ENV=production`, `APP_DEBUG=false`, the same `APP_KEY` on every node.
2. `SESSION_DRIVER=redis`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis` — no sticky sessions required.
3. `TRUSTED_PROXIES=<LB addresses>`, `APP_FORCE_HTTPS=true`, `SESSION_SECURE_COOKIE=true`, `OMS_HSTS=true`.
4. Reverb behind the load balancer with websocket upgrade; `REVERB_SCALING_ENABLED=true`; set `REVERB_HOST` /
   `REVERB_PORT=443` / `REVERB_SCHEME=https` (and the `VITE_REVERB_*` equivalents) to the public endpoint.
5. Deploy:
   ```bash
   composer install --no-dev --optimize-autoloader
   php artisan migrate --force
   php artisan db:seed --force          # idempotent reference data (no demo data in production)
   php artisan optimize                 # config, route, view and event caches
   php artisan filament:optimize
   php artisan filament:assets
   php artisan queue:restart
   php artisan reverb:restart
   ```
6. Supervise `queue:work` and `reverb:start` with Supervisor or systemd; run `schedule:run` from cron on every node.
   Ready-made files (edit the path / user if yours differ):
   ```bash
   sudo apt install -y supervisor
   sudo cp deploy/supervisor/oms.conf /etc/supervisor/conf.d/oms.conf
   sudo supervisorctl reread && sudo supervisorctl update
   sudo cp deploy/cron/oms /etc/cron.d/oms && sudo chmod 644 /etc/cron.d/oms
   ```
   Run workers as the web-server user (`www-data`) and keep `storage/` and `bootstrap/cache/` writable by it:
   `sudo chown -R $USER:www-data storage bootstrap/cache && sudo chmod -R ug+rwX storage bootstrap/cache`
   `sudo find storage bootstrap/cache -type d -exec chmod g+s {} +`
7. Load balancer health check: `GET /up`.
8. Enable OPcache (`opcache.validate_timestamps=0` in production).

## Performance

* Stateless web tier; shared Redis for sessions, cache, locks and queues.
* Indexed hot paths: FIFO ordering (`queue_type, status, priority_rank, queued_at`), active outages by type /
  priority / SLA, assignments by user / status / ack deadline, audit and notification logs by time.
* Eager loading throughout; strict models in development (lazy-loading violations are logged, missing attributes
  throw) to catch N+1 queries early.
* Dashboard aggregates cached for seconds and invalidated by real-time events, so many wallboards share one set of
  queries; settings, priorities, notification rules, status codes and major-type flags cached (arrays only).
* Real-time pushes replace aggressive polling; Filament SPA mode.
* Streamed CSV / Excel exports; queued notifications with retry / backoff; post-commit FIFO processing.
* Aggregations that differ between SQL dialects are computed in PHP for identical results on MySQL and PostgreSQL.

## Security

* Permission-based authorisation on every page, resource, action and workflow transition (policies); Super Admin
  bypass limited to permissions, never to workflow-state rules.
* Deactivated users are signed out on their next request and excluded from FIFO.
* Authenticator-app MFA with recovery codes (optional, or enforced with `OMS_REQUIRE_MFA=true`); login throttling.
* Strong password policy in production (12+ characters, mixed case, numbers, symbols).
* Security headers on every response: `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`,
  `Permissions-Policy`, `Cross-Origin-Opener-Policy`, CSP `frame-ancestors` / `base-uri` / `form-action`, optional HSTS.
* Encrypted sessions, CSRF protection, authorised private broadcast channels, IDs-only websocket payloads.
* Append-only audit log (who, what, before / after, trigger, IP, user agent, URL) plus status, assignment, SLA and
  SMS / credit histories; automated actions record the system event that triggered them.
* Master data deactivated rather than deleted; roles and notification rules cannot be deleted from the UI.
* Export rate limiting and spreadsheet formula-injection protection; e-mail template output escaped.
* `prohibitDestructiveCommands` in production; cache refuses to unserialize PHP objects; no default credentials in
  production (`oms:create-admin`).

## Troubleshooting

| Symptom | Fix |
|---|---|
| Bell is empty | Bell entries only go to users matched by a notification rule (role, user, assignee, team leader). Check *Monitoring → Notification Log* for the event and channel `database`. |
| Live updates / toasts / e-mails / SMS don't arrive | Make sure `reverb:start` **and** a queue worker are running; these are queued. Check `REVERB_*` values match on server and client. |
| `Pusher error: No matching application for ID` in failed jobs | Another project's Reverb is using the same port. Give this project its own `REVERB_PORT` / `REVERB_SERVER_PORT` (default 8090). |
| Tickets stay unassigned in FIFO Queues | Use *Why?* on the ticket — typical causes: no user *Available*, no active shift, on leave, workload limit, missing queue permission or department. |
| Scheduled e-mails not sent | Template must be active with recipients; scheduler and queue worker must run; check *Notification Log*. |
| Changes to `.env` ignored | Run `php artisan optimize:clear` (config may be cached). |
| Custom styles missing | Run `php artisan filament:assets` (Filament's own assets); OMS styles live in `public/css/oms.css`. |
