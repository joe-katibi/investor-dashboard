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
8. [Outage lifecycle](#outage-lifecycle) · [4G NOC module](#4g-noc-module) · [Customer Care: tickets and site visits](#customer-care-tickets-and-site-visits)
9. [FIFO assignment engine](#fifo-assignment-engine)
10. [SLA management](#sla-management)
11. [Notifications](#notifications)
12. [Reports and exports](#reports-and-exports)
13. [Scheduled e-mails and templates](#scheduled-e-mails-and-templates)
14. [Real-time updates (Reverb)](#real-time-updates-reverb) · [Mobile app API](#mobile-app-api)
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

The page looks for new work by itself every **40 seconds** — also while its browser tab is not in front — and
when a ticket has been assigned since the last look it says so (*New ticket for you: …*) and puts the number of
tickets in the tab's title, `(2) My Work`. It does not depend on Reverb.

### Reports
17 reports with filters, on-screen view and **CSV / Excel (.xlsx) / PDF** export — see
[Reports and exports](#reports-and-exports). The *Report* picker can be searched: type part of a report's name.

**Availability Time** (Operations, under My Work; also on Reports as *People: Availability Time*): how long each
person was Available, Busy, On break and Offline — day by day, week by week or month by month — in hours:minutes,
with the share of time available. Counted from every change of availability (website, app or a manager), up to now;
time before a person's first recorded change is shown as *Not recorded*. Each person sees their own time; whoever may
read reports sees everyone's and can export it.

An export never keeps the page waiting: pressing *Export* only queues it, a queue worker writes the file
(`GenerateReportExportJob`), and it is collected from **Reporting → Report Downloads** — a list with the date
range, the report, who pulled it, when, and a *Download* button. A file is kept for **6 hours**, then the file and
its row are removed (`oms:prune-report-exports`). People see their own exports; whoever reads the audit trail sees
everyone's and may download a report they could run themselves. Requesting and downloading are both in the audit
trail. A queue worker must be running (`php artisan queue:work`), locally too, or exports stay *Waiting to be prepared*.
Exports share the queue with everything else unless `OMS_EXPORTS_QUEUE` says otherwise: behind a long backlog
(a worker that was off for days) they wait their turn.

### Looking at photos
A photo is looked at the same way everywhere on the website: **View** (or a click on the picture) opens it large in a
pop-up in the middle of the page, with what is known about it underneath and a **Download** button — never in
another tab, and the page behind stays where it was (Esc, the Close button or a click beside the photo closes it).
One component does it (`App\Filament\Support\PhotoViewer`, `resources/views/filament/partials/photo-viewer.blade.php`):
*Photos & files* of customer tickets and site visits, the attachments and vendor evidence of 4G tickets, and the site
and maintenance photos on an outage. Files (photos, the CRF PDF, report exports) are excluded from the panel's
single-page navigation (`spaUrlExceptions`), so a download is a download and not a page of raw data.

### Photos a visit is completed with
Which photos a site visit needs depends on its **visit type** (Customer Care → Visit Types → *Photos to complete*;
`App\Support\VisitPhotos`):

* **Standard** (Support, Wi-Fi Extender): the geolocation photo and the speed-test photo.
* **Installation set** (Installation, Shifting): nine photos, all required — (1) the outside of the FAT serving the
  connection, **geotagged**; (2) inside the FAT after splicing, showing the terminations; (3) the power at the FAT
  port; (4) the cable entry towards the premises, **geotagged**; (5) the ATB and ONU mounted in the house; (6) the
  power at the ATB; (7) the ONU's serial number; (8) the speed tests, wired at the ONU then Wi-Fi in the room of use
  (one or more photos); (9) every smart TV / streaming device working.

The installation set is taken **in the mobile app only, with the camera** — no gallery, and no upload for these
photos on the website. The app offers *Installation photos (n of 9)* so they are taken as the work goes (at the FAT,
along the cable, in the house); *Complete visit* then asks only for the ones still missing and is refused, each
missing photo named, until all are there. A photo taken again replaces the earlier one (which stays on the visit as
evidence). The two geotagged photos use the geolocation feature below and appear in the *Geolocation Photos* report.
On the website the visit shows them under **Installation photos** (click to view) and under *Photos & files*.

### Geolocation photo
The **geolocation photo** of a site visit (mobile app, *Complete visit*) is taken at the phone's position. The app
stamps the picture like a GPS camera does — a small map with a pin, the place, the address, latitude and longitude
(with how exact the fix is), the day, time and time zone — and the same details are

* **saved with the photo** (`outage_attachments`: `latitude`, `longitude`, `accuracy_m`, `place`, `taken_at`,
  `location_source`), shown under *Photos & files* on the visit with a link to the spot on a map;
* **written into the photo file itself** (EXIF: GPS position, accuracy, date/time, description), so they can be read
  back from the file wherever it ends up. A geolocation photo that arrives without figures — added on the website,
  taken with another GPS camera app — is read for them (`App\Services\Geo\PhotoLocation`).

**Report — *Tickets: Geolocation Photos*** (Reporting → Reports): one row per geolocation photo of the period with the
customer's account number, the ticket number, latitude, longitude, the date the photo was taken (or added), the
place, the accuracy, who took it and the link to the photo. On screen the link is *View* (the pop-up) and *Download*;
in the Excel / CSV export it is the address of the photo, which asks the person opening it to sign in and to be
allowed to see the ticket.

The place name and the map are looked up by **this server** at OpenStreetMap (`GET /api/v1/geo/place`,
`App\Services\Geo\PlaceLookup`): only a latitude and a longitude are sent, answers are kept (30 days), and a phone
never talks to a map service itself. Two switches under **Customer Care → Ticket Settings**: *the geolocation photo
must carry the technician's position* (off: accepted when the phone cannot find where it is) and *add the place name
and a small map* (off: coordinates and time only). The server needs outbound HTTPS to
`nominatim.openstreetmap.org` and `tile.openstreetmap.org` for the lookup; without it the photo still carries the
coordinates and the time.

### Administration
Verticals, affected services, major outage types, outage types, statuses, impact / priority rules, SLA policies,
FIFO queues, FIFO & SLA settings, notification rules, e-mail templates & schedules, OLTs / FDTs / Slots / Ports
(with team coverage), departments, teams & sub-teams, users (skills, shifts, leave, availability history), roles &
permissions, notification log (with retry) and audit trail.

**Users at a glance.** Above the user list (*People → Users*) five figures: **total users**, **active**,
**inactive**, **signed in on the website** and **signed in on the mobile app** — each of the last two with how many
did so in the last 7 days, and for the app how many are signed in on a phone right now. The website and the app
keep their own "last signed in" time (columns *Last on the website* / *Last on the mobile app*, hidden by default);
*Last login* stays the latest of the two. Sign-ins from before this was added were split as well as they could be
known: an app sign-in still on file counts for the app, the rest for the website.

**The mobile app in the audit trail.** Everything done from the app is in *Monitoring → Audit Trail*, marked
**Mobile app** in the *Done from* column (filter *Done from*: Mobile app / Website / System):

| Entry | When |
|---|---|
| Mobile app: Signed in / Signed out | with the phone's name and the app version |
| Mobile app: Sign-in failed | a wrong password on a real account, or an inactive account (the password tried is never recorded) |
| Mobile app: Action on a ticket | every action run from the app — the ticket, the action, the resulting status, the phone |
| Mobile app: Raised from the app | corrective / preventive maintenance raised in the app |

**What the audit trail records.** Every entry carries who, when, what changed (before / after), where it was done
from (website, mobile app or the system) and the address of the computer.

| What | How it appears |
|---|---|
| Every create, change and deletion of tickets, users, customers, settings, queues, types, statuses, SLA policies, notification rules, templates, teams, topology, workflows… | *Created / Updated / Deleted* with the values before and after |
| Each step of a ticket | named entries per module (*4G: …*, *Ticket: …*, *Workflow: …*) plus the status, assignment and SLA histories on the ticket |
| Signing in and out on the website, failed attempts (also on an address nobody has), too many attempts | *Website: Signed in / Signed out / Sign-in failed / Too many sign-in attempts* |
| The same in the mobile app | *Mobile app: Signed in / Signed out / Sign-in failed* |
| A password changed (by the person, an administrator or the reset link) | *Password changed*, *Password reset by e-mail link* — never the password, nor its hash |
| A person given or losing a role or a team (user screen and staff import) | *Roles / Teams of a person changed*: added, removed, what they have now |
| A permission given to or taken from a role | *Permissions of a role changed*: added, removed |
| A report or the outage list exported | *Report exported*: which report, format and period |
| A customer import from the website | *Customers imported* / *Customer import previewed* with the outcome |
| A ticket opened — its page on the website, or the ticket in the mobile app | *Ticket opened*: who, which ticket, from where. At most one entry per person and ticket every 15 minutes, so reloading a page is not a new visit. Switch: *FIFO & SLA Settings → Audit trail → Record who opens a ticket* |
| Notifications sent (in-app, e-mail, SMS, push) | *Monitoring → Notification Log*, with delivery status and retries |

Not recorded: opening a list, a dashboard or a report on screen (only tickets are followed), and what a browser
shows as a desktop notification (the notification itself is in the Notification Log). Entries are append-only: the application never changes or
deletes them.

The changes an action makes (status, assignee, photos…) are recorded as before, and they too carry *Mobile app* —
as do the status history and the FIFO assignment history of the ticket, so it is always visible whether a step was
taken on the website or on a phone.

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

### Site photos (Emergency and Regular outages)

An Emergency or Regular outage is **reported restored with three photos**, in this order:

| Photo | Shows |
|---|---|
| **Findings on ground** | the fault as the team found it, before any work |
| **After restoration** | the finished work, once the service is back |
| **Power readings** | the power meter with the reading after restoration |

* **Required.** *Report restored* is refused — on the website and in the app — until all three are on the
  ticket; the message names the ones missing. A refused restoration saves nothing.
* **…or optional.** *FIFO & SLA Settings → Field photos* has two switches: *Emergency / Regular outages: the
  three site photos are mandatory* and *Preventive / corrective maintenance: photos are mandatory* (both on by
  default). Switched off, the photos are still offered — on the website and, at once, in the mobile app, where
  the fields stop being required — but nothing waits for them. The photos of a site visit have their own switch
  under *Ticket Settings & Alerts → Site visits*, and per visit type.
* **As the work goes.** *Site photos (n of 3)* adds any of them while the ticket is with the field team, so the
  findings are on the ticket before the repair starts; *Report restored* then only asks for those still missing.
  A photo added again replaces the earlier one.
* **Website:** chosen from the computer. **Mobile app:** only the camera — nothing from the gallery or anywhere
  else — and the date and time the photo was taken are stamped on the picture and stored with it
  (`outage_photos.taken_at`, `source` = `camera` / `upload`).
* **Which outages:** the types with *Site photos required* (Configuration → Outage Types; on for Regular and
  Emergency), when the major outage type sends the outage to Infrastructure — an outage restored remotely by the
  NOC has nothing to photograph. Core, system, planned and 4G tickets are unaffected.
* The photos are shown on the outage page (*Site photos*), each with who took it and when, are private like every
  other attachment, and each addition is on the timeline.

Preventive and corrective maintenance keep their own two "after" photos (outside with the label, inside); in the
mobile app those too can only be taken with the camera and carry the time stamp.

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

#### Routed by the affected system

The person raising a system outage picks the system under **Affected services**. A service can name the team that
reviews its outages (*Configuration → Affected Services → "System outages reviewed by"*):

| Affected system | Reviewed by |
|---|---|
| Billing (Savbill), Grafana | **CRM Managers** team (role *CRM Manager*) |
| CPaaS | **CPaaS Manager** team (role *CPaaS Manager*) |
| Sav-Money, Customer App, Mail, and any system without a team | the IT desk, as before |

* The ticket follows the same steps (review → resolve with feedback, or escalate to NOC → NOC returns it →
  confirm); only *who* gets it changes. FIFO offers it to the members of the team, whatever their department,
  and the confirmation after NOC's fix goes back to the same team — never to the general IT desk.
* If nobody of the team is available it **waits for them**; it is not handed to IT. With several affected
  systems, the first one that has a team decides.
* Team members need the permissions of the role (*Work the IT queue*, *IT review*): give the person the role
  **and** put them in the team (Users / Teams).
* Changing the routing is a setting, not code: pick another team on the service, or clear it to send that system
  back to IT. Add a new system as a new affected service.
* On a system in use, create the systems, the two teams and the two roles once (it only adds what is missing):

  ```bash
  php artisan db:seed --class=SystemRoutingSeeder --force
  ```

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
  4G NOC creates → NEW → 4G FIFO QUEUE → next available person who handles it (or one the NOC picks) → ASSIGNED
        Transmission outage → a 4G ENGINEER          eNodeB outage → a 4G TECHNICIAN
     └─ rejected / not accepted in time / person unavailable → OPEN, back in the queue → the next one → ASSIGNED
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
* **Engineers work transmission, technicians work eNodeB.** A *Transmission Outage* is only offered to people with
  the permission *Receive transmission outages* (role **4G Engineer**), an *eNodeB Outage* only to people with
  *Receive eNodeB outages* (role **4G Technician**). FIFO never crosses over — with no technician available an
  eNodeB ticket waits in the queue rather than going to an engineer — and the NOC's *Assign / Reassign* list (and
  the engineer / technician field on the form) only shows the people who handle that kind of outage. Someone who
  should work both gets both permissions under *People → Roles* (or on the user); *4G NOC → Engineers /
  Technicians* shows what each person handles, and *FIFO Queues → Why?* shows the rule as "Handles … outages".
  A 4G outage type of your own with another fault domain is open to both. On a running system the migration
  `split_mobility_ticket_handlers` grants the two permissions to the existing roles (a role of your own that
  already received 4G tickets keeps receiving both).
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
  addresses, with `{{ticket_number}}`, `{{site_id}}`, `{{site_name}}`, `{{tower_co_id}}` (the tower company's own
  reference for the site, from the eNodeB record — what the vendor's engineer looks the site up by),
  `{{location}}`, `{{outage_type}}`,
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
* **Roles** — `4G NOC Manager`, `4G NOC`, `4G Engineer` (transmission outages), `4G Technician` (eNodeB outages)
  (permissions `mobility.*`); put 4G staff in
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

## Customer Care: tickets and site visits

Customer tickets and site visits live under **Customer Care**. They are `Outage` rows with their own workflow
(`cx_ticket` / `site_visit`), statuses, screens and alert lists, plus a `tickets` detail row (customer, issue, desk,
visit, evidence). So the **FIFO engine, SLA engine, notification rules, timeline, attachments, status history,
reopening history, audit trail, reports and scheduled e-mails are the same ones the outages use** — they never show
on the outage screens, dashboards or reports, and outage rules / alert lists never fire for them.

**Customer database.** *Customer Care → Customers* holds every account (account number = ONU name, customer name =
alias name) with its OLT → FDT → Slot → Port, bandwidth, phones and region. *Import customers* takes an .xlsx / .csv
with the header `Alias Name | ONU Name | OLT | FDT | Slot | Port | Bandwidth` (optional `Phone | Alt Phone |
Alt Phone 2 | Address | Landmark | Region | Email`); existing OLTs / FDTs / slots / ports are reused, only new ones are
created, nothing is removed, and a preview mode saves nothing. When an agent raises a ticket the account number is
looked up: found, the form fills in the customer; **not found, the agent types the details and a provisional customer
is created — the next import completes it and links the earlier tickets** (position and region).

**Finding a customer's tickets.** The search box at the top of every page finds customer tickets and site visits by
ticket number, **account number**, customer name, calling / primary number or billing reference, and shows the
account and customer under each result. On the *Customers* list the **Tickets** count (and the *Tickets* action of
the row) opens that customer's tickets and visits, newest first — what was reported, status, desk and person it is
with, raised / closed, final reason, work done — each with a link to the full ticket page.

**Attachments when raising.** *Raise customer ticket* and *Book site visit* take files (images, PDF, Office
documents, saved e-mails — up to 8, 15 MB each): they are on the ticket from the first desk and named on its
timeline. More are added later with *Attach files*.

**Final reasons by desk.** *Configuration → Final Reasons → Offered at these desks*: tick Billing and Billing
Manager for the billing reasons, NOC for the NOC ones, the site-visit desks for the technician's. A person
closing a ticket — on the website or in the app — is offered the reasons of the desk the ticket is at, plus those
ticked for no desk (offered everywhere, which is how existing reasons start). The workflow refuses a reason of
another desk.

*Importing a full customer list* (a hundred thousand rows and more):

* **In the background.** *Import customers* answers at once and the outcome arrives as a notification (the bell) —
  a few seconds for a small file, a minute or two for a full list (143,000 rows: about a minute and a half,
  ~160 MB). One import at a time. The work runs in the web server's PHP process after the page has answered, so it
  needs no queue worker; restarting PHP-FPM in the middle stops it (nothing is half-saved: the import is one
  transaction).
* **From the command line** for a file the site will not take (upload limits), or to keep a record of the run:

  ```bash
  php artisan oms:import-customers /path/to/customers.xlsx --preview
  php artisan oms:import-customers /path/to/customers.xlsx --keep-incomplete
  ```

* **Rows that stop an import** — an empty or repeated account number, a slot or port that is not a number, an OLT
  or FDT name over 120 characters. *Nothing* is imported; every such row is counted and the first 200 are listed.
* **Rows with an incomplete position** — a slot without an FDT, a port without a slot, an FDT without an OLT.
  By default they stop the import too; with *Import those customers with the part that is complete*
  (`--keep-incomplete`) the customer is saved with the OLT (or the OLT and FDT) the file does give, nothing is
  invented, and the report says how many there were.
* **Over-long descriptive values** (a name over 190 characters, as a rule notes typed into it) are cut to fit and
  counted, never a reason to stop.
* **Uploading**: the site takes what PHP and nginx allow (`upload_max_filesize`, `post_max_size`,
  `client_max_body_size`) and Livewire's own 12 MB; a .csv of the same list is a fraction of the .xlsx and reads
  several times faster.
* Large workbooks are read with their text table held in memory (`App\Support\InMemorySharedStrings`): the
  spreadsheet library's default — temporary files of 10,000 texts, reloaded for almost every cell of a customer
  list — took over ten minutes for 143,000 rows.

**Desks.** A ticket is always at one desk (a FIFO queue): CX Tier II, Billing, Billing Manager, Commercial / Sales
Admin, CX Manager, CX Frontline Follow-up, NOC, Site Visit Dispatch, Site Visit Technician, Infrastructure Support
Dispatch / Technician, Design, Access, CX Field Support — plus the parallel *Pending Ticket Follow-up* queue. Each desk
has its own permission (`queue.work_<desk>`); the next available person holding it gets the ticket (FIFO, priority
first, oldest first) and only that person — or an override holder — can act on it.

**Routing (all configurable).** *Subcategories & Routing* sets, per issue reported: the first desk, the desk that
approves (refunds, credits), the desk that carries an approved request out, whether a site visit can be raised (and its
default visit type), whether to check for an ongoing outage, priority and SLA target. *Desk Routes* sets which desk may
hand a ticket to which other desk; "return to the previous desk" needs no route. Seeded flows:

| Issue | Flow |
|---|---|
| Billing | CX raises → **Billing** closes, or escalates to the **Billing Manager** (approve / decline → back to the agent), or sends to **CX Frontline Follow-up** to call the client → back to Billing to close |
| Refund | CX raises → **Commercial** assesses → approved: **Billing** pays and closes (or closes "refund not done – customer withdrew"); declined: CX calls the customer, closes as *Declined* |
| Troubleshooting | CX raises → **CX Tier II** vets / troubleshoots → resolved: calls the client and closes; otherwise **NOC** → back to Tier II; or Tier II raises a **site visit** (the ticket waits and closes with the visit) |
| Credit | CX raises → **CX Tier II** vets usage → **CX Manager** approves → **Billing** applies the credit; declined → Tier II advises the customer |

**Site visits.** Visit types number their own visits — **INS-1, WiE-1, SUP-1, SHI-1** (a locked counter per prefix,
never `MAX(id)+1`). Dispatch → technician (named, or the technician FIFO queue, optionally one team; a visit booked for a
later day is only offered on that day) → **the customer is texted** on the calling, primary and alternative numbers
(technician name and number, ticket number, "will visit today") → *En route* → *Start at customer premise* (timer) →
*Pause* (mandatory reason) / *Resume* → *Complete* with the final reason, **FAT number, geolocation photo and
speed-test photo** → back to the dispatcher (*Verify & close*, or *Not done — send back*) → **CX Field Support** calls
the customer (*confirms* → Successfully Closed / Closed Escalated; *not resolved* → reopened to dispatch). A technician
who cannot complete sends it to the dispatcher, who escalates to **Infrastructure Support** (dispatcher → technician →
back up the chain), **Design**, **NOC** or the **Access** team; or to **CX Field Support** (unreachable, reschedule,
call back), who can **reschedule** to the customer's date and time — the customer gets an SMS — and the visit returns to
dispatch. Statuses: Visit Open, Pending-Scheduled, Pending Without Team, Visit In Progress, Pending Escalated, Pending
Dispatcher Verification, Pending Customer Confirmation, Pending Customer (call-back / reschedule), Successfully
Closed, Closed Escalated, Cancelled, Declined, Duplicate.

**Shifting / Relocation technicians.** A visit type can be kept for some technicians (*Visit Types → Technicians*).
Shifting is, by default: its visits are offered — by name on *Assign technician* (website and app), through the
technician FIFO queue and on a manager's override — only to technicians with *Technician: Shifting / Relocation
visits* (`visit.work_shifting`; role *Shifting / Relocation Technician*, or add the permission to a person or role).
With none of them on duty a shifting visit waits in the queue. Installation, Wi-Fi Extender and Support visits go to
every site visit technician.

**Relocation (Shifting) visits.** Visit types marked *Relocation* (Shifting, by default):

* **NOC provisioning before the speed tests.** On site, the technician takes the photos up to the ONU serial number,
  then *Send to NOC for provisioning* (website or app) — the visit goes to the FTTH NOC. The speed tests and the
  smart-device photo cannot be taken, and the visit cannot be completed, until the NOC presses *Provisioning done —
  back to the technician*: the visit returns to the same technician (the customer is not texted again), who takes
  them and completes the visit. From there the usual flow: the dispatcher verifies and closes, CX Field Support
  confirms with the customer. A NOC that cannot provision uses *Return to previous desk*: the visit goes to the
  dispatcher. The NOC step is not an escalation (the visit can still close as *Successfully Closed*).
* **Another OLT / region.** *Relocate to another OLT / region* (dispatcher, before the work is completed): the new OLT,
  FDT, region, address, road and landmark. The visit keeps the new location and goes to a dispatcher of the new area
  — named, or the next available one of that region (FIFO) — who assigns a team there.

**Ongoing outages.** For LOS-type issues the customer's OLT / FDT / slot / port is checked against every open FTTH
outage — **also those not yet confirmed by NOC**. The agent sees it on the form; the ticket is linked to the outage and
gets the delay reason "Affected by an outage". *Link to outage* does the same by hand.

**Regions.** *Regions* groups OLTs; customers and tickets take the region of their OLT. People locked to regions
(on the region) only receive tickets of those regions from FIFO; people locked to none receive all.

**SLA.** Counted on a **business clock**: 07:00–18:00, every day by default; a ticket raised after 18:00 starts counting
at 07:00 the next working day (*Ticket Settings*: hours, working days, or wall clock). Target: subcategory → visit
type (Support 3 h, Shifting 72 h) → an SLA policy set for the *Customer Ticket* / *Site Visit* type → default (3 h).
"At risk" at 67 %. A ticket closed past its SLA needs a **reason for delay** (D01–D14, each flagged *within our control*
or not). **Team leader intervention** takes a ticket over with its own timer; who closed it is recorded.

**Follow-up and credit.** A ticket pending past **9 SLA hours** (configurable) enters the *Pending Ticket Follow-up*
FIFO queue so the follow-up team calls the customer, and is marked **ready for credit**; the credit roles / addresses
get one notification and one e-mail listing the new ones, with the list attached (`oms:ticket-tick`, every 5 minutes).

**Reports** (Reports page, "Tickets: …"): customer ticket and site visit registers (the tracker columns), pending tickets
& SLA, **TAT / SLA brackets** (3, 6, 9, 12, 15, 18, 21, 24, 48, 72, above — average, median, P90, P95, met / breach %,
hours lost, by kind, visit type, issue, department, region, OLT, technician, ticket type), escalations by department
(turnaround), agent / technician performance (time to accept, handling time, closed, breached, calls), reasons for
delay (preventable hours), customer follow-ups, ready for credit, reopened & repeat work, assignment fairness, ticket
audit trail. **Scheduled e-mails** (inactive until recipients are set, times editable): *Pending / SLA update* every
3 hours 07:00–19:00 (SLA per department, OLT and region, issues per OLT, tickets in follow-up, closed, pending list
attached), *Daily report* 07:00 / 12:00 / 19:00, *Weekly* Monday 07:00, *Monthly* the 1st at 07:00 (movement,
closed within SLA, pending by SLA, inflow vs closed and SLA % charts, regions, reasons for pending, trend). Charts
are built from tables, so they show in every mail client.

**Roles** — new: Ticketing Manager, CX Frontline, CX Retention, CX Follow-up Agent, CX Field Support, Billing Agent,
Billing Manager, Commercial / Sales Admin, Site Visit Dispatcher, Site Visit Technician, Infra Support Dispatcher,
Infra Support Technician, Design Engineer, Access Officer. Existing CX, NOC, Dispatch and technician roles get their
desks. To receive tickets a person needs the desk permission, *Available* status and an active shift (FIFO rules).

### Verticals: FTTH and 4G tickets

Every customer ticket is of one **vertical** — *FTTH*, *4G*, or one added later under *Configuration → Verticals*.
*Raise customer ticket* asks for it first (FTTH by default; a customer on file brings their own) and then offers only
that vertical's **categories and subcategories**. **Customers, Ticket Categories, Final Reasons and Reasons for Delay**
each carry a vertical (a column, a filter and a field on their screens): a ticket is offered, and the workflow accepts,
only the reasons of its vertical. Everything that existed before verticals is FTTH; a starter 4G set is seeded
(categories 4G Connectivity, Router / Device, Service & Account, Billing, General — all editable). Site visits are FTTH.

**The 4G flow** (desks *4G NOC (customer tickets)*, *IP Team*, *RF Team*, *Core Team*; roles *4G IP Team*, *4G RF Team*,
*4G Core Team*; the 4G NOC roles get the 4G NOC desk):

| Step | Who | Then |
|---|---|---|
| Raised | CX agent | → **CX Tier II** |
| Troubleshoot | CX Tier II | resolved: calls the customer, confirms uptime, closes · otherwise → **4G NOC** |
| Diagnose | 4G NOC | → **IP Team**, **RF Team** or **Core Team** (or back to Tier II) |
| Fix | the team | fixes it, or *Escalate to vendor* (a vendor of its type: IP / RF / Core, under *4G NOC → Vendors*). The vendor is e-mailed from the vendor templates, reminded when silent (60 min), told when the ticket closes. *Record vendor feedback* writes down what the vendor said. When up, the team sends the ticket to the 4G NOC with the reason |
| Validate | 4G NOC | not resolved: *Return* to the team · resolved: → **CX Tier II** |
| Confirm | CX Tier II | calls the customer and closes with the final reason |

An FTTH ticket never reaches a 4G desk and a 4G ticket never reaches the FTTH NOC or a site visit (desk routes are
filtered by the ticket's vertical). **Updates every 20 minutes:** an update on a 4G ticket reaches everyone involved
(who raised it, everyone who has held it); when nothing has been posted for 20 minutes the holder — or the desk while
nobody holds it — is reminded, again after every further 20 minutes (`oms:ticket-progress`, every minute; both times
under *Workflow configuration*). **4G NOC → 4G Tickets** lists them: Open, Assigned to me, Raised by me, CX Tier II,
NOC, IP Team, RF Team, Core Team, Customer call-back, SLA at risk / breached, Closed, All.

**4G ticket reports and e-mails.** On *Reports* (for the 4G NOC, its teams and CX Tier II), each exportable as
CSV / Excel / PDF:

| Report | What it shows |
|---|---|
| 4G Tickets: Summary by Department & Issue | per department (in the order of the flow), issue and category: Total, Open (waiting), In progress, With vendor, Customer call-back, Closed, SLA breached |
| 4G Tickets: Register (Open & Closed) | every ticket: customer, issue, state, department now / closed at, with whom, vendor, SLA hours and status, final reason |
| 4G Tickets: Pending — With Whom | every open ticket by department and person (or vendor, or nobody yet), hours at the desk, SLA |
| 4G Tickets: SLA Breaches — By Whom | each breach with the department and person holding the ticket — the ticket's SLA and each desk's own target |
| 4G Tickets: Vendors | per vendor: tickets called in, waiting for the vendor now, feedback received, hours to feedback |

A period covers the tickets raised in it plus every ticket still open. E-mails (*Email Templates*, created inactive —
set the recipients and switch them on): **4G Tickets – 2-Hour Update** (07:00–19:00 every 2 hours), **Daily Report**
(07:00 and 19:00, last 12 hours), **Weekly Summary** (Monday 07:00, last week against the week before) and
**Monthly Summary** (the 1st at 06:00, last month against the month before) — headline figures, comparison, per
department, pending with whom, breaches by whom, vendors and issues, with the register attached.

**Adding the module to a production system that already holds data:**

```bash
php artisan migrate --force
php artisan db:seed --class=TicketingSeeder --force
```

`TicketingSeeder` only **creates what is missing**: the ticketing permissions and roles, the Billing / Commercial /
Design / Access departments, the *Customer Ticket* major type with the Customer Ticket and Site Visit types and
statuses, one queue per desk, visit types, categories / subcategories with routing, reasons for delay, final reasons,
desk routes and the four ticket e-mails. Existing records are never changed — except that, the first time, the
existing CX / NOC / Dispatch / technician roles get their ticketing permissions. Then add regions, import customers
and set the recipients of the ticket e-mails.

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

## Workflow configuration

*Configuration → Workflow Configuration* (permission `workflow.configure`; on an installation in use it was given to
the roles that hold `config.admin`). One tab per flow:

| Flow | Steps shown |
|---|---|
| Network outage | Reported → NOC review → Dispatch → Field work (+ Support technician) → NOC restore confirmation; Customer call-back after a reject |
| System outage | Reported → Review (IT or the system's team) → NOC when escalated → Confirmation |
| Planned maintenance | Requested → CRF and sign-off → Dispatch → Field work → NOC confirmation → Customers told |
| Preventive / corrective maintenance | Raised on site → the two approvals (preventive) → Field work → NOC confirmation |
| 4G outage (own engineers) · 4G outage (vendor) | Reported → Engineer / technician or the vendor → 4G NOC verification |
| Customer ticket | Raised → the desks (CX frontline, Tier II, Billing, Billing Manager, Commercial, CX Manager, NOC) + pending follow-up |
| Site visit | Booked → Dispatch → Technician → Confirmation with the customer; the escalation desks |

For every step the page shows **who works it** (the roles holding the step's permission and how many active
people that is), **how tickets are given out**, the **time to accept**, the **desk SLA target** and the
**notification events** of the step with the number of rules on each.

**What can be changed from the screen, without code:**

* **Per step — "Change"**: automatic FIFO or manual assignment, narrow it to a department and / or a team, the time
  to accept, after how many rejections / time-outs it is escalated, and the desk SLA target.
* **Per flow — "Change switches"**: the switches that add or drop a step or change how it behaves — for example
  *NOC confirms before it goes on* and *worked by a field team* per major outage type, *reviewed by IT first* for
  system outages, *on the field within N minutes*, photos mandatory or not, *CX Field Support confirms with the
  customer before a visit closes*, visits per technician per day, vendor chase and escalation times.
* Links to the rest of a flow's configuration: roles (who), notification rules (who is told), desk routes and
  issues (customer tickets and visits), affected services (system outages), FIFO queues.

**Past tickets are not touched.** A change applies to tickets that reach a step from then on: a ticket already
at a step keeps the person it was given to and the SLA target it arrived with, and nothing in its history is
rewritten. *Latest changes* at the bottom lists who changed what and when (the same entries as the audit trail).

That is **stage 1**: the built-in flows made adjustable. **Stage 2** adds workflows built from scratch:

### Custom workflows (stage 2)

*Configuration → Custom Workflows* (permission `workflow.configure`): build a flow of your own — a purchase
request, a vehicle request, an access request, an internal approval — without code.

* **The flow**: a name, a **ticket prefix** (`PUR` → `PUR-000001`), a description, the SLA of the whole ticket, the
  roles that may raise a ticket in it (empty = everyone who may raise custom tickets), whether a closing note is
  required, and active / switched off.
* **The steps, in order**: add, remove and **drag to reorder**. Each step has a name, the **team** that works it,
  automatic FIFO or manual assignment, its own SLA target, whether it may send the ticket back to the step before,
  whether a note is required to complete it, and instructions for the person doing it.
* **Who works a step**: the members of the step's team (and its sub-teams) — no extra permission. The oldest
  ticket goes to the team member waiting longest, with the same rules as every queue (availability, shift, leave,
  workload). A *manual* step waits until a manager assigns it.

*Operations → Workflow Tickets*: **Raise ticket** (choose the workflow, title, details, priority), then on the
ticket page the person holding it uses **Complete step** — the ticket moves to the next step's team — or **Send
back** with a reason. The last step is **Complete ticket** with the closing note. Managers can **Assign** within
the step's team and **Cancel**. The page shows the steps as chips (done / here now / to come) and every stay at a
step: team, person, from, until, time taken, the step's target and whether it was kept.

| Permission | Meaning |
|---|---|
| `workflow.configure` | build and edit custom workflows |
| `custom.raise` | raise tickets in custom workflows (a flow can narrow this to roles) |
| `custom.view` | see every custom ticket — without it a person sees the tickets they raised, hold or held, and the ones waiting for their team |
| `custom.cancel` | cancel custom tickets (the person who raised one may cancel it while nobody holds it) |

When these arrived, `custom.view` and `custom.cancel` were given to the roles that may override FIFO (managers,
team leaders) and all three to the roles holding `workflow.configure`. **Give `custom.raise` to the roles that
should raise tickets** — nobody else has it at first.

**Editing a flow never touches tickets already on their way.** A ticket carries a copy of the steps as they were
when it was raised (and the version number of the flow), and follows that copy to the end — reorder, rename,
remove or add steps, change teams or switch the flow off at any time. Changes apply to tickets raised afterwards.
Every change is a new version and is on the audit trail.

Custom tickets take part in everything else: **My Work** (under the step's name), the **FIFO Queues** screen
(tab *Custom Workflow Step*), **SLA per desk** (a step's target is warned and reported like a desk's), the
**audit trail** (*Workflow: Raised / Step completed / Sent back / Completed / Cancelled*), the reports *Ticket
Life Cycle*, *SLA: Desk Response Times* and *Staff Productivity* (module *Custom workflows*), and the **mobile
app** — whoever holds a ticket can accept it, complete the step or send it back from the phone.

**What stage 2 does not do.** The order of the steps of the **built-in** flows (network outage, system outage,
maintenance, 4G, customer tickets, site visits) stays as it is: their steps carry rules of their own — photos, the
CRF signatures, the two approvals, vendor e-mails — that a free reordering would break. Those flows are adjusted
with the step settings and switches above. Custom tickets have a title, details and a priority; extra form fields
per flow and attachments are not part of this version.

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

### SLA per desk (escalation points)

Next to the SLA of the whole ticket, every **desk** has its own time to act on a ticket that reaches it — to
confirm, reject, dispatch, return, escalate or close it. It is counted from the moment the ticket arrives at the
desk until the desk moves it on.

| Desk | Built-in target |
|------|-----------------|
| NOC — review, restore confirmation, customer tickets escalated to NOC, 4G NOC verification | 20 minutes |
| CX Tier II — customer tickets, and the call-back after a NOC reject | 15 minutes |
| CX frontline (Tier I / CX agents) — customer call-backs | 5 minutes |
| Every other desk (Billing, dispatch, technicians, Design, Access …) | none until one is set |
| **Outage on the field**: reported → dispatched to a field technician | 30 minutes |

* **Where to change it**: Configuration → FIFO & SLA Settings → *SLA per desk*. One box per desk (minutes,
  `0` = no target), the "on the field within" time, the share of the target after which the warning goes out
  (75 % by default: a desk with 20 minutes is warned after 15), the stakeholder roles and extra e-mail addresses.
* **A change never rewrites history**: the target in force when a ticket arrives at a desk stays with that stay.
  New targets apply to tickets arriving from then on.
* **Clock**: customer tickets and site visits count working hours (Ticket Settings: 07:00–18:00 by default), so a
  ticket reaching Billing at 19:10 with a 30-minute target is due at 07:30. Outages and 4G tickets count every
  minute. A site visit booked for a later day starts counting on that day.
* **Who is told** (`oms:sla-check`, every minute; each notice once): the person holding the ticket, their team
  leaders and the stakeholder roles — each person only about the kinds of ticket they may view. The warning is
  shown in the system; a breach is also e-mailed. Both are events of the notification rules and the alert lists
  ("Desk SLA: Target Almost Up", "Desk SLA: Target Breached at a Desk", "Field: Not Yet Dispatched (warning)",
  "Field: Not Dispatched Within the Target"), so more people, e-mail addresses or SMS numbers can be added there.
* **On screen**: My Work shows "desk target: 4 min left / over by 3 min" under the step, and the FIFO Queues
  screen shows it under the queue age.
* **The ticket's own SLA**: when it runs out, the breach is recorded against the desk the ticket was at and the
  person holding it, and the notice says so.
* **Reports** (Reports page, for the modules you may view):
  *SLA: Desk Response Times* — per desk: tickets received, handled, within target, breached, compliance %,
  average and longest time at the desk, and how many are waiting past the target right now.
  *SLA: Breaches by Desk (escalation points)* — every breach with its desk, who had it, the target, the time
  taken and by how much it was missed: a desk's own target, the ticket SLA running out at a desk, and outages
  not on the field in time.

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

**Phone numbers** are stored in international (E.164) format; local numbers such as `0772 123 456` become
`+256772123456` using `SMS_DEFAULT_COUNTRY_CODE`. Invalid numbers are rejected on the form and skipped when sending.
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
| **Staff Productivity (all modules)** | Per person, module and hour / day / week / month (see below) |
| **SLA: Desk Response Times** | Per desk: received, handled, within target, breached, compliance %, average / longest time at the desk |
| **SLA: Breaches by Desk (escalation points)** | Every breach with the desk it happened at — a desk's target, the ticket SLA, time to field |
| **Ticket Life Cycle (every step, all modules)** | One line per step of every ticket: raised, each desk (who, how long, against its target), closed |
| **OLT Performance: SLA per Technician & Dispatcher** | Per OLT, technician and dispatcher: tickets, SLA met / breached, time to dispatch and to restore |
| **Reason for Outage (RFO) Report** | Closed outages grouped by final reason: count, share, customers, duration, most affected OLTs |
| **Tickets: Final Reasons (7-day trend)** | Tickets and visits closed per final reason, day by day for a week against the week before |

### Life cycle, reasons and OLT performance

* **Ticket Life Cycle** works for outages, 4G tickets, customer tickets and site visits (the *Modules* filter; you
  only see the modules you may view). Each ticket raised in the period is followed step by step: *Raised* (by
  whom), every desk it waited at — who had it, when it was accepted, how many minutes it stayed, the desk's
  target and whether it was kept — and *Closed* with the status, the final reason and the SLA result. A ticket
  still open ends at the desk it is waiting at.
* **OLT Performance** has one line per OLT, technician and dispatcher. Outages: reported → dispatched and
  start → restored. Site visits: reported → first assigned and reported → completed (working hours). SLA
  compliance is met ÷ (met + breached); an open ticket past its deadline counts as breached.
* **Reason for Outage** counts the same wording together ("Fibre cut by contractor" and "fibre cut  by
  Contractor" are one reason) and separates real outages from tickets NOC rejected. 4G outages have their own
  *4G Root Cause Analysis*.
* **Final Reasons** shows the seven days up to the *To* date. The same table is on the **Customer Care
  dashboard** ("Final reasons — last 7 days") and in the scheduled e-mails (`{{tk_final_reasons_table}}`).

### Staff productivity

*Reporting → Reports → Staff Productivity (all modules)* covers **outages (FTTH / core / system), 4G outages, customer
tickets and site visits** in one report. Choose *Group by* (hourly, daily, weekly, monthly or the whole period), the
modules and the people. Each row is one person, one module and one period, plus an **All modules** row for people who
worked in more than one:

| Column | Meaning |
|---|---|
| Created | Tickets the person raised (outages, 4G tickets, customer tickets, site visits) |
| Assigned / Accepted | FIFO or manager assignments received / accepted, and the average minutes to accept |
| Rejected / not accepted in time | Assignments rejected or left to time out |
| Steps done | Queue steps completed (NOC review, dispatch, technician work, a desk's work…) |
| Avg time to handle / total handling | Assigned → step done, average minutes and total hours |
| Tickets worked | Each ticket counted once per person and period: accepted, worked, closed, escalated or restored |
| Closed / avg age at closing | Tickets the person closed (cancelled and duplicates excluded) and how long they had been open |
| Escalated | Hand-offs to another desk, IT → NOC, the 4G "Escalate" |
| Field work / time on premises | Restorations reported (outage technicians, 4G engineers) and site visits completed; time on site |
| Customer calls / updates posted | Calls logged on tickets, progress updates written |
| Close rate / escalation rate | Closed ÷ worked, escalated ÷ worked |

People only see the modules they may view (outage, 4G, Customer Care). Four **scheduled e-mails** (inactive until
recipients are set, editable under Email Templates) send it by module and by person with the full report attached:
*hourly* (last hour), *daily* at 19:00 (the day so far; before 09:00 it covers yesterday), *weekly* on Monday 07:00
(last Monday–Sunday) and *monthly* on the 1st at 07:00 (last month). On a system that already holds data, create
them with `php artisan db:seed --class=ProductivitySeeder --force`.

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
| Outage Summary – Weekly | Monday 07:00 | Last week's outages (from Monday 00:00), trend, top risk areas + Excel |
| Outage Summary – Monthly | The 1st at 07:00 | Last month's outages, trend, top risk areas + Excel |
| Tickets – Raised & Closed | Every 2 hours | Tickets raised and closed in the last two hours, pending per department |
| Tickets – Summary | Every 6 hours | Pending against the SLA per department and region, follow-up, final reasons + pending list |
| Tickets – Executive Summary | 07:00 and 19:00 | The day against the day before, SLA, departments, final reasons, trend charts |
| Tickets – Final Reason Report | Daily 07:00 | Final reasons day by day for seven days against the week before + Excel |
| Staff Productivity – Every 2 Hours | Every 2 hours | What each person did in the last two hours (weekly Monday 07:00 and monthly on the 1st already exist) |

The last seven were added in October 2026. On a system already in use, create them (and nothing else — existing
templates are never touched) with:

```bash
php artisan db:seed --class=EmailTemplateSeeder --force
```

Then set the recipients and switch each one on under *Email Templates & Schedules*.

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

### Desktop notifications

Everything that lands in a person's bell — a ticket assigned to them, an escalation, an SLA or desk-SLA warning
or breach, a returned ticket, a step of a custom workflow — is also shown as a **browser desktop notification**,
so it is seen when OMS is in a background tab or the person is working in another program. Clicking it brings
OMS to the front and opens the ticket.

* **Switching it on.** After signing in, a small card asks *Enable notifications*. The browser's own permission
  question only appears when the person clicks it (a browser will not let a site ask by itself, and permission
  can never be granted for someone). *Remind me later* hides the card until the next sign-in in that tab; *Don't
  ask again on this computer* hides it for good. If notifications were blocked, the card says how to unblock
  them (padlock next to the address → Notifications → Allow). Permission is per browser and per computer.
* **How it arrives.** Two ways, and each notification is shown once:
  live over Reverb (the event `desktop.alert` on the person's own private channel), and — so it does not depend on
  Reverb — every open page asks the server every 40 seconds for the person's unread notifications since it last
  asked (`GET /desktop-alerts`). With Reverb down, an alert is therefore at most about a minute late.
* **While looking at OMS** no desktop notification is shown: the page shows its own message instead.
* **What is on the desktop**: the title ("Ticket assigned to you: OUT-…", "SLA BREACHED: …") and the ticket
  number — never customer names or phone numbers, since a desktop notification can be read by anyone near the
  screen. Breaches and escalations stay on screen until dismissed.
* **Requirements and limits.** The site must be served over **HTTPS** (browsers only allow notifications on
  secure pages) — on an `http://` address the card appears and says so instead of offering the button. To try it
  on a development machine with Herd: `herd secure outagemanagement`, set `APP_URL=https://outagemanagement.test`
  and open that address. OMS must be open in a tab — any page, in the background is fine. With the browser closed
  nothing can be shown (that needs web push, which is not part of this; phones get push through the mobile app).
  Works in Chrome, Edge, Brave and Firefox; the operating system's "Do not disturb / Focus" mode hides them.
* **Nothing to configure**: no setting, no build step (the script is part of the page), no migration.

## Mobile app API

A JSON API under **`/api/v1`** for the field app (Flutter, in its own repository `oms-mobile`), used by three kinds
of people only: **dispatchers**, **technicians** and **4G engineers**. Everything else stays on the website.

```
                       OMS (Laravel)
   Sanctum API · ticket workflow · authorisation · notification logic
        ▲                    │                              │
      HTTPS                Reverb                          FCM
   /api/v1 (token)      app is open            app in the background / closed
        │                    │                              │
        └──────────────── Flutter OMS app (Android / iOS) ──┘
```

**The phone is an untrusted client.** Who the person is, which tickets they may see and what they may do is
decided here on every request — never by anything the app sends.

| Endpoint | |
| --- | --- |
| `POST auth/login` | username or e-mail + password (+ `code` for accounts with two-step sign-in) → token, expiry, user |
| `POST auth/logout` | revokes this phone's token and device |
| `GET me` · `POST me/availability` | profile, mobile role; Available / Busy / On break / Offline (FIFO offers tickets only to Available people) |
| `GET dashboard` · `GET config` | tiles with counts; Reverb connection details, whether Firebase pushes are on, list filters |
| `GET tickets` | `scope`, `module`, `priority`, `search`, `page` — paginated |
| `GET tickets/{id}` | the ticket, its timeline, photos **and the actions this person may take now** |
| `POST tickets/{id}/actions/{action}` | run one of those actions (multipart: answers + photos) |
| `GET actions` · `POST actions/{action}` | what the person can start without a ticket — **raise corrective / preventive maintenance** on site — with the forms; the answer carries the ticket that was created |
| `GET attachments/{id}` · `GET photos/{id}` | private files, with the token |
| `GET notifications` · `POST notifications/{id}/read` · `POST notifications/read-all` | the same notifications as the web bell |
| `POST devices` · `DELETE devices/{id}` | the phones a person is signed in on, each with its Firebase push token |
| `POST broadcasting/auth` | signs the subscription to the person's own Reverb channel |

* **Sign-in** uses the existing accounts (no second user system): a Sanctum personal access token per phone, valid
  `OMS_MOBILE_TOKEN_DAYS` (30). Wrong password, unknown and inactive accounts get the same answer; 5 attempts a
  minute per account and address. Accounts with an authenticator app must send the 6-digit code; with
  `OMS_REQUIRE_MFA=true` an account that has not set one up cannot use the app. Deactivating a user, or removing
  their field role, takes effect on their next request and revokes the token.
* **Who may use it** (`App\Services\Mobile\MobileProfile`), from permissions, not role names — *dispatcher*:
  `outage.dispatch`, `visit.dispatch` or `queue.work_infra_support_dispatch`; *engineer*: receives 4G tickets and
  handles transmission; *technician*: any other field queue (outage, support, site visit, infra support, 4G
  eNodeB). Anyone else gets *"Your account is not authorized to use the mobile application."*
* **Camera-only photos** — a photo field with `"capture": "camera"` (the three site photos of an Emergency /
  Regular outage, the two "after" photos of field maintenance, and on a site visit the geolocation photo, the
  speed-test photo and any photo added to the visit) must be taken there and then: the app offers no
  gallery, stamps the time on the picture and sends it as `{field}_taken_at`. A time in the future (a phone whose
  clock runs ahead) is replaced by the server's own. Other photo fields say `"capture": "any"`.
* **Which tickets** — a dispatcher sees the tickets of the kinds they dispatch; a technician or engineer only the
  tickets they hold or held. Asking for any other id is a `403` (`404` if it does not exist): knowing an id is
  never enough.
* **Server-driven actions** (`App\Services\Mobile\MobileActions`) — the ticket answer lists the actions the
  person may take *right now* with the form each needs (fields, options, mandatory). The app only draws them.
  Each action is checked again by the policy and runs through `OutageWorkflow` / the FIFO engine, exactly like
  the website button: accept / reject an assignment, dispatch, report restored, request / complete support, set
  status, approve preventive downtime, 4G start / resolve / status / photos, site-visit assign / reschedule /
  en route / arrive / pause / resume / complete (FAT number, geolocation and speed-test photos) / could not
  complete / verify / send back, hand over / return / close a ticket, add an update, add photos. A rule changed
  in the workflow applies to the app at once, with no app release.
* **Photos** are validated as real images (JPG / PNG / WebP, `OMS_MOBILE_MAX_UPLOAD_KB`), stored on the private
  disk next to the website's uploads and only served to people who may see the ticket.
* **Notifications** — every in-app notification (the one the web bell shows) also goes to the person's phones,
  two ways, from `SendNotificationJob`:
  * **Reverb, while the app is open** — `mobile.notification` on the person's private channel
    `App.Models.User.{id}` (`App\Events\MobileNotification`); the app shows a banner at once.
  * **Firebase Cloud Messaging, while the app is in the background or closed** — `SendPushNotificationJob`
    pushes to every phone of that person that registered an FCM token (`mobile_devices.push_token`), over the
    FCM HTTP v1 API (`App\Services\Mobile\FcmSender`); the phone shows the notification itself.

  Both carry only `id`, `type`, `title` (e.g. "Ticket assigned to you: OUT-…"), `ticket_id` and `ticket_number`
  — never the ticket. Tapping it makes the app call `GET tickets/{id}`, and the server decides then whether this
  person may see it (signed out: sign-in first, then the same check). The app shows a notification that arrives
  both ways once. A token Firebase reports as no longer registered is dropped; a token that turns up on another
  person's sign-in is taken off the previous person's device; signing out removes the device.
* **Firebase setup** — in the Firebase console: *Project settings → Service accounts → Generate new private key*.
  Put the JSON on the server outside git (e.g. `storage/app/private/firebase/service-account.json`, readable by
  the web user) and set `OMS_FCM_CREDENTIALS` to its path; `GET config` then reports `push.enabled: true`.
  Without it nothing is pushed and notifications only reach an open app. Pushes are sent by the queue worker.
* **Rate limits** — `mobile` 180 requests / minute per person, `mobile-write` 40 / minute, `mobile-login` 5 / minute.
* **Old apps** — set `OMS_MOBILE_MIN_VERSION` to refuse versions below it (`426`, "please update").
* `https://<host>/mobile/tickets/{id}` opened in a browser redirects to the ticket's page on the website.

**Deploying:** `composer install` (adds `laravel/sanctum`), `php artisan migrate --force` (tables
`personal_access_tokens`, `mobile_devices`), set `OMS_FCM_CREDENTIALS`, `php artisan optimize`,
`php artisan queue:restart`. Reverb must be reachable from the phones over `wss://` — the app uses the same
`REVERB_*` / `VITE_REVERB_*` values as the website — and the server must be able to reach
`oauth2.googleapis.com` and `fcm.googleapis.com` over HTTPS.

**Phones outside the company network.** The website is internal; the app is used on mobile data. Publish
the API only — a separate nginx `server` on its own port, which the load balancer (it holds the public
certificate and forwards plain HTTP) points at. Everything but `/api/v1/` answers 404 there, so the web
panel stays internal:

```nginx
server {
    listen 5301;
    listen [::]:5301;
    server_name oms.savannafibre.co.ug;

    root /home/nms/www/outagemangementsystem/public;
    server_tokens off;
    client_max_body_size 64M;          # photos from the app

    location / { return 404; }

    location ^~ /api/v1/ {
        try_files $uri /index.php?$query_string;
    }

    location = /index.php {
        internal;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

* No `ssl` on that listener when the load balancer speaks plain HTTP to it (a `400` for every request in
  its access log means the two disagree). No `error_page 404 /index.php` — it would hand every path to
  the application.
* The app's address is the public one with its port (`https://oms.savannafibre.co.ug:5301/api/v1`); the
  links the API returns (photos, attachments) follow the address the phone called.
* `APP_URL` stays the **internal** website address: links in e-mails and notifications are built from it.
* Uploads: PHP-FPM `upload_max_filesize` ≥ `OMS_MOBILE_MAX_UPLOAD_KB` and `post_max_size` to match
  `client_max_body_size` (the stock 2M / 8M refuse photos).
* Behind the load balancer every phone has its address: set `TRUSTED_PROXIES` to it (and have it send
  `X-Forwarded-For`), or all phones share the per-address sign-in limit.
* The in-app live channel (Reverb) is not published by this: phones outside get their notifications
  through Firebase until the socket is published too.

**Keeping the app in step:** `OMS_MOBILE_FIXTURES=<folder> php artisan test --filter=MobileApiTest` writes the real
API answers as JSON; the app's own tests read them (`oms-mobile/test/fixtures/api`).

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
| System owners | CRM Manager, CPaaS Manager (system outages of their own systems) |
| Management | Executive (read-only), Super Admin |

### Screens with a switch of their own

Every menu entry and screen is behind a permission; nothing is shown to a role that does not hold it (a CX agent
has no 4G NOC menu, a 4G engineer no Customer Care menu). Five screens that used to come with a module's "view"
permission can be switched off per role without taking the work away:

| Permission | Screen | Also needs |
|---|---|---|
| `dashboard.view` | Command centre dashboard | `outage.view` |
| `ticket.dashboard` | Customer Care dashboard | `ticket.view` |
| `visit.view` | Site visits list and menu entry | `ticket.view` |
| `mobility.dashboard` | 4G dashboard | `mobility.view` |
| `mobility.engineers` | 4G engineers & technicians availability | `mobility.view` |

When these were introduced (migration `add_screen_permissions`) every role, and every person holding the parent
permission directly, was given the screens they already saw — so nothing changed on that day. To make a role see
less, untick the screen under *People → Roles & Permissions*: for example a CX Agent without `dashboard.view`,
`ticket.dashboard` and `visit.view` still raises and follows customer tickets and reports outages, but no longer
sees the two dashboards or the site visits list. A site visit a person is assigned to still opens from My Work.

The role seeder only **adds** missing permissions to existing roles (it never removes any), so on an existing
installation review *Support/Installation Technician* — it no longer needs `queue.work_technician`,
`outage.update_restoration` or `outage.change_status` now that it works its own Support Technician queue.

## Importing users

Accounts for a whole team from a staff list (.xlsx or .csv). **An e-mail address is enough** — the rest is optional:

```
Email | Name | Username | Password | Department | Roles | Team | Status | Phone
```

```bash
# what would happen — nothing is saved
php artisan oms:import-users /path/to/staff.xlsx --preview

# a bare list of addresses: everything else from the command
php artisan oms:import-users /path/to/emails.csv --role="CX Agent" --department=CX --team="Customer Experience" --random-passwords
```

* **Name and username from the address** when the file does not give them: `john.doe@…` is *John Doe* /
  `john-doe` (numbered when the username is taken). The e-mail column may be headed *Email*, *Mail*, *Column1*, or
  have no heading at all (a plain list).
* **Department, roles, team** — the cell, or `--department` / `--role` / `--team` for rows that leave it empty.
  Matched without case against what exists; several roles or teams are separated by commas. Nothing is created
  here: an unknown role, team or department is an error.
* **Passwords.** A password from the file is stored hashed and must meet the system's password rule (production:
  12 characters, upper and lower case, a number, a symbol). `--random-passwords` ignores the column — every
  account gets a random password nobody knows and each person sets their own with *Forgot your password?*
  (outgoing e-mail must work). `--allow-weak-passwords` accepts the file's passwords as they are. Passwords are
  never printed, logged or reported; afterwards the file is the only place they exist in the clear — delete it.
* **People who already have an account** (same e-mail) are left as they are; `--update` applies what the file
  says (name, username, phone, department, status) and adds roles and teams. A password is never changed.
* **A username that already belongs to another account** (another e-mail address — as a rule the same person,
  registered earlier under a private address) stops the import; `--skip-existing` leaves those rows out, lists
  them with the address of the earlier account, and imports the rest.
* **All or nothing.** Any bad row (invalid or repeated address, unknown role, username in use, weak password)
  and no account is created; every such row is listed.
* New accounts are active, with a verified address, and *Offline* for FIFO until the person sets themselves
  available. No e-mail is sent to them.

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

…or **Import topology** on the **OLT, FDT, Slot or Port** screen (permission `topology.manage`) — the same button
everywhere, because one file fills all four levels. The form asks *What should happen?* and has no default:
**Import** saves the file, **Preview only** checks it and saves nothing (the result then reads *PREVIEW ONLY — nothing
was saved*, in amber). After an import the message lists what each OLT of the file now holds (FDTs / slots / ports) and
links to the FDT, slot and port lists already filtered to that OLT; a database error is shown as *Import failed —
nothing was saved*.

Newly imported rows sit at the **end** of the FDT / Slot / Port lists, and slot and port names are only numbers — use
the **OLT filter** (or search the OLT / FDT name) on those screens, or the *FDTs → Slots → Ports* arrows on a row. The
OLT list shows each OLT's FDT and slot counts.

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
| `oms:ticket-tick` | every 5 minutes | Customer tickets: queue tickets pending past the follow-up time for a customer call; send the ready-for-credit list |
| `oms:ticket-progress` | every minute | 4G customer tickets: remind the holder when no update has been posted for 20 minutes; remind silent vendors by e-mail |
| `oms:import-4g-topology {file} [--dry-run]` | manual | Import the 4G workbook: eNodeB sites and transmission nodes (RSG → ASG → CSG → SSG) |
| `oms:prune-report-exports` | every 10 minutes | Remove report exports older than 6 hours (file and row); close exports whose worker died |
| `oms:prune-expired-cache` (scheduled closure) | hourly | Clean expired rows when the database cache driver is used |
| `queue:prune-failed`, `queue:prune-batches` | daily | Housekeeping |
| `oms:create-admin` | manual | Create a Super Admin |
| `oms:import-users {file} [--preview] [--role=] [--department=] [--team=] [--random-passwords] [--allow-weak-passwords] [--skip-existing] [--update]` | manual | Create user accounts from a staff list — see [Importing users](#importing-users) |
| `oms:import-topology {file} [--dry-run] [--missing=keep\|deactivate\|remove]` | manual | Import the OLT → FDT → Slot → Port mapping |
| `oms:import-customers {file} [--preview] [--keep-incomplete]` | manual | Import the customer list (same import as *Customers → Import customers*; for files too large to upload) |
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
| `OMS_GEO_NOMINATIM_URL`, `OMS_GEO_TILE_URL`, `OMS_GEO_USER_AGENT` | OpenStreetMap, `SavannaFibre-OMS/1.0 (+https://oms.savannafibre.co.ug)` | Where the place name and the small map of a geolocation photo are looked up, and how OMS identifies itself there (OpenStreetMap refuses applications that do not: 403) |
| `OMS_EXPORTS_QUEUE` | `default` | Queue of the report exports. Set it to `exports` and list that queue first on the workers (`--queue=exports,default,notifications,reports`) so an export never waits behind a backlog of notifications |
| `SMS_DRIVER` | `log` | SMS gateway: `log`, `africastalking`, `twilio` or `http` |
| `SMS_SENDER`, `SMS_DEFAULT_COUNTRY_CODE`, `SMS_MAX_LENGTH` | `OMS`, `256`, `306` | Sender ID, calling code for local numbers, message length cap |
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
* Streamed CSV / Excel exports, written by a queue worker rather than in the person's request; queued
  notifications with retry / backoff; post-commit FIFO processing.
* Indexes on the columns behind "my tickets", a technician's and a team's tickets, the bell and the audit
  filters (`2026_10_12_100003_add_indexes_for_load`) — PostgreSQL does not index foreign keys by itself.
* Desktop alerts stop asking the server every 40 seconds once the live connection has delivered one (then every
  5 minutes as a safety net).
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
