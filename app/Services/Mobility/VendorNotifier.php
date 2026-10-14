<?php

namespace App\Services\Mobility;

use App\Enums\NotificationChannel;
use App\Filament\Support\OutageLink;
use App\Jobs\SendNotificationJob;
use App\Models\NotificationLog;
use App\Models\Outage;
use App\Models\VendorTemplate;
use App\Services\Maintenance\MaintenanceNotifier;
use App\Services\Reports\TemplateRenderer;
use App\Services\Sla\SlaService;
use App\Support\AuditContext;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * E-mails to external vendors, rendered from the editable vendor templates. Every message gets a
 * delivery log row first (ticket, vendor, recipient, CC, subject, template, time, status, error),
 * so the audit trail is complete even when the mail server refuses the message.
 */
class VendorNotifier
{
    /** Event name of vendor e-mails in the notification log. */
    public const EVENT = 'vendor_notification';

    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly Settings $settings,
    ) {}

    /**
     * Queue the active template(s) of a trigger for the ticket's vendor.
     *
     * @param  bool  $advance  move the ticket to "Vendor Notification Sent" once the e-mail is delivered
     * @param  string|null  $fallback  trigger to use when no template exists for $trigger
     * @return Collection<int, NotificationLog> one row per e-mail (status "failed" when it could not even be queued)
     */
    public function send(string $trigger, Outage $outage, array $context = [], bool $advance = false, ?string $fallback = null): Collection
    {
        $outage->loadMissing('vendor');
        $vendor = $outage->vendor;

        if (! $vendor) {
            return collect();
        }

        $templates = $this->templatesFor($trigger, $outage);

        if ($templates->isEmpty() && $fallback) {
            $templates = $this->templatesFor($fallback, $outage);
        }

        $data = $this->data($outage, $context);

        return $templates->map(function (VendorTemplate $template) use ($outage, $vendor, $data, $advance, $trigger): NotificationLog {
            $to = $this->addresses([$template->send_to_vendor ? $vendor->email : null, ...(array) $template->to]);
            $cc = array_values(array_diff($this->addresses([...($template->cc_vendor_cc ? $vendor->ccList() : []), ...(array) $template->cc]), $to));
            $html = $this->renderer->render($template->body, $data);

            $log = NotificationLog::query()->create([
                'outage_id' => $outage->id,
                'event' => self::EVENT,
                'channel' => NotificationChannel::Mail->value,
                'recipient' => $to[0] ?? $vendor->name.' (no e-mail address)',
                'subject' => Str::limit(trim($this->renderer->renderText($template->subject, $data)), 250, ''),
                'body' => Str::limit(trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>'], "\n", $html)))), 5000),
                'meta' => [
                    'kind' => 'vendor',
                    'html' => $html,
                    'to' => $to,
                    'cc' => $cc,
                    'vendor_id' => $vendor->id,
                    'vendor' => $vendor->name,
                    'vendor_template_id' => $template->id,
                    'template' => $template->name,
                    'vendor_trigger' => $trigger,
                    'advance' => $advance,
                ],
                'status' => $to === [] ? 'failed' : 'pending',
                'failure_reason' => $to === [] ? "Vendor {$vendor->name} has no e-mail address and the template has no TO address." : null,
                'trigger' => AuditContext::trigger(),
            ]);

            if ($to !== []) {
                SendNotificationJob::dispatch($log->id);
            }

            return $log;
        })->values();
    }

    /**
     * A type-specific template wins over the general one for the same trigger.
     *
     * @return Collection<int, VendorTemplate>
     */
    public function templatesFor(string $trigger, Outage $outage): Collection
    {
        $templates = VendorTemplate::query()->active()->where('trigger', $trigger)
            ->where(fn ($q) => $q->whereNull('outage_type_id')->orWhere('outage_type_id', $outage->outage_type_id))
            ->orderBy('id')->get();

        $specific = $templates->whereNotNull('outage_type_id');

        return ($specific->isNotEmpty() ? $specific : $templates)->values();
    }

    /** Placeholder values for vendor templates. */
    public function data(Outage $outage, array $context = []): array
    {
        $outage->loadMissing(['vendor', 'outageType', 'priority', 'status', 'services', 'enodebs', 'transmissionNodes', 'slaPolicy', 'ticket.subcategory']);

        $sites = $outage->enodebs;
        $more = $sites->count() > 1 ? ' (+'.($sites->count() - 1).' more)' : '';
        $minutes = $outage->slaPolicy?->resolution_minutes
            ?? app(SlaService::class)->resolvePolicy($outage)?->resolution_minutes
            ?? $this->settings->int('sla.default_resolution_minutes');
        $end = $outage->restored_at ?? $outage->end_time;
        // The vendor's own reference for a site: what their engineer looks the site up by.
        $towerIds = $sites->filter(fn ($site) => filled($site->tower_co_site_id));

        return array_merge([
            'ticket_number' => $outage->ticket_number,
            'site_id' => $sites->isNotEmpty() ? $sites->first()->site_id.$more : '—',
            'site_name' => $sites->isNotEmpty() ? $sites->first()->name.$more : ($outage->location ?: $outage->title),
            'tower_co_id' => match (true) {
                $towerIds->isEmpty() => '—',
                $sites->count() === 1 => $towerIds->first()->tower_co_site_id,
                default => $towerIds->map(fn ($site) => $site->tower_co_site_id.' ('.$site->site_id.')')->implode(', '),
            },
            'affected_sites' => $sites->map(fn ($site) => $site->site_id.' '.$site->name.(filled($site->tower_co_site_id) ? ' (Tower CO ID '.$site->tower_co_site_id.')' : ''))->implode(', ') ?: '—',
            'affected_nodes' => $outage->transmissionNodes->map(fn ($node) => $node->label())->implode(', ') ?: '—',
            'location' => $outage->location ?: '—',
            'outage_type' => $outage->outageType?->name,
            'priority' => $outage->priority?->name ?? '—',
            'outage_start_time' => $outage->start_time?->format('d/m/Y H:i'),
            'detection_time' => ($outage->detected_at ?? $outage->created_at)?->format('d/m/Y H:i'),
            'affected_services' => $outage->services->pluck('name')->implode(', ') ?: '—',
            'description' => $outage->description ?: '—',
            'service_impact' => $outage->service_impact ?: '—',
            'vendor_name' => $outage->vendor?->name,
            'vendor_contact' => $outage->vendor?->contact_person ?: $outage->vendor?->name,
            'sla' => MaintenanceNotifier::duration((int) $minutes),
            'sla_due' => $outage->sla_due_at?->format('d/m/Y H:i') ?? '—',
            'status' => $outage->status?->name,
            'noc_contact' => $this->settings->get('mobility.noc_contact') ?: (string) config('mail.from.address'),
            'ticket_url' => OutageLink::url($outage),
            'outage_duration' => $outage->start_time ? MaintenanceNotifier::duration(max(1, (int) $outage->start_time->diffInMinutes($end ?? now()))) : '—',
            'restoration_time' => $end?->format('d/m/Y H:i') ?? '—',
            'root_cause' => $outage->root_cause ?: '—',
            'resolution' => $outage->resolution ?: '—',
            'issue' => $outage->ticket?->subcategory?->name ?? $outage->title,
            'team' => $outage->current_queue?->getLabel() ?? '—',
            'reason' => '',
        ], array_intersect_key($context, array_flip(VendorTemplate::PLACEHOLDERS)));
    }

    /** Sample values for the template preview. */
    public function sampleData(): array
    {
        return [
            'ticket_number' => '4G-PWR-000123', 'site_id' => '4624', 'site_name' => 'Nsambya Kevina', 'tower_co_id' => 'ATC-KLA-0412',
            'affected_sites' => '4624 Nsambya Kevina (Tower CO ID ATC-KLA-0412)',
            'affected_nodes' => 'SSG-1111 — Nsambya Kevina', 'location' => 'Nsambya, Kampala', 'outage_type' => 'Power Outage', 'priority' => '4G Minor',
            'outage_start_time' => now()->subMinutes(20)->format('d/m/Y H:i'), 'detection_time' => now()->subMinutes(15)->format('d/m/Y H:i'),
            'affected_services' => 'Mobile Data, Voice', 'description' => 'Site is down on mains failure; batteries depleted.', 'service_impact' => '4G coverage lost around the site.',
            'vendor_name' => 'ATC', 'vendor_contact' => 'ATC NOC', 'sla' => '4 hours', 'sla_due' => now()->addHours(4)->format('d/m/Y H:i'),
            'status' => 'Vendor Assigned', 'noc_contact' => $this->settings->get('mobility.noc_contact') ?: (string) config('mail.from.address'),
            'ticket_url' => url('/admin'), 'outage_duration' => '20 minutes', 'restoration_time' => '—', 'root_cause' => '—', 'resolution' => '—',
            'reason' => 'Sample reason', 'issue' => 'No service / no signal', 'team' => 'RF Team',
        ];
    }

    /** @return list<string> valid, lower-cased, unique */
    private function addresses(array $emails): array
    {
        return collect($emails)
            ->map(fn ($email) => Str::lower(trim((string) $email)))
            ->filter(fn (string $email) => (bool) filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()->values()->all();
    }
}
