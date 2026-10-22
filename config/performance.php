<?php

/*
 * The investor dashboard (Performance page). The service levels are printed on the page with these values —
 * change a window and the page says so; the figures are always recomputed from the recorded times.
 */
return [
    'months' => 6,

    'outages' => [
        'target' => (float) env('PERFORMANCE_OUTAGE_TARGET', 93),
        // Restored within this many clock hours of the fault occurring.
        'window_hours' => (float) env('PERFORMANCE_OUTAGE_WINDOW_HOURS', 24),
        // The internal restoration SLA of the incident log, shown alongside.
        'internal_hours' => 4,
    ],

    'visits' => [
        'target' => (float) env('PERFORMANCE_VISIT_TARGET', 90),
        // Completed within this many business hours (07:00–18:00, the ticket tracker's clock) of being reported.
        'window_hours' => (float) env('PERFORMANCE_VISIT_WINDOW_HOURS', 24),
        // The internal site-visit SLA of the ticket tracker (6 business hours; shifting 72), shown alongside.
        'internal_label' => '6 business hours (shifting 72)',
    ],
];
