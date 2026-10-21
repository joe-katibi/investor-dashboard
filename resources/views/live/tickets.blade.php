<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta http-equiv="refresh" content="{{ $refresh }}">
    <title>Executive live summary ({{ $report_time }})</title>
    <style>
        body { margin: 0; padding: 16px; background: #f4f5f7; font-family: "Segoe UI", Arial, sans-serif; color: #1f2937; }
        .card { max-width: 1100px; margin: 0 auto 16px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; }
        .head { padding: 16px 20px; border-bottom: 3px solid #d97706; display: flex; flex-wrap: wrap; gap: 4px 16px; align-items: baseline; justify-content: space-between; }
        .head h1 { margin: 0; font-size: 18px; }
        .muted { color: #6b7280; font-size: 13px; font-weight: normal; text-transform: none; letter-spacing: 0; }
        .live { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #16a34a; margin-right: 6px; }
        .body { padding: 16px 20px; font-size: 14px; }
        nav { display: flex; flex-wrap: wrap; gap: 8px; padding: 10px 20px; border-bottom: 1px solid #e5e7eb; font-size: 13px; }
        nav a { color: #b45309; text-decoration: none; padding: 4px 10px; border: 1px solid #fcd9a8; border-radius: 999px; background: #fff7ed; }
        h2 { font-size: 15px; margin: 0 0 6px; }
        h3 { font-size: 12px; letter-spacing: .04em; text-transform: uppercase; margin: 18px 0 6px; }
        .scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .foot { max-width: 1100px; margin: 0 auto; font-size: 12px; color: #6b7280; text-align: center; }
    </style>
</head>
<body>
{{-- Every table and tile below is built by OMS (TicketTables / OperationalSnapshot), its values escaped there. --}}
<div class="card">
    <div class="head">
        <h1>{{ config('app.name') }} — Executive live summary</h1>
        <span class="muted"><span class="live"></span>Live · as at {{ $report_time }}, {{ $report_date }} · refreshes every minute</span>
    </div>
    <nav>
        <a href="#ftth">FTTH outages</a><a href="#mobility">4G outages</a><a href="#tickets">Customer tickets</a><a href="#visits">Site visits</a><a href="#fourg">4G tickets</a>
    </nav>
</div>

<div class="card" id="ftth">
    <div class="body">
        <h2>FTTH outages <span class="muted">— network, core and system, open now; new and restored since midnight</span></h2>
        <div class="scroll">{!! $ftth['tiles'] !!}</div>
        <h3>Active outages</h3>
        <div class="scroll">{!! $ftth['table'] !!}</div>
    </div>
</div>

<div class="card" id="mobility">
    <div class="body">
        <h2>4G outages <span class="muted">— open now; new and restored since midnight</span></h2>
        <div class="scroll">{!! $mobility_tiles !!}</div>
        <h3>Active 4G outages</h3>
        <div class="scroll">{!! $mobility_table !!}</div>
    </div>
</div>

@foreach ($ticket_parts as $id => $part)
<div class="card" id="{{ $id }}">
    <div class="body">
        <h2>{{ $part['title'] }} <span class="muted">— pending against the SLA · SLA clock: {{ $part['tk_sla_clock'] }}</span></h2>
        <div class="scroll">{!! $part['tk_headline_table'] !!}</div>

        <h3>SLA per department <span class="muted">(pending, by how long they have been open)</span></h3>
        <div class="scroll">{!! $part['tk_sla_per_department_table'] !!}</div>

        <h3>SLA per OLT</h3>
        <div class="scroll">{!! $part['tk_sla_per_olt_table'] !!}</div>

        <h3>Issue per OLT</h3>
        <div class="scroll">{!! $part['tk_issue_per_olt_table'] !!}</div>

        <h3>SLA per region</h3>
        <div class="scroll">{!! $part['tk_sla_per_region_table'] !!}</div>

        <h3>Closed</h3>
        <p>{{ $part['tk_closed'] }} in the last {{ $part['tk_period_hours'] }} hours ({{ $part['tk_closed_sla_pct'] }} within SLA) · {{ $part['tk_closed_today'] }} today.</p>
    </div>
</div>
@endforeach

<div class="card" id="fourg">
    <div class="body">
        <h2>4G tickets <span class="muted">— open now; raised and closed since midnight</span></h2>
        <div class="scroll">{!! $fourg_tiles !!}</div>
        <h3>Pending — with whom</h3>
        <div class="scroll">{!! $fourg_pending !!}</div>
    </div>
</div>

<div class="foot">Figures only — no customer details. Updated {{ $built_at }}. This link is private to management: do not forward it.</div>
</body>
</html>
