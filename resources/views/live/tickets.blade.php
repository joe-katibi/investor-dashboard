<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta http-equiv="refresh" content="{{ $refresh }}">
    <title>Tickets – Pending / SLA ({{ $report_time }})</title>
    <style>
        body { margin: 0; padding: 16px; background: #f4f5f7; font-family: "Segoe UI", Arial, sans-serif; color: #1f2937; }
        .card { max-width: 1100px; margin: 0 auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; }
        .head { padding: 16px 20px; border-bottom: 3px solid #d97706; display: flex; flex-wrap: wrap; gap: 4px 16px; align-items: baseline; justify-content: space-between; }
        .head h1 { margin: 0; font-size: 18px; }
        .muted { color: #6b7280; font-size: 13px; }
        .live { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #16a34a; margin-right: 6px; }
        .body { padding: 16px 20px; font-size: 14px; }
        h2 { font-size: 13px; letter-spacing: .04em; text-transform: uppercase; margin: 22px 0 6px; }
        .scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .foot { padding: 10px 20px; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280; }
    </style>
</head>
<body>
<div class="card">
    <div class="head">
        <h1>{{ config('app.name') }} — Tickets: Pending / SLA</h1>
        <span class="muted"><span class="live"></span>Live · as at {{ $report_time }}, {{ $report_date }} · refreshes every minute</span>
    </div>
    <div class="body">
        <div class="muted">SLA clock: {{ $tk_sla_clock }}</div>
        {{-- The tables are built by OMS (App\Services\Tickets\TicketTables), values escaped there. --}}
        <div class="scroll">{!! $tk_headline_table !!}</div>

        <h2>SLA per department <span class="muted">(pending tickets by how long they have been open)</span></h2>
        <div class="scroll">{!! $tk_sla_per_department_table !!}</div>

        <h2>SLA per OLT</h2>
        <div class="scroll">{!! $tk_sla_per_olt_table !!}</div>

        <h2>Issue per OLT</h2>
        <div class="scroll">{!! $tk_issue_per_olt_table !!}</div>

        <h2>SLA per region</h2>
        <div class="scroll">{!! $tk_sla_per_region_table !!}</div>

        <h2>Closed in the last {{ $tk_period_hours }} hours</h2>
        <p>{{ $tk_closed }} closed, {{ $tk_closed_sla_pct }} within SLA.</p>
    </div>
    <div class="foot">Figures only — no customer details. Updated {{ $built_at }}. This link is private to management: do not forward it.</div>
</div>
</body>
</html>
