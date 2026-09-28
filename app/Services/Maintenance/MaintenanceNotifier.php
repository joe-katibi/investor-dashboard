<?php

namespace App\Services\Maintenance;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Filament\Resources\Outages\OutageResource;
use App\Jobs\SendNotificationJob;
use App\Models\ChangeRequest;
use App\Models\ChangeRequestSignature;
use App\Models\MaintenanceTemplate;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Reports\TemplateRenderer;
use App\Support\AuditContext;
use App\Support\Settings;
use Database\Seeders\MaintenanceTemplateSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Planned-maintenance notifications:
 *  - in-app to the stakeholders (roles in Maintenance Settings) and to signatories with an account;
 *  - configured notification rules / alert list for the event (NotificationDispatcher);
 *  - the editable maintenance e-mail templates for the trigger, optionally with the CRF attached.
 */
class MaintenanceNotifier
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly NotificationDispatcher $dispatcher,
        private readonly Settings $settings,
    ) {}

    public function announce(NotificationEvent $event, ChangeRequest $changeRequest, ?string $trigger, array $context = []): void
    {
        $changeRequest->loadMissing(['outage', 'signatures.user']);
        $outage = $changeRequest->outage;
        $context += ['window' => $this->window($changeRequest)];

        $users = $this->stakeholderUsers()
            ->merge($changeRequest->signatures->pluck('user')->filter())
            ->merge(User::query()->active()->whereIn('id', array_filter((array) ($context['notify_user_ids'] ?? [])))->get())
            ->unique('id');

        $this->dispatcher->notifyUsers($event, $outage, $users, [NotificationChannel::InApp->value], $context);
        $this->dispatcher->dispatch($event, $outage, array_diff_key($context, ['notify_user_ids' => true]));

        if ($trigger) {
            $this->sendTemplates($trigger, $changeRequest, $context);
        }
    }

    /**
     * Send every active template for the trigger (or one template when $only is given).
     *
     * @return int e-mails queued
     */
    public function sendTemplates(string $trigger, ChangeRequest $changeRequest, array $context = [], ?MaintenanceTemplate $only = null): int
    {
        $templates = $only ? collect([$only]) : MaintenanceTemplate::query()->active()->forTrigger($trigger)->get();
        $queued = 0;

        foreach ($templates as $template) {
            $emails = $this->recipients($template, $changeRequest);

            foreach ($emails as $email) {
                $queued += $this->queueMail($template, $changeRequest, $email, $context);
            }
        }

        return $queued;
    }

    /** Personal "please sign" e-mails with a fresh link for each pending signatory. */
    public function sendSignRequests(ChangeRequest $changeRequest, ?Collection $signatures = null): int
    {
        $changeRequest->loadMissing(['outage', 'signatures']);
        $signatures ??= $changeRequest->signatures->where('status', ChangeRequestSignature::PENDING);

        $templates = MaintenanceTemplate::query()->active()->forTrigger('sign_request')->get();
        if ($templates->isEmpty()) {
            // Signing is impossible without the link, so fall back to the built-in wording.
            $templates = collect([new MaintenanceTemplate(MaintenanceTemplateSeeder::defaults()['sign_request'])]);
        }

        $queued = 0;

        foreach ($signatures as $signature) {
            $url = $signature->issueSignUrl();
            $context = [
                'signatory_name' => $signature->displayName(),
                'signatory_role' => $signature->role_label,
                'sign_button' => $this->button($url, 'Review & sign the CRF'),
            ];

            foreach ($templates as $template) {
                $queued += $this->queueMail($template, $changeRequest, $signature->email, $context, $signature->user_id);
            }
        }

        return $queued;
    }

    /** Placeholder values for maintenance templates. */
    public function data(ChangeRequest $changeRequest, array $context = []): array
    {
        $changeRequest->loadMissing(['outage.olts:id,name', 'outage.fdts:id,name,olt_id', 'outage.services:id,name', 'outage.team:id,name', 'outage.technician:id,name', 'signatures']);
        $outage = $changeRequest->outage;
        $start = $changeRequest->scheduled_start;
        $end = $changeRequest->scheduled_end;

        return array_merge([
            'ticket' => $outage->ticket_number,
            'title' => $outage->title,
            'scheduled_date' => $start->format('d/m/Y'),
            'start_time' => $start->format('H:i').'Hrs',
            'end_time' => $end->format('H:i').'Hrs'.($end->isSameDay($start) ? '' : ' ('.$end->format('d/m/Y').')'),
            'scheduled_window' => $this->window($changeRequest),
            'expected_downtime' => $changeRequest->expectedDowntime(),
            'time_left' => $this->timeLeft($changeRequest),
            'location' => $outage->location,
            'olt_fdt' => $outage->topologyPath() ?: ($outage->location ?: 'the affected area'),
            'customers' => number_format($outage->customers_affected),
            'services' => $outage->services->pluck('name')->implode(', '),
            'initiator' => $changeRequest->initiator,
            'department' => $changeRequest->department,
            'in_charge' => $changeRequest->in_charge,
            'emergency_contact' => $changeRequest->emergency_contact,
            'risk' => $changeRequest->riskLabel(),
            'work_description' => $changeRequest->work_description,
            'fallback_plan' => $changeRequest->fallback_plan,
            'prior_tests' => $changeRequest->prior_tests,
            'risks_mitigation' => $changeRequest->risks_mitigation,
            'crf_status' => $changeRequest->statusLabel(),
            'signed_count' => $changeRequest->signedCount(),
            'signatory_count' => $changeRequest->signatures->count(),
            'pending_signatories' => $changeRequest->signatures->where('status', ChangeRequestSignature::PENDING)
                ->map(fn (ChangeRequestSignature $s) => $s->role_label.' ('.$s->displayName().')')->implode(', ') ?: 'None',
            'final_status' => ChangeRequest::FINAL_STATUSES[$changeRequest->final_status] ?? '',
            'outcome_details' => $changeRequest->outcome_details,
            'team' => $outage->team?->name,
            'technician' => $outage->technician?->name,
            'ticket_button' => $this->button(OutageResource::getUrl('view', ['record' => $outage]), 'Open ticket '.$outage->ticket_number),
            'reason' => '',
            'previous_window' => '',
            'signatory_name' => '',
            'signatory_role' => '',
            'sign_button' => '',
        ], array_intersect_key($context, array_flip(MaintenanceTemplate::PLACEHOLDERS)));
    }

    /** @return Collection<int, User> */
    public function stakeholderUsers(): Collection
    {
        $roles = array_values(array_filter((array) $this->settings->get('maintenance.stakeholder_roles')));

        return $roles === [] ? collect() : User::query()->active()->role($roles)->get();
    }

    public function window(ChangeRequest $changeRequest): string
    {
        $start = $changeRequest->scheduled_start;
        $end = $changeRequest->scheduled_end;

        return $start->format('d/m/Y H:i').' – '.($end->isSameDay($start) ? $end->format('H:i') : $end->format('d/m/Y H:i')).' Hrs';
    }

    public function timeLeft(ChangeRequest $changeRequest): string
    {
        $minutes = (int) ceil(now()->diffInSeconds($changeRequest->scheduled_start, false) / 60);

        return $minutes <= 0 ? 'now' : 'in '.self::duration($minutes);
    }

    public static function duration(int $minutes): string
    {
        if ($minutes % 1440 === 0) {
            return ($d = intdiv($minutes, 1440)).' day'.($d === 1 ? '' : 's');
        }

        if ($minutes >= 60) {
            $hours = intdiv($minutes, 60);
            $rest = $minutes % 60;

            return $hours.' hour'.($hours === 1 ? '' : 's').($rest ? " {$rest} minutes" : '');
        }

        return $minutes.' minute'.($minutes === 1 ? '' : 's');
    }

    /** @return list<string> */
    private function recipients(MaintenanceTemplate $template, ChangeRequest $changeRequest): array
    {
        $emails = collect([...(array) $template->to, ...(array) $template->cc]);

        if ($template->send_to_stakeholders) {
            $emails = $emails
                ->merge((array) $this->settings->get('maintenance.stakeholder_emails'))
                ->merge((array) $changeRequest->stakeholder_emails)
                ->merge($this->stakeholderUsers()->pluck('email'));
        }

        if ($template->send_to_signatories) {
            $emails = $emails->merge($changeRequest->signatures->pluck('email'));
        }

        return $emails->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->map(fn (string $email) => Str::lower(trim($email)))
            ->unique()->values()->all();
    }

    private function queueMail(MaintenanceTemplate $template, ChangeRequest $changeRequest, string $email, array $context, ?int $userId = null): int
    {
        $data = $this->data($changeRequest, $context);
        $html = $this->renderer->render($template->body, $data);

        $log = NotificationLog::query()->create([
            'outage_id' => $changeRequest->outage_id,
            'event' => 'maintenance_'.$template->trigger,
            'channel' => NotificationChannel::Mail->value,
            'recipient_user_id' => $userId,
            'recipient' => Str::lower($email),
            'subject' => Str::limit($this->renderer->renderText($template->subject, $data), 250, ''),
            'body' => Str::limit(trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>'], "\n", $html)))), 5000),
            'meta' => [
                'maintenance_template_id' => $template->id,
                'change_request_id' => $changeRequest->id,
                'html' => $html,
                'attach_crf' => (bool) $template->attach_crf,
            ],
            'status' => 'pending',
            'trigger' => AuditContext::trigger(),
        ]);

        SendNotificationJob::dispatch($log->id);

        return 1;
    }

    private function button(string $url, string $label): string
    {
        return '<a href="'.e($url).'" style="display:inline-block;padding:10px 18px;margin:6px 0;background:#d97706;color:#ffffff;'
            .'text-decoration:none;border-radius:6px;font-weight:bold;">'.e($label).'</a>';
    }
}
