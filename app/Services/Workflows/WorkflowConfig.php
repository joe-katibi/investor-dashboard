<?php

namespace App\Services\Workflows;

use App\Enums\NotificationEvent as E;
use App\Enums\QueueType as Q;
use App\Models\AuditLog;
use App\Models\CustomFlow;
use App\Models\CustomFlowStep;
use App\Models\DeskRoute;
use App\Models\MajorOutageType;
use App\Models\NotificationRule;
use App\Models\QueueDefinition;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Services\Sla\DeskSla;
use App\Support\Permissions;
use App\Support\Settings;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Workflow configuration, stage 1: every flow of the system laid out step by step, with what can
 * be adjusted from the screen —
 *
 *   per step   who works it (department, team, automatic FIFO or manual), how long they have to
 *              accept, when it is escalated, and the desk's SLA target
 *   per flow   the switches that add or drop a step or change how it behaves
 *
 * Nothing here is a second copy of the configuration: a step's settings are the FIFO queue of that
 * desk and the desk's SLA target; a switch is an existing setting or a flag of a major outage type.
 * A change applies to tickets reaching a step from then on — a ticket keeps what was in force when
 * it passed (its queue entries, targets and history are never rewritten).
 */
class WorkflowConfig
{
    /** Settings a flow's switches may write (nothing else is accepted from the screen). */
    private const SETTINGS = [
        'desk_sla.field_minutes', 'photos.outage_required', 'photos.maintenance_required', 'maintenance.reapprove_on_reschedule',
        'maintenance.sign_link_days', 'mobility.vendor_silent_minutes', 'mobility.vendor_escalation_minutes', 'mobility.vendor_reminder_mail',
        'ticketing.followup_enabled', 'ticketing.followup_minutes', 'ticketing.sla_minutes', 'ticketing.confirm_closure',
        'ticketing.require_evidence', 'ticketing.max_daily_visits', 'ticketing.dispatch_target_minutes',
        'ticketing.mobility_update_minutes', 'ticketing.mobility_vendor_reminder_minutes',
    ];

    public function __construct(
        private readonly Settings $settings,
        private readonly DeskSla $deskSla,
        private readonly FifoAssignmentEngine $fifo,
    ) {}

    /** @return array<string, array{label: string, description: string}> */
    public function flows(): array
    {
        return Arr::map($this->catalogue(), fn (array $flow) => Arr::only($flow, ['label', 'description']));
    }

    public function has(string $flow): bool
    {
        return isset($this->catalogue()[$flow]);
    }

    /**
     * The steps of a flow, in order, with their configuration as it stands.
     *
     * @return list<array<string, mixed>>
     */
    public function steps(string $flow): array
    {
        $targets = $this->deskSla->targets();
        $rules = NotificationRule::query()->active()->toBase()->selectRaw('event, count(*) as aggregate')->groupBy('event')->pluck('aggregate', 'event');
        $steps = [];

        foreach ($this->catalogue()[$flow]['steps'] ?? [] as $i => $step) {
            /** @var ?Q $desk */
            $desk = $step['desk'] ?? null;
            $definition = $desk ? $this->queueOf($desk) : null;
            $permission = $definition?->permission() ?? $desk?->defaultPermission() ?? ($step['permission'] ?? null);

            $steps[] = [
                'number' => $i + 1,
                'name' => $step['name'],
                'what' => $step['what'],
                'kind' => $step['kind'] ?? 'step',
                'desk' => $desk?->value,
                'desk_label' => $desk?->getLabel(),
                'permission' => $permission,
                'permission_label' => $permission ? Permissions::label($permission) : null,
                'roles' => $permission ? Role::query()->whereHas('permissions', fn ($q) => $q->where('name', $permission))->orderBy('name')->pluck('name')->all() : [],
                'people' => $permission ? $this->peopleWith($permission) : 0,
                'department' => $definition?->department?->name,
                'team' => $definition?->team?->name,
                'mode' => $desk ? ($definition?->isManual() ? 'Manual (a manager assigns)' : 'Automatic FIFO') : null,
                'ack' => $desk ? ($definition?->ack_timeout_minutes ?? $this->settings->int('fifo.ack_timeout_minutes')) : null,
                'attempts' => $desk ? ($definition?->escalate_after_attempts ?: $this->settings->int('fifo.escalate_after_attempts')) : null,
                'target' => $desk ? ($targets[$desk->value] ?? 0) : null,
                'more_queues' => $desk ? max(0, QueueDefinition::query()->active()->where('queue_type', $desk->value)->count() - 1) : 0,
                'events' => array_map(fn (E $event) => ['label' => $event->getLabel(), 'rules' => (int) ($rules[$event->value] ?? 0)], $step['events'] ?? []),
            ];
        }

        return $steps;
    }

    /** What the "Change" form of a step starts with. */
    public function stepForm(Q $desk): array
    {
        $definition = $this->queueOf($desk);

        return [
            'assignment_mode' => $definition?->assignment_mode ?? 'auto',
            'department_id' => $definition?->department_id,
            'team_id' => $definition?->team_id,
            'ack_timeout_minutes' => $definition?->ack_timeout_minutes,
            'escalate_after_attempts' => $definition?->escalate_after_attempts ?? 3,
            'target_minutes' => $this->deskSla->minutesFor($desk),
        ];
    }

    /**
     * Change who works a step and how long they have. Applies to tickets reaching the step from now on.
     *
     * @param  array{assignment_mode?: string, department_id?: mixed, team_id?: mixed, ack_timeout_minutes?: mixed, escalate_after_attempts?: mixed, target_minutes?: mixed}  $data
     */
    public function updateStep(Q $desk, array $data, User $by): void
    {
        $mode = $data['assignment_mode'] ?? 'auto';

        if (! in_array($mode, ['auto', 'manual'], true)) {
            throw ValidationException::withMessages(['assignment_mode' => 'Choose automatic FIFO or manual assignment.']);
        }

        $whole = fn (string $key, bool $nullable = false) => ($nullable && blank($data[$key] ?? null)) ? null : max(0, (int) ($data[$key] ?? 0));

        $definition = $this->queueOf($desk) ?? new QueueDefinition([
            'name' => $desk->getLabel().' Queue', 'queue_type' => $desk, 'required_permission' => $desk->defaultPermission(), 'is_active' => true,
        ]);

        $definition->fill([
            'assignment_mode' => $mode,
            'department_id' => filled($data['department_id'] ?? null) ? (int) $data['department_id'] : null,
            'team_id' => filled($data['team_id'] ?? null) ? (int) $data['team_id'] : null,
            'ack_timeout_minutes' => $whole('ack_timeout_minutes', nullable: true),
            'escalate_after_attempts' => $whole('escalate_after_attempts'),
        ])->save();

        $target = $whole('target_minutes');

        if ($target !== $this->deskSla->minutesFor($desk)) {
            $this->settings->set(['desk_sla.minutes' => [$desk->value => $target] + $this->deskSla->targets()], $by->id);
        }

        // People who became eligible may be given what is waiting at this step.
        $this->fifo->process($desk);
    }

    /**
     * The switches of a flow with their current values.
     *
     * @return list<array{key: string, label: string, help: ?string, type: string, value: mixed}>
     */
    public function switches(string $flow): array
    {
        $switches = [];

        foreach ($this->catalogue()[$flow]['switches'] ?? [] as $switch) {
            [$key, $label, $help] = [$switch[0], $switch[1], $switch[2] ?? null];
            $default = Settings::DEFAULTS[$key] ?? null;

            $switches[] = [
                'key' => $key, 'label' => $label, 'help' => $help,
                'type' => is_bool($default) ? 'bool' : 'int',
                'value' => is_bool($default) ? $this->settings->bool($key) : $this->settings->int($key),
            ];
        }

        // Which steps an outage of each major type goes through.
        foreach ($this->catalogue()[$flow]['major_flags'] ?? [] as $flag => [$label, $help]) {
            foreach (MajorOutageType::query()->active()->whereIn('code', $this->catalogue()[$flow]['major_types'])->orderBy('sort_order')->get() as $type) {
                $switches[] = [
                    'key' => "major.{$type->id}.{$flag}", 'label' => "{$type->name}: {$label}", 'help' => $help, 'type' => 'bool', 'value' => (bool) $type->{$flag},
                ];
            }
        }

        return $switches;
    }

    /** @param array<string, mixed> $values switch key => value, as {@see switches()} lists them */
    public function saveSwitches(string $flow, array $values, User $by): void
    {
        $allowed = collect($this->switches($flow))->keyBy('key');
        $settings = [];

        foreach ($values as $key => $value) {
            $switch = $allowed->get($key);

            if (! $switch) {
                continue;
            }

            $value = $switch['type'] === 'bool' ? (bool) $value : max(0, (int) $value);

            if (str_starts_with($key, 'major.')) {
                [, $id, $flag] = explode('.', $key, 3);
                MajorOutageType::query()->findOrFail((int) $id)->forceFill([$flag => $value])->save();
            } elseif (in_array($key, self::SETTINGS, true) && $value !== $switch['value']) {
                $settings[$key] = $value;
            }
        }

        if ($settings !== []) {
            $this->settings->set($settings, $by->id);
        }
    }

    /**
     * The latest changes to the workflow configuration, newest first: who changed what and when.
     *
     * @return Collection<int, array{at: string, by: string, what: string, changes: list<string>}>
     */
    public function history(int $limit = 15): Collection
    {
        $morph = fn (string $class) => (new $class)->getMorphClass();
        $settingIds = Setting::query()->whereIn('key', [...self::SETTINGS, 'desk_sla.minutes', 'desk_sla.enabled', 'desk_sla.warn_percent'])->pluck('key', 'id');

        return AuditLog::query()
            ->where(fn ($q) => $q
                ->whereIn('auditable_type', [$morph(QueueDefinition::class), $morph(MajorOutageType::class), $morph(DeskRoute::class), $morph(CustomFlow::class), $morph(CustomFlowStep::class)])
                ->orWhere(fn ($setting) => $setting->where('auditable_type', $morph(Setting::class))->whereIn('auditable_id', $settingIds->keys()->all() ?: [0]))
                ->orWhere(fn ($service) => $service->where('auditable_type', $morph(Service::class))->where('event', 'updated')))
            ->with('user:id,name')
            ->latest('id')
            ->limit($limit * 3)
            ->get()
            // A service is only workflow configuration when its reviewing team changed.
            ->filter(fn (AuditLog $log) => $log->auditable_type !== $morph(Service::class) || array_key_exists('review_team_id', $log->new_values ?? []))
            ->take($limit)
            ->map(fn (AuditLog $log) => [
                'at' => $log->created_at->format('d M Y H:i'),
                'by' => $log->user?->name ?? 'System',
                'what' => match ($log->auditable_type) {
                    $morph(Setting::class) => 'Setting '.($settingIds[$log->auditable_id] ?? '#'.$log->auditable_id),
                    $morph(QueueDefinition::class) => 'Step / queue #'.$log->auditable_id,
                    $morph(MajorOutageType::class) => 'Major outage type #'.$log->auditable_id,
                    $morph(DeskRoute::class) => 'Desk route #'.$log->auditable_id,
                    $morph(CustomFlow::class) => 'Custom workflow #'.$log->auditable_id,
                    $morph(CustomFlowStep::class) => 'Step of a custom workflow #'.$log->auditable_id,
                    default => 'Affected service #'.$log->auditable_id,
                }.' — '.$log->eventLabel(),
                'changes' => array_slice($log->changeLines(), 0, 6),
            ])
            ->values();
    }

    // ------------------------------------------------------------------ internals

    /** The general queue of a desk (the one without a major type / vertical matcher). */
    private function queueOf(Q $desk): ?QueueDefinition
    {
        return QueueDefinition::query()->active()->where('queue_type', $desk->value)->with(['department:id,name', 'team:id,name'])->get()
            ->sortBy(fn (QueueDefinition $definition) => [($definition->major_outage_type_id ? 2 : 0) + ($definition->vertical_id ? 1 : 0), $definition->sort_order, $definition->id])
            ->first();
    }

    private function peopleWith(string $permission): int
    {
        try {
            return User::permission($permission)->where('is_active', true)->count();
        } catch (\Throwable) {
            return 0; // the permission does not exist on this installation yet
        }
    }

    /**
     * The flows as the code runs them. A step with a desk is a FIFO queue; one without is done by
     * whoever holds the permission named.
     *
     * @return array<string, array<string, mixed>>
     */
    private function catalogue(): array
    {
        $step = fn (?Q $desk, string $name, string $what, array $events = [], ?string $permission = null, string $kind = 'step') => compact('desk', 'name', 'what', 'events', 'permission', 'kind');

        return [
            'outage' => [
                'label' => 'Network outage',
                'description' => 'FTTH and core outages: reported, confirmed by the NOC, dispatched to a field team, restored, confirmed and resolved.',
                'steps' => [
                    $step(null, 'Reported', 'A CX agent or the NOC reports the outage; priority and SLA are set from the impact.', [E::OutageCreated], 'outage.create'),
                    $step(Q::NocReview, 'NOC review', 'The NOC confirms it is an outage, or rejects it with a reason.', [E::NocEscalation, E::NocConfirmed, E::NocRejected]),
                    $step(Q::Infrastructure, 'Dispatch', 'A dispatcher sends a team and a technician.', [E::InfrastructureEscalation, E::Dispatched, E::FieldSlaPreBreach, E::FieldSlaBreach]),
                    $step(Q::Technician, 'Field work', 'The technician works on site, posts updates and reports the restoration with the site photos.', [E::Assigned, E::UpdatePosted, E::RestoreReported]),
                    $step(Q::SupportTechnician, 'Support technician', 'When asked for: drop cable / premises work, alongside the field work.', [E::SupportRequested, E::SupportCompleted], kind: 'alongside'),
                    $step(Q::NocRestore, 'NOC restore confirmation', 'The NOC confirms the restoration and resolves the ticket with its final reason.', [E::RestoreConfirmed, E::Resolved]),
                    $step(Q::CxFollowUp, 'Customer call-back', 'Only after a NOC reject: CX calls the customer back and raises a site visit if needed.', [E::CxFollowUp, E::SiteVisitRaised], kind: 'branch'),
                ],
                'switches' => [
                    ['desk_sla.field_minutes', 'On the field within (minutes)', 'Reported → dispatched to a technician; a warning before and a notice at the breach. 0 = off.'],
                    ['photos.outage_required', 'The three site photos are mandatory', 'Findings on ground, after restoration, power readings — to report the outage restored.'],
                ],
                'major_types' => [MajorOutageType::NETWORK, MajorOutageType::CORE],
                'major_flags' => [
                    'requires_noc_confirmation' => ['NOC confirms before it goes on', 'Off: the NOC review step is skipped and the outage goes straight to dispatch.'],
                    'requires_infrastructure' => ['worked by a field team', 'Off: no dispatch and technician steps; the NOC resolves it.'],
                ],
            ],
            'system' => [
                'label' => 'System outage',
                'description' => 'Business systems (Savbill, CPaaS, Grafana, Mail…): reviewed by IT or the system\'s own team, escalated to the NOC only for a core / network cause.',
                'steps' => [
                    $step(null, 'Reported', 'Anyone who may report outages picks the system under “Affected services”.', [E::OutageCreated], 'outage.create'),
                    $step(Q::ItReview, 'Review', 'IT — or the team named on the affected service — fixes it and resolves with feedback, or escalates to the NOC.', [E::ItEscalation, E::Resolved]),
                    $step(Q::NocReview, 'NOC (core / network cause)', 'Only when escalated: the NOC fixes the core / network side and returns the ticket.', [E::NocEscalation, E::ReturnedToIt], kind: 'branch'),
                    $step(Q::ItConfirmation, 'Confirmation', 'The reviewers confirm the system works and close the ticket, or send it back to the NOC.', [E::Resolved], kind: 'branch'),
                ],
                'major_types' => [MajorOutageType::SYSTEM],
                'major_flags' => [
                    'requires_it_review' => ['reviewed by IT / the system owners first', 'Off: system outages go to the NOC like network outages.'],
                ],
            ],
            'planned' => [
                'label' => 'Planned maintenance',
                'description' => 'Requested with a Maintenance & Change Request Form, signed off, carried out by the field team, confirmed, and customers told.',
                'steps' => [
                    $step(null, 'Requested', 'Infrastructure requests the maintenance window.', [E::MaintenanceRequested], 'maintenance.request'),
                    $step(null, 'CRF and sign-off', 'The NOC completes the form and sends it to the signatories; every signature is collected.', [E::CrfSignoffRequested, E::CrfSigned, E::CrfChangesRequested, E::CrfApproved], 'maintenance.manage'),
                    $step(Q::Infrastructure, 'Dispatch', 'Once fully signed, a dispatcher assigns the team.', [E::Dispatched, E::MaintenanceReminder]),
                    $step(Q::Technician, 'Field work', 'The work is done in the window; reschedules are recorded.', [E::MaintenanceStarted, E::MaintenanceRescheduled, E::RestoreReported]),
                    $step(Q::NocRestore, 'NOC confirmation', 'The NOC confirms the outcome.', [E::RestoreConfirmed, E::MaintenanceCompleted]),
                    $step(Q::CxNotify, 'Customers told', 'CX confirms that customers were told the work is finished; the ticket closes.', [E::MaintenanceCompleted]),
                ],
                'switches' => [
                    ['maintenance.reapprove_on_reschedule', 'Collect the signatures again when the window moves'],
                    ['maintenance.sign_link_days', 'Days a sign-off link stays valid'],
                ],
            ],
            'field_maintenance' => [
                'label' => 'Preventive / corrective maintenance',
                'description' => 'Raised on site by a technician with photos. Corrective work starts at once; preventive work waits for two approvals.',
                'steps' => [
                    $step(null, 'Raised on site', 'A technician raises it from the website or the mobile app, with the two “before” photos.', [E::CorrectiveRaised, E::PreventiveRequested], 'field_maintenance.raise'),
                    $step(null, 'Approval by the Dispatcher', 'Preventive only: the downtime is approved by a dispatcher…', [E::PreventiveApproved, E::PreventiveRejected], 'preventive.approve_dispatch', 'branch'),
                    $step(null, 'Approval by the Infrastructure Manager', '…and by the Infrastructure Manager (two different people).', [E::PreventiveApproved, E::PreventiveRejected], 'preventive.approve_manager', 'branch'),
                    $step(Q::Technician, 'Field work', 'The technician who raised it does the work and reports it completed with the “after” photos.', [E::RestoreReported]),
                    $step(Q::SupportTechnician, 'Support technician', 'When asked for: drop cable / premises work, alongside.', [E::SupportRequested, E::SupportCompleted], kind: 'alongside'),
                    $step(Q::NocRestore, 'NOC confirmation', 'The NOC confirms and resolves.', [E::RestoreConfirmed, E::Resolved]),
                ],
                'switches' => [
                    ['photos.maintenance_required', 'The “before” and “after” photos are mandatory'],
                ],
            ],
            'mobility' => [
                'label' => '4G outage (own engineers)',
                'description' => 'eNodeB, transmission and power outages worked by the 4G engineers and technicians; only the 4G NOC closes a ticket.',
                'steps' => [
                    $step(null, 'Reported', 'The 4G NOC raises the ticket; severity follows the impact rules.', [E::OutageCreated], 'mobility.create'),
                    $step(Q::MobilityEngineer, 'Engineer / technician', 'Transmission outages go to the engineers, eNodeB outages to the technicians; they work it and mark it resolved.', [E::Assigned, E::UpdatePosted, E::MobilityResolvedPending]),
                    $step(Q::MobilityNoc, '4G NOC verification', 'The NOC verifies the fix and closes the ticket, or sends it back.', [E::MobilityVerificationFailed, E::Resolved, E::MobilityReopened]),
                ],
            ],
            'mobility_vendor' => [
                'label' => '4G outage (vendor)',
                'description' => 'Outages worked by an external vendor: the vendor is notified by e-mail and the NOC records its updates.',
                'steps' => [
                    $step(null, 'Reported and vendor notified', 'The 4G NOC raises the ticket; the vendor gets the notification e-mail.', [E::OutageCreated, E::MobilityVendorNotified, E::MobilityVendorMailFailed], 'mobility.create'),
                    $step(null, 'With the vendor', 'The NOC records the vendor\'s updates; silence is chased and then escalated.', [E::MobilityVendorUpdate, E::MobilityVendorSilent, E::MobilityVendorEscalation], 'mobility.vendor_update'),
                    $step(Q::MobilityNoc, '4G NOC verification', 'The NOC verifies the fix and closes the ticket.', [E::MobilityResolvedPending, E::Resolved]),
                ],
                'switches' => [
                    ['mobility.vendor_silent_minutes', 'No vendor update for this long → remind the NOC (minutes)', '0 = off.'],
                    ['mobility.vendor_escalation_minutes', 'Still nothing → management escalation (minutes)', '0 = off.'],
                    ['mobility.vendor_reminder_mail', 'Also e-mail the vendor a reminder at the escalation'],
                ],
            ],
            'cx_ticket' => [
                'label' => 'Customer ticket',
                'description' => 'Raised by CX and worked desk by desk. The first desk comes from the issue (subcategory); desks hand over along the desk routes.',
                'steps' => [
                    $step(null, 'Raised', 'A CX agent raises the ticket; it goes to the first desk of its subcategory.', [E::OutageCreated], 'ticket.create'),
                    $step(Q::CxCallback, 'CX frontline follow-up', 'Customer call-backs (for example after a declined request).', [E::TicketReturned]),
                    $step(Q::CxTier2, 'CX Tier II', 'Troubleshooting and vetting; raises a site visit when needed.', [E::TicketRouted, E::SiteVisitRaised]),
                    $step(Q::Billing, 'Billing', 'Billing queries, payments and adjustments.', [E::TicketRouted]),
                    $step(Q::BillingManager, 'Billing Manager', 'Approves or declines billing requests.', [E::TicketApproval]),
                    $step(Q::Commercial, 'Commercial / Sales Admin', 'Assesses refunds.', [E::TicketApproval]),
                    $step(Q::CxManager, 'CX Manager', 'Approves credits.', [E::TicketApproval]),
                    $step(Q::TicketNoc, 'NOC (customer tickets)', 'Network troubleshooting for one customer.', [E::TicketRouted, E::TicketReturned]),
                    $step(Q::TicketFollowUp, 'Pending ticket follow-up', 'Tickets pending past the follow-up time: a call to the customer, alongside the work.', [E::TicketFollowUpDue, E::TicketCreditReady], kind: 'alongside'),
                ],
                'switches' => [
                    ['ticketing.sla_minutes', 'Ticket SLA when the issue has none of its own (minutes)'],
                    ['ticketing.followup_enabled', 'Queue tickets pending too long for a customer call'],
                    ['ticketing.followup_minutes', 'Pending this long → follow-up call and ready for credit (minutes)'],
                ],
            ],
            'cx_ticket_4g' => [
                'label' => '4G customer ticket',
                'description' => 'A customer ticket raised under the 4G vertical: CX Tier II, then the 4G NOC, then the IP, RF or Core team — who may call in their vendor — back to the NOC to validate, and to CX Tier II to confirm with the customer. An update is due every few minutes at every step.',
                'steps' => [
                    $step(null, 'Raised', 'A CX agent raises the ticket under the 4G vertical.', [E::OutageCreated], 'ticket.create'),
                    $step(Q::CxTier2, 'CX Tier II', 'Troubleshoots; resolves and closes after confirming with the customer, or escalates to the 4G NOC.', [E::TicketRouted]),
                    $step(Q::Ticket4gNoc, '4G NOC', 'Resolves, or escalates to the IP, RF or Core team; validates their fix — back to the team, or to CX Tier II.', [E::TicketRouted, E::TicketReturned]),
                    $step(Q::TicketIp, 'IP Team', 'Resolves (to the NOC to validate) or escalates to an IP vendor.', [E::TicketRouted, E::TicketVendorEscalated]),
                    $step(Q::TicketRf, 'RF Team', 'Resolves (to the NOC to validate) or escalates to an RF vendor.', [E::TicketRouted, E::TicketVendorEscalated]),
                    $step(Q::TicketCore, 'Core Team', 'Resolves (to the NOC to validate) or escalates to a core vendor.', [E::TicketRouted, E::TicketVendorEscalated]),
                ],
                'switches' => [
                    ['ticketing.mobility_update_minutes', 'An update is due on a 4G ticket every (minutes)', 'Whoever holds it is reminded when nothing has been posted for this long. 0 = off.'],
                    ['ticketing.mobility_vendor_reminder_minutes', 'Remind a vendor by e-mail when they have said nothing for (minutes)', '0 = off.'],
                ],
            ],
            'site_visit' => [
                'label' => 'Site visit',
                'description' => 'Booked, dispatched to a technician, completed with evidence, verified by the dispatcher and confirmed with the customer.',
                'steps' => [
                    $step(null, 'Booked', 'CX, a dispatcher or a customer ticket raises the visit.', [E::OutageCreated, E::SiteVisitRaised], 'visit.create'),
                    $step(Q::VisitDispatch, 'Dispatch and verification', 'The dispatcher assigns a technician; later verifies the completed work or sends it back.', [E::VisitScheduled, E::VisitRescheduled, E::VisitCompleted]),
                    $step(Q::ShiftingDispatch, 'Shifting dispatch and verification', 'Shifting / Relocation visits: dispatched and verified at their own desk, by the Shifting dispatchers.', [E::VisitScheduled, E::VisitRescheduled, E::VisitCompleted], kind: 'branch'),
                    $step(Q::VisitTechnician, 'Technician', 'En route, on premise, completed with the FAT number and photos — or not completed, with the reason.', [E::Assigned, E::VisitCompleted, E::TicketCustomerSms]),
                    $step(Q::CxFieldSupport, 'Confirmation with the customer', 'CX Field Support confirms with the customer and closes the visit.', [E::Resolved, E::TicketReopened]),
                    $step(Q::InfraSupportDispatch, 'Infrastructure support dispatch', 'Escalation: the visit needs infrastructure work.', [E::TicketRouted], kind: 'branch'),
                    $step(Q::InfraSupportTechnician, 'Infrastructure support technician', 'Escalation: the infrastructure technician does the work.', [E::TicketReturned], kind: 'branch'),
                    $step(Q::Design, 'Design', 'Escalation: a survey or design is needed.', [E::TicketRouted], kind: 'branch'),
                    $step(Q::Access, 'Access team', 'Escalation: access to the property has to be arranged.', [E::TicketRouted], kind: 'branch'),
                ],
                'switches' => [
                    ['ticketing.confirm_closure', 'CX Field Support confirms with the customer before the visit closes', 'Off: a visit closes when the dispatcher verifies it.'],
                    ['ticketing.require_evidence', 'FAT number, geolocation and speed-test photos are needed to complete a visit'],
                    ['ticketing.max_daily_visits', 'Visits per technician per day', '0 = no limit.'],
                    ['ticketing.dispatch_target_minutes', 'Target: reported → assigned (minutes)'],
                ],
            ],
        ];
    }
}
