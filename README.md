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
8. [Outage lifecycle](#outage-lifecycle)
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
| `dispatch@oms.test` | Dispatcher | |
| `infra.manager@oms.test`, `infra.lead@oms.test` | Infrastructure Manager / Team Leader | |
| `infra.dispatch1@oms.test`, `infra.dispatch2@oms.test` | Infrastructure Dispatcher | Receive Infrastructure queue tickets |
| `tech.north1@oms.test`, `tech.north2@oms.test` | Outage Technician (North Crew) | Cover Salaama, Kanengo OLTs |
| `tech.south1@oms.test`, `tech.south2@oms.test` | Outage Technician (South Crew) | Cover Area 47, Njewa OLTs |
| `vendor.tech1@oms.test` | Outage Technician (outsourced) | FibreTech Contractors |

Demo data also includes teams / sub-teams, four OLTs with FDT → Slot → Port topology, 7 days of shifts for every
user, skills, and seven sample outages driven through the real workflow (one fully resolved).

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
  reason / RFO, plus tabs for timeline & updates, status history, FIFO assignments (with *Why?* explanation),
  assignment history, SMS & credit history, SLA events, notifications and audit trail.
* Workflow actions (only shown when both permission and workflow state allow them): Accept / Reject assignment,
  NOC Confirm, NOC Reject (reason mandatory), Dispatch, Add update, Report restored, NOC Restore Confirmed,
  Restoration not confirmed, Resolve (final reason when required), Set status, SMS / Credit, Assign / override FIFO.

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
| 4. NOC Reject | Reason mandatory; outage closed, SLA *N/A*, queues cleared. |
| 5. NOC Confirm | Confirmation recorded; for types requiring Infrastructure the outage moves to *Assigned to Infrastructure* and into the **Infrastructure** FIFO queue. System outages skip Infrastructure. |
| 6. Dispatch | Dispatcher selects team type, team, sub-team and either a specific **eligible** technician or automatic FIFO; status *Team Dispatched*. |
| 7. Field action | Technician posts updates, may set operational statuses (Partial Restore, Awaiting Field Access, Vendor Escalated, …) and reports restoration → *Restore Reported – Awaiting NOC* and the **NOC Restore** FIFO queue. |
| 8. NOC Restore Confirmed | Restoration time recorded; or *Restoration not confirmed* sends it back to the field team. |
| 9. Resolved | Final Reason / RFO required (status flag); SLA finalised as *met* / *missed*; all queue items closed. |
| 10. Notifications | Configured SLA, credit, SMS and operational notifications; dashboards update live. |

Rules enforced everywhere (UI, future APIs, integrations) by `App\Services\Outages\OutageWorkflow` +
`App\Policies\OutagePolicy`:

* every transition checks **permission and current workflow state** (even for Super Admin), locks the row, runs in
  one transaction and writes status history (with a snapshot of priority, team, technician, impact, queue, SLA);
* statuses flagged **Prompt for Final Reason** cannot be set without one;
* changing customers affected re-evaluates priority, recalculates SLA and re-ranks open FIFO items;
* topology selections are sanitised (children must belong to selected parents; inactive items cannot be newly attached);
* SMS / Credit are explicit YES/NO fields with *who* and *when*, plus a change history.

## FIFO assignment engine

Implemented as UI-independent domain services — `App\Services\Fifo\FifoAssignmentEngine` and
`App\Services\Fifo\EligibilityEvaluator` — so the same logic can be driven by the UI, the scheduler, APIs,
monitoring integrations or a mobile app.

**Queues** (configurable under *Configuration → FIFO Queues*): NOC Review, Infrastructure Dispatch, Technician,
NOC Restore Confirmation. Each queue definition sets department, optional team, routing (major type / vertical),
required permission, automatic or manual mode, acknowledgement timeout and escalation threshold.

**Ordering — priority-aware FIFO**: Critical → High → Medium → Low (rank editable per priority), oldest first within
each priority using microsecond queue timestamps with the id as final tie-break. Can be switched to strict FIFO.

**Eligibility rules** (each can be toggled in *FIFO & SLA Settings*):

| Rule | Requirement |
|---|---|
| User status | Active users only |
| Availability | Status *Available* |
| Department | Belongs to the queue's department |
| Permission | Holds the queue permission (`queue.work_noc`, `queue.work_infrastructure`, `queue.work_technician`) |
| Team / sub-team | Member of the required team (or its sub-teams) / sub-team |
| Shift | Inside an active scheduled shift |
| Leave | Not on approved leave |
| Skill (optional) | Skill for an affected service or the vertical |
| OLT coverage | Team covers the affected OLT (when coverage is configured) |
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
* Channels: **in-app** (Filament bell + real-time toast), **e-mail**, **SMS** (`log` or generic `http` driver).
* Events: outage created, NOC escalation, NOC confirmed / rejected, Infrastructure escalation, dispatched, status
  changed, restoration reported / confirmed, resolved, priority changed, assigned, assignment rejected,
  acknowledgement timeout, queue escalation, SLA pre-breach / breach / escalation, credit due, credit shared, SMS
  sent, scheduled report.
* Every recipient × channel is logged (recipient, event, time, status, attempts, failure reason) and delivered by a
  queued job with retry and backoff; failed deliveries can be retried from *Monitoring → Notification Log*.

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
| Planned Maintenance | Register filtered to planned types |
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

Each schedule slot is claimed atomically, so a report is never sent twice even with several servers.

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
| Outages | `outage.view`, `outage.create`, `outage.edit`, `outage.delete`, `outage.confirm`, `outage.dispatch`, `outage.update_progress`, `outage.update_restoration`, `outage.confirm_restoration`, `outage.close`, `outage.change_status`, `outage.update_credit_sms`, `outage.override_priority` |
| FIFO Queues | `queue.view`, `queue.work_noc`, `queue.work_infrastructure`, `queue.work_technician`, `queue.override` |
| Configuration | `topology.manage`, `sla.manage`, `templates.manage`, `notifications.manage`, `master_data.manage`, `config.admin` |
| People | `users.manage`, `teams.manage`, `roles.manage`, `workforce.manage` |
| Reporting & Audit | `reports.view`, `reports.export`, `audit.view` |

Seeded roles (spec §4.1):

| Department | Roles |
|---|---|
| NOC | NOC Manager, NOC Team Leader, NOC Tier II, NOC Tier I |
| Customer Experience | CX Manager, CX Team Leader, CX Agent |
| Dispatch | Dispatch Manager, Dispatch Team Leader, Dispatcher, Support/Installation Technician |
| Infrastructure | Infrastructure Manager, Infrastructure Team Leader, Infrastructure Dispatcher, Outage Technician |
| Management | Executive (read-only), Super Admin |

## Seeded reference data

`php artisan db:seed` always seeds the reference data below (idempotent — safe to re-run on every deploy); demo
users and outages are added only outside production. Each seeder can also be run on its own, e.g.
`php artisan db:seed --class=PrioritySeeder --force`.

| Seeder | Seeds |
|---|---|
| `RolesAndPermissionsSeeder` | Permission catalogue + roles above |
| `DepartmentSeeder` | NOC, Customer Experience, Dispatch, Infrastructure, Management |
| `VerticalSeeder` | FTTH, 4G Mobility |
| `ServiceSeeder` | FTTH: Internet, Voice (VoIP), IPTV, Enterprise Fibre · 4G: Mobile Data, Voice, SMS, Fixed Wireless (MiFi) · Cross-vertical: CRM, Call Center, Dialer, Billing (Savbill), International Transit (SEACOM) |
| `MajorOutageTypeSeeder` | Network (topology, NOC + Infrastructure), Core (NOC + Infrastructure), System (NOC only) |
| `OutageTypeSeeder` | Regular `OUT`, Emergency `EMO`, Planned Maintenance `PLM`, SEACOM / Core `CORE`, System `SYS` |
| `OutageStatusSeeder` | Support Unconfirmed, NOC Confirmed, NOC Reject, Assigned to Infrastructure, Infrastructure Confirmed, Team Dispatched, Awaiting Field Access, Vendor Escalated, Partial Restore, Restore Reported – Awaiting NOC, NOC Restore Confirmed, Awaiting Customer Confirmation, Outage Resolved, Scheduled Maintenance |
| `PrioritySeeder` | Low 0–9, Medium 10–49, High 50–199, Critical 200+ customers; FIFO rank Critical 1 → Low 4 |
| `SlaPolicySeeder` | Critical 4h, High 8h, Medium 12h, Low 24h (pre-breach, ack timeout, escalation), Planned Maintenance window |
| `QueueDefinitionSeeder` | NOC Review (NOC, 10 min ack), Infrastructure Dispatch (INFRA, 15), Technician (INFRA, 20), NOC Restore (NOC, 15) |
| `NotificationRuleSeeder` | NOC / Infrastructure escalation alerts, critical outage created, assignment rejected, queue escalation, restoration awaiting NOC, resolved CX follow-up, credit due |
| `EmailTemplateSeeder` | Two-Hour Update, Executive Summary, Summary Report |
| `DemoDataSeeder` | Non-production only: teams, users, topology, shifts, skills, sample outages |

## Configuration screens

| Menu | Screen | Notes |
|---|---|---|
| Operations | Command Centre, My Work, Outages, FIFO Queues | |
| Reporting | Reports | |
| Topology | OLTs, FDTs, Slots, Ports | Hierarchical; OLT team coverage; deactivate instead of delete |
| Configuration | FIFO & SLA Settings | All spec §27.8 options, SLA fallbacks, pause switch |
| Configuration | Verticals, Affected Services, Major Outage Types, Outage Types (prefixes), Statuses (final reason / pauses SLA / manual / closed flags), Impact / Priority Rules (thresholds + FIFO rank), SLA Policies, FIFO Queues, Notification Rules, Email Templates & Schedules | Notification rules cannot be deleted — deactivate them |
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
  Console/Commands/        oms:sla-check, oms:fifo-timeouts, oms:fifo-sweep, oms:send-scheduled-reports, oms:create-admin
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
resources/css/oms.css      Custom view styles (registered via FilamentAsset, no build step)
resources/views/           E-mails, PDF report, custom Filament views
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
| `oms:prune-expired-cache` (scheduled closure) | hourly | Clean expired rows when the database cache driver is used |
| `queue:prune-failed`, `queue:prune-batches` | daily | Housekeeping |
| `oms:create-admin` | manual | Create a Super Admin |

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
| `SMS_DRIVER`, `SMS_URL`, `SMS_TOKEN`, `SMS_SENDER` | `log` | SMS gateway (`log` or `http`) |
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
| Live updates / bell notifications don't appear | Make sure `reverb:start` **and** a queue worker are running; broadcasts are queued. Check `REVERB_*` values match on server and client. |
| `Pusher error: No matching application for ID` in failed jobs | Another project's Reverb is using the same port. Give this project its own `REVERB_PORT` / `REVERB_SERVER_PORT` (default 8090). |
| Tickets stay unassigned in FIFO Queues | Use *Why?* on the ticket — typical causes: no user *Available*, no active shift, on leave, workload limit, missing queue permission or department. |
| Scheduled e-mails not sent | Template must be active with recipients; scheduler and queue worker must run; check *Notification Log*. |
| Changes to `.env` ignored | Run `php artisan optimize:clear` (config may be cached). |
| Custom styles missing | Run `php artisan filament:assets`. |
