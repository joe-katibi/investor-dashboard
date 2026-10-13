<?php

namespace App\Services\Mobile;

use App\Enums\AssignmentStatus;
use App\Enums\QueueType;
use App\Enums\StatusCode;
use App\Filament\Resources\MobilityOutages\MobilityActions;
use App\Filament\Resources\Outages\OutageActions;
use App\Filament\Resources\Tickets\TicketActions;
use App\Models\MaintenanceApproval;
use App\Models\Olt;
use App\Models\Outage;
use App\Models\OutageAssignment;
use App\Models\OutageAttachment;
use App\Models\OutagePhoto;
use App\Models\OutageStatus;
use App\Models\OutageType;
use App\Models\Team;
use App\Models\TicketFollowUp;
use App\Models\User;
use App\Models\Vertical;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Services\Outages\OutageWorkflow;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * What a person may do to a ticket from the mobile app — decided here, never on the phone.
 *
 * The app asks for a ticket and gets back the list of actions this person may take on it right now,
 * each with the form it needs (fields, options, what is mandatory). It draws those buttons and
 * forms and posts the answers back; every action then runs through the same policy and the same
 * OutageWorkflow / FIFO engine as the web screens. A new rule added there applies to the app at
 * once, with no app update.
 *
 * Field types the app understands: text, textarea, number, select, datetime, toggle, photo (one
 * image), photos (several images). "depends_on" filters a select's options to those whose "group"
 * equals the value of another field.
 */
final class MobileActions
{
    public function __construct(
        private readonly OutageWorkflow $workflow,
        private readonly FifoAssignmentEngine $fifo,
    ) {}

    /**
     * The actions this person can take on the ticket, in the order they are shown.
     *
     * @return list<array<string, mixed>>
     */
    public function for(User $user, Outage $outage): array
    {
        $actions = [];

        foreach ($this->definitions($user, $outage) as $key => $definition) {
            if (! $definition['visible']) {
                continue;
            }

            $actions[] = [
                'key' => $key,
                'label' => $definition['label'],
                'style' => $definition['style'] ?? 'neutral',
                'primary' => (bool) ($definition['primary'] ?? false),
                'description' => $definition['description'] ?? null,
                'confirm' => (bool) ($definition['confirm'] ?? false),
                'disabled_reason' => $definition['disabled_reason'] ?? null,
                'fields' => array_values(($definition['fields'] ?? fn () => [])()),
            ];
        }

        return $actions;
    }

    /**
     * Run one action. Whether it is allowed is checked again here — and once more inside the
     * workflow — whatever the app showed.
     *
     * @return string the message to show
     */
    public function run(User $user, Outage $outage, string $key, Request $request): string
    {
        $definition = $this->definitions($user, $outage)[$key] ?? null;

        if (! $definition || ! $definition['visible']) {
            throw ValidationException::withMessages(['action' => 'This action is no longer available on this ticket. Refresh and try again.']);
        }

        if (! empty($definition['disabled_reason'])) {
            throw ValidationException::withMessages(['action' => $definition['disabled_reason']]);
        }

        $fields = array_values(($definition['fields'] ?? fn () => [])());
        $data = $this->validated($request, $fields);

        return ($definition['handle'])($data) ?? ($definition['success'] ?? 'Done');
    }

    // ------------------------------------------------------------------ not about one ticket

    /**
     * What this person can start from the app without a ticket in front of them: raise corrective
     * or preventive maintenance on site (the same as "Raise field maintenance" on the website).
     *
     * @return list<array<string, mixed>>
     */
    public function general(User $user): array
    {
        $actions = [];

        foreach ($this->generalDefinitions($user) as $key => $definition) {
            if ($definition['visible']) {
                $actions[] = [
                    'key' => $key, 'label' => $definition['label'], 'style' => $definition['style'] ?? 'neutral', 'primary' => false,
                    'description' => $definition['description'] ?? null, 'confirm' => false, 'disabled_reason' => null,
                    'fields' => array_values($definition['fields']()),
                ];
            }
        }

        return $actions;
    }

    /**
     * @return array{0: string, 1: ?Outage} the message to show and the ticket that was created
     */
    public function runGeneral(User $user, string $key, Request $request): array
    {
        $definition = $this->generalDefinitions($user)[$key] ?? null;

        if (! $definition || ! $definition['visible']) {
            throw ValidationException::withMessages(['action' => 'This is not available to you. Refresh and try again.']);
        }

        return ($definition['handle'])($this->validated($request, array_values($definition['fields']())));
    }

    /** @return array<string, array<string, mixed>> */
    private function generalDefinitions(User $user): array
    {
        $mayRaise = $user->can('raiseFieldMaintenance', Outage::class);
        $photosRequired = app(Settings::class)->bool('photos.maintenance_required');

        $where = fn (): array => [
            $this->text('site_label', 'FAT / FDT label', required: true, max: 120),
            $this->text('location', 'Location / area'),
            $this->select('olt_id', 'OLT', $this->options(Olt::query()->active()->orderBy('name')->pluck('name', 'id')), placeholder: 'Not known'),
            $this->number('customers_affected', 'Customers affected (estimate)', max: 10_000_000),
        ];
        $photos = fn (): array => [
            $this->photo('photo_outside', 'Photo before the work — outside', OutagePhoto::POSITIONS['outside'], required: $photosRequired),
            $this->photo('photo_inside', 'Photo before the work — inside', OutagePhoto::POSITIONS['inside'], required: $photosRequired),
        ];

        $raise = function (string $kind, array $data) use ($user): array {
            $outage = $this->workflow->raiseFieldMaintenance([
                'kind' => $kind,
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'site_label' => $data['site_label'] ?? null,
                'location' => $data['location'] ?? null,
                'vertical_id' => Vertical::query()->where('code', Vertical::FTTH)->value('id'),
                'olt_ids' => array_filter([$this->id($data['olt_id'] ?? null)]),
                'customers_affected' => (int) ($data['customers_affected'] ?? 0),
                'downtime_start' => $data['downtime_start'] ?? null,
                'downtime_end' => $data['downtime_end'] ?? null,
                'photos' => $this->cameraPhotos($data, ['outside' => 'photo_outside', 'inside' => 'photo_inside']),
                'support' => filled($data['support_work'] ?? null)
                    ? ['work_required' => $data['support_work'], 'customer_details' => $data['support_customers'] ?? null]
                    : [],
            ], $user);

            return [$kind === OutageType::PREVENTIVE
                ? "{$outage->ticket_number} sent for Dispatcher and Infrastructure Manager approval."
                : "{$outage->ticket_number} raised and assigned to you. Everyone concerned has been notified.", $outage];
        };

        return [
            'raise_corrective' => [
                'visible' => $mayRaise,
                'label' => 'Raise corrective maintenance',
                'style' => 'danger',
                'description' => 'Something is broken (a fallen pole, a dropped cable): the work starts now and the ticket is yours.',
                'fields' => fn (): array => [
                    $this->text('title', 'Title', required: true),
                    $this->textarea('description', 'What happened / work needed', required: true, max: 5000),
                    ...$where(),
                    ...$photos(),
                    $this->textarea('support_work', 'Support technician — work required (leave empty if none is needed)'),
                    $this->text('support_customers', 'Support technician — customer(s): name / account / phone'),
                ],
                'handle' => fn (array $data): array => $raise(OutageType::CORRECTIVE, $data),
            ],
            'raise_preventive' => [
                'visible' => $mayRaise,
                'label' => 'Raise preventive maintenance',
                'style' => 'warning',
                'description' => 'An issue found on site that needs a planned downtime: the Dispatcher and the Infrastructure Manager approve it first.',
                'fields' => fn (): array => [
                    $this->text('title', 'Title', required: true),
                    $this->textarea('description', 'Issue found on site', required: true, max: 5000),
                    ...$where(),
                    $this->datetime('downtime_start', 'Proposed downtime — start', required: true, future: true),
                    $this->datetime('downtime_end', 'Proposed downtime — end', required: true, future: true),
                    ...$photos(),
                ],
                'handle' => fn (array $data): array => $raise(OutageType::PREVENTIVE, $data),
            ],
        ];
    }

    // ------------------------------------------------------------------ the catalogue

    /** @return array<string, array<string, mixed>> */
    private function definitions(User $user, Outage $outage): array
    {
        $assignment = $this->myAssignment($user, $outage);
        // Field steps belong to the person holding the ticket (or someone who may override FIFO).
        $holding = $assignment !== null || $user->can('queue.override');
        $can = fn (string $ability) => $user->can($ability, $outage);
        // Custom workflow: the flow, its steps and the one the ticket is at.
        $custom = $outage->isCustomFlow() ? $outage->customTicket : null;
        $ticket = $outage->isTicket() ? $outage->ticket : null;
        $approvalSlot = $can('approvePreventive') ? MaintenanceApproval::openSlotFor($outage, $user) : null;
        // Emergency / Regular outage worked by a field team: position => photo on the ticket (or null).
        $sitePhotos = $outage->offersSitePhotos() ? $outage->sitePhotos() : null;
        // Mandatory, or offered but optional: FIFO & SLA Settings → Field photos.
        $photosRequired = $outage->requiresSitePhotos();
        $maintenancePhotosRequired = app(Settings::class)->bool('photos.maintenance_required');
        $missingPhotos = collect($sitePhotos ?? [])->filter(fn (?OutagePhoto $photo) => $photo === null)
            ->map(fn ($photo, string $position) => OutagePhoto::SITE_PHOTOS[$position][0])->all();

        return [
            // ---------------------------------------------------------- FIFO: my assignment
            'accept' => [
                'visible' => $assignment?->status === AssignmentStatus::Assigned,
                'label' => 'Accept',
                'style' => 'success',
                'primary' => true,
                'handle' => function () use ($assignment, $user): string {
                    $this->fifo->accept($assignment, $user);

                    return 'Ticket accepted';
                },
            ],
            'reject' => [
                'visible' => $assignment !== null,
                'label' => 'Reject assignment',
                'style' => 'neutral',
                'description' => 'The ticket goes back to the queue for the next available person.',
                'fields' => fn () => [$this->textarea('reason', 'Why can you not take this ticket?', required: true, max: 1000)],
                'handle' => function (array $data) use ($assignment, $user): string {
                    $this->fifo->reject($assignment, $user, $data['reason']);

                    return 'Ticket returned to the queue';
                },
            ],

            // ---------------------------------------------------------- FTTH outage: dispatcher
            'dispatch' => [
                'visible' => $can('dispatch'),
                'label' => $outage->dispatched_at ? 'Re-dispatch' : 'Dispatch',
                'style' => 'warning',
                'primary' => true,
                'description' => 'Pick the team. Leave the technician on automatic and the next eligible technician of that team gets it (FIFO).',
                'fields' => fn () => [
                    $this->select('team_id', 'Team', $this->options(OutageActions::teamOptions(null)), required: true, default: $outage->team_id, search: true),
                    $this->select('technician_id', 'Technician', $this->technicianOptions(), placeholder: 'Automatic — next eligible technician (FIFO)', dependsOn: 'team_id', search: true),
                    $this->textarea('note', 'Dispatch note', max: 2000),
                ],
                'handle' => function (array $data) use ($outage, $user): string {
                    $team = Team::query()->active()->findOrFail((int) $data['team_id']);

                    $this->workflow->dispatch($outage, [
                        'team_type' => $team->team_type?->value,
                        'team_id' => $team->id,
                        'sub_team_id' => null,
                        'technician_id' => $data['technician_id'] ?? null,
                        'note' => $data['note'] ?? null,
                    ], $user);

                    return 'Dispatched';
                },
            ],
            'approve_preventive' => [
                'visible' => $can('approvePreventive'),
                'label' => 'Approve downtime'.($approvalSlot ? ' ('.MaintenanceApproval::SLOTS[$approvalSlot][0].')' : ''),
                'style' => 'success',
                'primary' => true,
                'description' => 'Downtime '.($outage->downtime_start?->format('d M Y H:i') ?? '—').' – '.($outage->downtime_end?->format('d M Y H:i') ?? '—').'.',
                'fields' => fn () => [$this->textarea('comment', 'Comment (optional)', max: 2000)],
                'handle' => function (array $data) use ($outage, $user): string {
                    $this->workflow->approvePreventive($outage, $user, $data['comment'] ?? null);

                    return 'Downtime approved';
                },
            ],
            'reject_preventive' => [
                'visible' => $can('rejectPreventive'),
                'label' => 'Reject downtime',
                'style' => 'danger',
                'fields' => fn () => [$this->textarea('reason', 'Reason', required: true, max: 2000)],
                'handle' => function (array $data) use ($outage, $user): string {
                    $this->workflow->rejectPreventive($outage, $user, $data['reason']);

                    return 'Preventive maintenance rejected';
                },
            ],

            // ---------------------------------------------------------- FTTH outage: technician
            // Emergency / Regular outage: the three site photos, each taken with the camera when it
            // happens — the findings before the repair, the rest after it.
            'site_photos' => [
                'visible' => $sitePhotos !== null && $can('addSitePhotos') && $holding,
                'label' => 'Site photos ('.count(array_filter($sitePhotos ?? [])).' of '.count(OutagePhoto::SITE_PHOTOS).')',
                'style' => $missingPhotos === [] || ! $photosRequired ? 'neutral' : 'warning',
                'primary' => $missingPhotos !== [] && $photosRequired,
                'description' => match (true) {
                    $missingPhotos === [] => 'All three photos are on the ticket. Take one again only to replace it.',
                    $photosRequired => 'Still to take: '.implode(', ', $missingPhotos).'. The outage cannot be reported restored without all three.',
                    default => 'Photos of the work (optional). Still to take: '.implode(', ', $missingPhotos).'.',
                },
                'fields' => fn () => collect(OutagePhoto::SITE_PHOTOS)->map(fn (array $photo, string $position) => $this->photo(
                    'site_'.$position,
                    $photo[0],
                    ($sitePhotos[$position] ?? null) ? 'On the ticket — '.$sitePhotos[$position]->caption().'. Take it again only to replace it.' : $photo[1],
                ))->values()->all(),
                'handle' => function (array $data) use ($outage, $user): string {
                    $photos = $this->cameraPhotos($data, $this->sitePhotoFields());

                    if ($photos === []) {
                        throw ValidationException::withMessages(['photos' => 'Take at least one photo.']);
                    }

                    $left = $this->workflow->addSitePhotos($outage, $user, $photos)->missingSitePhotos();

                    return $left === [] ? 'Saved — all three photos are on the ticket' : 'Saved — still to take: '.implode(', ', $left);
                },
            ],
            'report_restored' => [
                'visible' => $can('reportRestored') && $holding,
                'label' => $outage->maintenance_kind ? 'Report work completed' : 'Report restored',
                'style' => 'success',
                'primary' => true,
                'description' => $missingPhotos === [] || ! $photosRequired
                    ? 'The NOC then confirms the restoration.'
                    : 'Needs the three site photos — still to take: '.implode(', ', $missingPhotos).'. The NOC then confirms the restoration.',
                'disabled_reason' => $outage->openSupportRequest !== null
                    ? 'Waiting for the support technician to finish the drop cable / premises work (or for the request to be cancelled).'
                    : null,
                'fields' => fn () => [
                    $this->datetime('restored_at', 'Restored at', default: now()->startOfMinute()),
                    $this->textarea('note', $outage->maintenance_kind ? 'Work done' : 'Restoration details', required: true, max: 5000),
                    // Field maintenance closes with two photos of the finished work.
                    ...($outage->maintenance_kind ? [
                        $this->photo('after_outside', 'After — outside', OutagePhoto::POSITIONS['outside'], required: $maintenancePhotosRequired),
                        $this->photo('after_inside', 'After — inside', OutagePhoto::POSITIONS['inside'], required: $maintenancePhotosRequired),
                    ] : []),
                    // Emergency / Regular outage: whichever site photos are not on the ticket yet.
                    ...collect($missingPhotos)->map(fn (string $label, string $position) => $this->photo(
                        'site_'.$position, $label, OutagePhoto::SITE_PHOTOS[$position][1], required: $photosRequired,
                    ))->values()->all(),
                ],
                'handle' => function (array $data) use ($outage, $user): string {
                    $photos = $this->cameraPhotos($data, $outage->maintenance_kind ? ['outside' => 'after_outside', 'inside' => 'after_inside'] : $this->sitePhotoFields());

                    $this->workflow->reportRestored($outage, $user, $data['note'], $data['restored_at'] ?? null, $photos);

                    return 'Reported — awaiting NOC confirmation';
                },
            ],
            'request_support' => [
                'visible' => $can('requestSupport') && $holding,
                'label' => 'Request support technician',
                'style' => 'warning',
                'description' => 'A support technician (drop cable to the customer premises) works on this ticket at the same time as you.',
                'fields' => fn () => [
                    $this->textarea('work_required', 'Work required on site', required: true, max: 2000),
                    $this->text('customer_details', 'Customer(s) — name / account / phone', max: 255),
                    $this->text('location', 'Premises location', max: 255, default: $outage->location),
                ],
                'handle' => function (array $data) use ($outage, $user): string {
                    $this->workflow->requestSupport($outage, $user, $data);

                    return 'Support technician requested';
                },
            ],
            'complete_support' => [
                'visible' => $can('completeSupport') && $holding,
                'label' => 'Support work done',
                'style' => 'success',
                'primary' => true,
                'description' => 'Requested: '.($outage->openSupportRequest?->work_required ?? '—'),
                'fields' => fn () => [$this->textarea('note', 'Drop cable / premises work done', required: true, max: 5000)],
                'handle' => function (array $data) use ($outage, $user): string {
                    $this->workflow->completeSupport($outage, $user, $data['note']);

                    return 'Support work completed';
                },
            ],
            'cancel_support' => [
                'visible' => $can('cancelSupport'),
                'label' => 'Cancel support request',
                'description' => 'Requested: '.($outage->openSupportRequest?->work_required ?? '—'),
                'fields' => fn () => [$this->textarea('reason', 'Why is the support technician no longer needed?', required: true)],
                'handle' => function (array $data) use ($outage, $user): string {
                    $this->workflow->cancelSupport($outage, $user, $data['reason']);

                    return 'Support request cancelled';
                },
            ],
            'set_status' => [
                'visible' => $can('changeStatus') && $holding,
                'label' => 'Set status',
                'fields' => fn () => [
                    $this->select('status_id', 'Status', $this->options(
                        OutageStatus::query()->active()->standard()->where('is_manual_selectable', true)->orderBy('sort_order')->pluck('name', 'id')->all()
                    ), required: true),
                    $this->textarea('reason', 'Note', max: 5000),
                ],
                'handle' => function (array $data) use ($outage, $user): string {
                    $this->workflow->changeStatus($outage, OutageStatus::query()->findOrFail((int) $data['status_id']), $user, $data['reason'] ?? null);

                    return 'Status updated';
                },
            ],

            // ---------------------------------------------------------- 4G: engineer (transmission) / technician (eNodeB)
            'mobility_start' => [
                'visible' => $can('mobilityStart') && $holding,
                'label' => 'Start investigation',
                'style' => 'primary',
                'primary' => true,
                'fields' => fn () => [$this->textarea('note', 'Note (optional)', max: 2000)],
                'handle' => function (array $data) use ($outage, $user): string {
                    $this->workflow->startMobilityWork($outage, $user, $data['note'] ?? null);

                    return 'Investigation started';
                },
            ],
            'mobility_resolve' => [
                'visible' => $can('mobilityResolve') && $holding,
                'label' => 'Mark resolved',
                'style' => 'success',
                'primary' => true,
                'description' => 'The ticket returns to the 4G NOC, who verify the service and close it.',
                'fields' => fn () => [
                    $this->datetime('restored_at', 'Restoration time', default: now()->startOfMinute()),
                    $this->textarea('root_cause', 'Root cause', required: true, max: 5000, default: $outage->root_cause),
                    $this->textarea('resolution', 'Resolution / work done', required: true, max: 5000, default: $outage->resolution),
                ],
                'handle' => function (array $data) use ($outage, $user): string {
                    $this->workflow->markMobilityResolved($outage, $user, $data);

                    return 'Resolved — sent to the NOC to confirm';
                },
            ],
            'mobility_status' => [
                'visible' => $can('mobilitySetStatus') && $holding && MobilityActions::statusOptions($outage) !== [],
                'label' => 'Set status',
                'fields' => fn () => [
                    $this->select('status', 'Status', $this->options(MobilityActions::statusOptions($outage)), required: true),
                    $this->textarea('note', 'Note', max: 2000),
                ],
                'handle' => function (array $data) use ($outage, $user): string {
                    $this->workflow->setMobilityStatus($outage, StatusCode::from((string) $data['status']), $user, $data['note'] ?? null);

                    return 'Status updated';
                },
            ],
            'mobility_attach' => [
                'visible' => $can('mobilityAttach') && $holding,
                'label' => 'Add photos',
                'fields' => fn () => [
                    $this->photos('files', 'Photos', required: true),
                    $this->text('note', 'Note (optional)', max: 255),
                ],
                'handle' => function (array $data) use ($outage, $user): string {
                    [$paths, $names] = $this->storeMany((array) $data['files'], OutageAttachment::DIRECTORY);
                    $this->workflow->addMobilityAttachments($outage, $user, $paths, $names, $data['note'] ?? null);

                    return 'Photos attached';
                },
            ],

            // ---------------------------------------------------------- site visit: dispatcher
            'visit_assign' => [
                'visible' => $can('visitAssign'),
                'label' => $outage->current_queue === QueueType::VisitTechnician ? 'Reassign technician' : 'Assign technician',
                'style' => 'warning',
                'primary' => $outage->current_queue === QueueType::VisitDispatch && $ticket?->completed_at === null,
                'description' => 'Pick a technician, or leave it on automatic: the visit goes to the technician queue (FIFO). The customer is texted the technician\'s name and number.',
                'fields' => fn () => [
                    $this->select('technician_id', 'Technician', $this->options(TicketActions::deskUsers(QueueType::VisitTechnician)), placeholder: 'Automatic — next available technician (FIFO)', search: true),
                    $this->select('team_id', 'Only offer it to this team (optional)', $this->options(TicketActions::teams()), default: $outage->team_id, search: true),
                    $this->datetime('scheduled_at', 'Visit date / time', help: 'Leave empty for “today, as soon as possible”.', default: $ticket?->scheduled_at, future: true),
                    $this->textarea('note', 'Instructions (optional)', max: 2000),
                ],
                'handle' => function (array $data) use ($outage, $user): string {
                    $this->workflow->assignVisit($outage, $user, $data);

                    return filled($data['technician_id'] ?? null) ? 'Technician assigned — customer texted' : 'Placed in the technician queue (FIFO)';
                },
            ],
            'visit_verify' => [
                'visible' => $can('visitVerify'),
                'label' => 'Verify & close',
                'style' => 'success',
                'primary' => true,
                'description' => 'Your final close of the completed work.',
                'fields' => fn () => [
                    ...(TicketActions::late($outage) ? [
                        $this->select('delay_reason_id', 'Reason for delay', $this->options(TicketActions::delayReasons()), required: true,
                            help: 'This visit is past its SLA: a reason for the delay is required.'),
                    ] : []),
                    $this->textarea('note', 'Verification note (optional)', max: 2000),
                ],
                'handle' => function (array $data) use ($outage, $user): string {
                    $this->workflow->verifyVisit($outage, $user, $data['note'] ?? null, $this->id($data['delay_reason_id'] ?? null));

                    return 'Verified';
                },
            ],
            'visit_reject_completion' => [
                'visible' => $can('visitVerify'),
                'label' => 'Not done — send back',
                'style' => 'neutral',
                'description' => 'The completion is withdrawn and the visit goes back to the technician who did it.',
                'fields' => fn () => [$this->textarea('reason', 'What is not right?', required: true, max: 2000)],
                'handle' => function (array $data) use ($outage, $user): string {
                    $this->workflow->rejectVisitCompletion($outage, $user, $data['reason']);

                    return 'Sent back to the technician';
                },
            ],
            'visit_reschedule' => [
                'visible' => $can('visitReschedule'),
                'label' => 'Reschedule',
                'description' => 'Sets the date / time the customer asked for.',
                'fields' => fn () => [
                    $this->datetime('scheduled_at', 'New date / time', required: true, future: true),
                    $this->textarea('reason', 'Reason', required: true, max: 2000),
                    $this->toggle('notify', 'Text the customer the new time', default: true),
                ],
                'handle' => function (array $data) use ($outage, $user): string {
                    $this->workflow->rescheduleVisit($outage, $user, $data['scheduled_at'], $data['reason'], (bool) ($data['notify'] ?? true));

                    return 'Visit rescheduled';
                },
            ],

            // ---------------------------------------------------------- site visit: technician
            'visit_en_route' => [
                'visible' => $can('visitEnRoute') && $holding,
                'label' => 'En route',
                'style' => 'primary',
                'primary' => true,
                'confirm' => true,
                'description' => 'Tell the dispatcher you are on the way to the customer.',
                'handle' => function () use ($outage, $user): string {
                    $this->workflow->visitEnRoute($outage, $user);

                    return 'On the way';
                },
            ],
            'visit_arrive' => [
                'visible' => $can('visitArrive') && $holding,
                'label' => 'Start at customer premise',
                'style' => 'primary',
                'primary' => $ticket?->en_route_at !== null,
                'confirm' => true,
                'description' => 'Starts the on-premise timer.',
                'handle' => function () use ($outage, $user): string {
                    $this->workflow->visitArrive($outage, $user);

                    return 'Arrived — timer started';
                },
            ],
            'visit_resume' => [
                'visible' => $can('visitResume') && $holding,
                'label' => 'Resume work',
                'style' => 'primary',
                'primary' => true,
                'confirm' => true,
                'handle' => function () use ($outage, $user): string {
                    $this->workflow->visitResume($outage, $user);

                    return 'Work resumed';
                },
            ],
            'visit_complete' => [
                'visible' => $can('visitComplete') && $holding,
                'label' => 'Complete visit',
                'style' => 'success',
                'primary' => $ticket?->arrived_at !== null && $ticket?->paused_at === null,
                'description' => $outage->isSiteVisit() && TicketActions::needsEvidence($outage)
                    ? 'The FAT number, a geolocation photo and a speed-test photo are required. The visit returns to the dispatcher for the final close.'
                    : 'The visit returns to the dispatcher for the final close.',
                'fields' => function () use ($outage, $ticket): array {
                    $evidence = TicketActions::needsEvidence($outage);

                    return [
                        $this->select('final_reason_id', 'Final reason', $this->options(TicketActions::finalReasons('visit', $outage->current_queue)), required: true),
                        $this->text('fat_number', 'FAT number', required: $evidence, max: 60, default: $ticket?->fat_number),
                        // Taken at the phone's position: the place, the coordinates and the time are stamped on it and saved with it.
                        $this->photo('geo_photo', 'Geolocation photo', 'Taken at the customer premise: your position, the place and the time are stamped on the photo.',
                            required: $evidence && ! $outage->attachments()->where('kind', 'geo_photo')->exists(), location: true),
                        $this->photo('speed_photo', 'Speed test photo', 'Photo of the speed test result after the fix.',
                            required: $evidence && ! $outage->attachments()->where('kind', 'speed_test')->exists()),
                        $this->textarea('resolution_notes', 'Work done', required: true, max: 5000),
                        $this->text('onu_type', 'ONU type used', max: 60),
                        $this->number('drop_cable_m', 'Drop cable (m)', max: 100000),
                        $this->number('ethernet_m', 'Ethernet cable (m)', max: 100000),
                        $this->text('patch_cord', 'Patch cord', max: 60),
                        $this->text('atb', 'ATB', max: 60),
                        $this->text('extender', 'Extender', max: 60),
                        $this->datetime('completed_at', 'Completed at', default: now()->startOfMinute()),
                    ];
                },
                'handle' => function (array $data) use ($outage, $user): string {
                    foreach (['geo_photo', 'speed_photo'] as $field) {
                        if (($data[$field] ?? null) instanceof UploadedFile) {
                            [$data[$field], $data[$field.'_name']] = $this->store($data[$field], OutageAttachment::DIRECTORY);
                        } else {
                            unset($data[$field]);
                        }
                    }

                    $this->workflow->completeVisit($outage, $user, $data);

                    return 'Completed — sent to the dispatcher';
                },
            ],
            'visit_pause' => [
                'visible' => $can('visitPause') && $holding,
                'label' => 'Pause work',
                'fields' => fn () => [$this->textarea('reason', 'Reason (mandatory)', required: true, max: 250)],
                'handle' => function (array $data) use ($outage, $user): string {
                    $this->workflow->visitPause($outage, $user, $data['reason']);

                    return 'Work paused';
                },
            ],
            'visit_not_completed' => [
                'visible' => $can('visitNotCompleted') && $holding,
                'label' => 'Could not complete',
                'style' => 'danger',
                'fields' => fn () => [
                    $this->select('to', 'What is needed', [
                        ['value' => 'dispatch', 'label' => 'Escalation — the dispatcher decides (Infrastructure Support, Design, NOC, Access…)'],
                        ['value' => 'field_support', 'label' => 'The customer — unreachable, asked to reschedule or to be called back (CX Field Support)'],
                    ], required: true, default: 'dispatch'),
                    $this->select('delay_reason_id', 'Reason for delay', $this->options(TicketActions::delayReasons())),
                    $this->textarea('reason', 'What happened on site', required: true, max: 2000),
                ],
                'handle' => function (array $data) use ($outage, $user): string {
                    $this->workflow->visitNotCompleted($outage, $user, $data['reason'], $this->id($data['delay_reason_id'] ?? null), ($data['to'] ?? 'dispatch') === 'field_support');

                    return 'Visit handed back';
                },
            ],

            // ---------------------------------------------------------- customer tickets / visits: desk work
            'ticket_route' => [
                'visible' => $can('ticketRoute') && $holding && TicketActions::routeOptions($outage) !== [],
                'label' => 'Escalate / hand over',
                'style' => 'warning',
                'description' => 'From '.($outage->current_queue?->getLabel() ?? '—').'. The next available person at the desk you choose gets it (FIFO).',
                'fields' => fn () => [
                    $this->select('desk', 'Send to', $this->options(TicketActions::routeOptions($outage)), required: true),
                    ...($outage->isSiteVisit() ? [$this->select('delay_reason_id', 'Reason for delay (optional)', $this->options(TicketActions::delayReasons()))] : []),
                    $this->textarea('note', 'Why / what is needed', required: true, max: 2000),
                ],
                'handle' => function (array $data) use ($outage, $user): string {
                    $desk = QueueType::tryFrom((string) $data['desk']);

                    if (! $desk || ! array_key_exists($desk->value, TicketActions::routeOptions($outage))) {
                        throw ValidationException::withMessages(['desk' => 'Choose one of the desks this ticket can go to.']);
                    }

                    $this->workflow->routeTicket($outage, $user, $desk, $data['note'], $this->id($data['delay_reason_id'] ?? null));

                    return 'Ticket handed over';
                },
            ],
            'ticket_return' => [
                'visible' => $can('ticketReturn') && $holding,
                'label' => 'Return to previous desk',
                'style' => 'primary',
                'description' => 'The ticket goes back to the desk that handed it to you, with what you did / found.',
                'fields' => fn () => [$this->textarea('note', 'What was done / found', required: true, max: 2000)],
                'handle' => function (array $data) use ($outage, $user): string {
                    $this->workflow->returnTicket($outage, $user, $data['note']);

                    return 'Ticket returned';
                },
            ],
            'ticket_follow_up' => [
                'visible' => $can('ticketFollowUp') && ! $can('visitConfirm'),
                'label' => 'Log customer call',
                'description' => 'Numbers: '.(implode(' · ', $ticket?->phones() ?? []) ?: 'none on the ticket'),
                'fields' => fn () => [
                    $this->select('outcome', 'Outcome', $this->options(TicketFollowUp::OUTCOMES), required: true),
                    $this->datetime('called_at', 'Called at', default: now()->startOfMinute()),
                    $this->textarea('notes', 'Notes', max: 5000),
                ],
                'handle' => function (array $data) use ($outage, $user): string {
                    $this->workflow->recordTicketFollowUp($outage, $user, $data);

                    return 'Customer call logged';
                },
            ],
            'ticket_close' => [
                'visible' => $can('ticketClose') && $holding,
                'label' => 'Close ticket',
                'style' => 'success',
                'description' => $outage->isSiteVisit()
                    ? 'Closes the visit without field work (for example the customer confirmed uptime on the call).'
                    : 'Closes the ticket with its final reason.',
                'fields' => fn () => [
                    $this->select('final_reason_id', 'Final reason', $this->options(TicketActions::finalReasons($outage->isSiteVisit() ? 'visit' : 'ticket', $outage->current_queue)), required: true),
                    $this->select('delay_reason_id', 'Reason for delay', $this->options(TicketActions::delayReasons()), required: TicketActions::late($outage),
                        help: TicketActions::late($outage) ? 'This ticket is past its SLA: a reason for the delay is required.' : null),
                    $this->textarea('resolution_notes', 'Resolution / what the customer was told', max: 5000),
                    $this->toggle('customer_confirmed', 'The customer confirmed it is resolved'),
                ],
                'handle' => function (array $data) use ($outage, $user): string {
                    $this->workflow->closeTicket($outage, $user, $data);

                    return 'Ticket closed';
                },
            ],
            'ticket_attach' => [
                'visible' => $can('ticketAttach') && ($holding || MobileProfile::dispatchQueues($user) !== []),
                'label' => 'Add photos',
                'fields' => fn () => [
                    $this->photos('files', 'Photos', required: true),
                    $this->select('kind', 'What is it?', [
                        ['value' => 'geo_photo', 'label' => 'Geolocation photo'],
                        ['value' => 'speed_test', 'label' => 'Speed test photo'],
                        ['value' => 'evidence', 'label' => 'Other evidence of the work'],
                    ], placeholder: 'Other document'),
                    $this->text('note', 'Note (optional)', max: 255),
                ],
                'handle' => function (array $data) use ($outage, $user): string {
                    [$paths, $names] = $this->storeMany((array) $data['files'], OutageAttachment::DIRECTORY);
                    $this->workflow->addTicketAttachments($outage, $user, $paths, $names, $data['note'] ?? null, $data['kind'] ?? null);

                    return 'Photos attached';
                },
            ],
            'ticket_cancel' => [
                'visible' => $can('ticketCancel'),
                'label' => 'Cancel / duplicate',
                'style' => 'danger',
                'description' => 'Cancelled and duplicate tickets have no SLA outcome and are left out of the reports.',
                'fields' => fn () => [
                    $this->toggle('duplicate', 'This is a duplicate of another ticket'),
                    $this->textarea('reason', 'Reason', required: true),
                ],
                'handle' => function (array $data) use ($outage, $user): string {
                    $this->workflow->cancelTicket($outage, $user, $data['reason'], ! empty($data['duplicate']));

                    return ! empty($data['duplicate']) ? 'Marked as a duplicate' : 'Ticket cancelled';
                },
            ],

            // ---------------------------------------------------------- custom workflows
            'custom_complete' => [
                'visible' => $can('workCustomStep') && $assignment !== null,
                'label' => $custom?->isLastStep() ? 'Complete ticket' : 'Complete step',
                'style' => 'success',
                'primary' => true,
                'description' => $custom ? $custom->progressLabel().(filled($custom->step()['instructions'] ?? null) ? ' — '.$custom->step()['instructions'] : '') : null,
                'fields' => fn () => [$this->textarea('note', $custom?->isLastStep() ? 'How it was resolved' : 'Note', max: 5000)],
                'handle' => function (array $data) use ($outage, $user): string {
                    $done = $this->workflow->completeCustomStep($outage, $user, $data['note'] ?? null);

                    return $done->is_closed ? 'Ticket completed' : 'Step completed — on to “'.($done->customTicket?->step()['name'] ?? 'the next step').'”';
                },
            ],
            'custom_send_back' => [
                'visible' => $can('workCustomStep') && $assignment !== null && ($custom?->step_index ?? 0) > 0 && ($custom?->step()['can_send_back'] ?? false),
                'label' => 'Send back',
                'style' => 'warning',
                'fields' => fn () => [$this->textarea('reason', 'Why is it sent back?', required: true)],
                'handle' => function (array $data) use ($outage, $user): string {
                    $this->workflow->sendBackCustomStep($outage, $user, $data['reason']);

                    return 'Sent back to the step before';
                },
            ],

            // ---------------------------------------------------------- everyone on the ticket
            'add_update' => [
                'visible' => $can('addUpdate') && ($holding || MobileProfile::dispatchQueues($user) !== []),
                'label' => 'Add update',
                'fields' => fn () => [$this->textarea('body', 'Update / action taken', required: true, max: 5000)],
                'handle' => function (array $data) use ($outage, $user): string {
                    $this->workflow->addUpdate($outage, $user, $data['body'], 'progress');

                    return 'Update posted';
                },
            ],
        ];
    }

    // ------------------------------------------------------------------ fields

    private function text(string $name, string $label, bool $required = false, int $max = 255, mixed $default = null): array
    {
        return ['name' => $name, 'type' => 'text', 'label' => $label, 'required' => $required, 'max' => $max, 'default' => $default];
    }

    private function textarea(string $name, string $label, bool $required = false, int $max = 2000, mixed $default = null): array
    {
        return ['name' => $name, 'type' => 'textarea', 'label' => $label, 'required' => $required, 'max' => $max, 'default' => $default];
    }

    private function number(string $name, string $label, bool $required = false, int $max = 1000000): array
    {
        return ['name' => $name, 'type' => 'number', 'label' => $label, 'required' => $required, 'max' => $max, 'default' => null];
    }

    /**
     * @param  list<array{value: mixed, label: string, group?: mixed}>  $options
     * @param  bool  $search  the app always shows a search box (people and teams), however short the list;
     *                        other lists get one once they are long
     */
    private function select(string $name, string $label, array $options, bool $required = false, mixed $default = null, ?string $placeholder = null, ?string $help = null, ?string $dependsOn = null, bool $search = false): array
    {
        return [
            'name' => $name, 'type' => 'select', 'label' => $label, 'required' => $required, 'options' => array_values($options),
            'default' => $default === null ? null : (string) $default, 'placeholder' => $placeholder, 'help' => $help, 'depends_on' => $dependsOn,
            'search' => $search,
        ];
    }

    private function datetime(string $name, string $label, bool $required = false, mixed $default = null, ?string $help = null, bool $future = false): array
    {
        return [
            'name' => $name, 'type' => 'datetime', 'label' => $label, 'required' => $required, 'help' => $help,
            'default' => $default?->toIso8601String(),
            // A moment that happened cannot be in the future; a booking cannot be in the past.
            'future' => $future,
        ];
    }

    private function toggle(string $name, string $label, bool $default = false): array
    {
        return ['name' => $name, 'type' => 'toggle', 'label' => $label, 'required' => false, 'default' => $default];
    }

    /**
     * Every photo in the app is taken with the phone's camera, there and then — nothing from the
     * gallery. The app stamps the time on the picture and sends it as "{name}_taken_at".
     */
    private function photo(string $name, string $label, ?string $help = null, bool $required = false, bool $location = false): array
    {
        return [
            'name' => $name, 'type' => 'photo', 'label' => $label, 'required' => $required, 'help' => $help,
            'max_kb' => (int) config('oms.mobile.max_upload_kb'),
            'capture' => 'camera',
            // A geolocation photo: the app takes the phone's position with it and sends it as "{name}_lat", "{name}_lng",
            // "{name}_accuracy" (metres) and "{name}_place". "required" = no photo without a position.
            'location' => $location ? (app(Settings::class)->bool('ticketing.require_location') ? 'required' : 'optional') : null,
        ];
    }

    /** @return array<string, string> site photo position => its field in the app's form */
    private function sitePhotoFields(): array
    {
        return collect(array_keys(OutagePhoto::SITE_PHOTOS))->mapWithKeys(fn (string $position) => [$position => 'site_'.$position])->all();
    }

    /**
     * Photos taken with the camera, stored and described for the workflow: path, file name, when
     * the camera took them, and that they came from the camera.
     *
     * @param  array<string, string>  $fields  position => field name
     * @return array<string, mixed> empty when no photo was sent
     */
    private function cameraPhotos(array $data, array $fields): array
    {
        $photos = [];

        foreach ($fields as $position => $field) {
            if (($data[$field] ?? null) instanceof UploadedFile) {
                [$photos[$position], $photos[$position.'_name']] = $this->store($data[$field], 'outage-photos');
                $photos[$position.'_taken_at'] = $data[$field.'_taken_at'] ?? now()->toDateTimeString();
                $photos[$position.'_source'] = OutagePhoto::SOURCE_CAMERA;
            }
        }

        return $photos;
    }

    private function photos(string $name, string $label, bool $required = false): array
    {
        return ['name' => $name, 'type' => 'photos', 'label' => $label, 'required' => $required, 'max_files' => 8, 'max_kb' => (int) config('oms.mobile.max_upload_kb'), 'capture' => 'camera'];
    }

    /**
     * id => label pairs as the option list the app expects.
     *
     * @return list<array{value: string, label: string}>
     */
    private function options(iterable $pairs): array
    {
        $options = [];

        foreach ($pairs as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return $options;
    }

    /** Technicians per dispatch team: the app narrows the list to the team picked ("group" = team id). */
    private function technicianOptions(): array
    {
        $options = [];

        foreach (array_keys(OutageActions::teamOptions(null)) as $teamId) {
            foreach (OutageActions::technicianOptions((int) $teamId, null) as $userId => $label) {
                $options[] = ['value' => (string) $userId, 'label' => $label, 'group' => (string) $teamId];
            }
        }

        return $options;
    }

    // ------------------------------------------------------------------ input

    /**
     * Validate what the app sent against the form this action has right now. Unknown fields are
     * dropped; a select only accepts one of its options; photos must really be images.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $fields): array
    {
        $rules = [];
        $labels = [];
        $messages = [];
        $maxKb = (int) config('oms.mobile.max_upload_kb');
        $image = ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.$maxKb];

        foreach ($fields as $field) {
            $name = $field['name'];
            $labels[$name] = strtolower($field['label']);
            $presence = $field['required'] ? 'required' : 'nullable';

            $rules[$name] = match ($field['type']) {
                'text', 'textarea' => [$presence, 'string', 'max:'.$field['max']],
                'number' => [$presence, 'integer', 'min:0', 'max:'.$field['max']],
                'select' => [$presence, 'in:'.implode(',', array_unique(array_column($field['options'], 'value')))],
                'datetime' => [$presence, 'date', $field['future'] ? 'after:'.now()->subMinutes(10)->toDateTimeString() : 'before:'.now()->addMinutes(5)->toDateTimeString()],
                'toggle' => ['nullable', 'boolean'],
                'photo' => [$presence, ...$image],
                'photos' => [$presence, 'array', 'max:'.$field['max_files']],
                default => ['nullable'],
            };

            if ($field['type'] === 'photos') {
                $rules[$name.'.*'] = $image;
            }

            // A camera-only photo comes with the moment it was taken.
            if (($field['capture'] ?? null) === 'camera') {
                $rules[$name.'_taken_at'] = ['nullable', 'date'];
            }

            // A geolocation photo comes with the position it was taken at.
            if (filled($field['location'] ?? null)) {
                $needed = $field['location'] === 'required' ? 'required_with:'.$name : 'nullable';
                $rules[$name.'_lat'] = [$needed, 'numeric', 'between:-90,90'];
                $rules[$name.'_lng'] = [$needed, 'numeric', 'between:-180,180'];
                $rules[$name.'_accuracy'] = ['nullable', 'numeric', 'min:0', 'max:1000000'];
                $rules[$name.'_place'] = ['nullable', 'string', 'max:255'];
                $messages[$name.'_lat.required_with'] = $messages[$name.'_lng.required_with']
                    = 'The '.strtolower($field['label']).' needs your position: switch on Location on the phone, allow it for OMS and take the photo again.';
            }
        }

        $data = Validator::make($request->all(), $rules, $messages, $labels)->validate();

        foreach ($fields as $field) {
            if (filled($field['location'] ?? null)) {
                $name = $field['name'];
                $data[$name.'_location'] = filled($data[$name.'_lat'] ?? null) && filled($data[$name.'_lng'] ?? null) ? [
                    'lat' => $data[$name.'_lat'], 'lng' => $data[$name.'_lng'], 'accuracy' => $data[$name.'_accuracy'] ?? null,
                    'place' => $data[$name.'_place'] ?? null, 'taken_at' => $data[$name.'_taken_at'] ?? null,
                ] : null;
            }
        }

        foreach ($fields as $field) {
            if (($field['capture'] ?? null) === 'camera') {
                $taken = filled($data[$field['name'].'_taken_at'] ?? null) ? Carbon::parse($data[$field['name'].'_taken_at'])->setTimezone(config('app.timezone')) : null;
                // A phone whose clock runs ahead cannot date a photo in the future.
                $data[$field['name'].'_taken_at'] = ($taken === null || $taken->isAfter(now()->addMinutes(5)) ? now() : $taken)->toDateTimeString();
            }
        }

        foreach ($fields as $field) {
            $name = $field['name'];

            $value = $data[$name] ?? null;

            $data[$name] = match (true) {
                $field['type'] === 'toggle' => filter_var($value ?? $field['default'], FILTER_VALIDATE_BOOLEAN),
                $value === null || $value === '' => null,
                // The phone sends a moment with its UTC offset; the workflow works in the server's own time.
                $field['type'] === 'datetime' => Carbon::parse($value)->setTimezone(config('app.timezone'))->toDateTimeString(),
                $field['type'] === 'number', $field['type'] === 'select' && ctype_digit((string) $value) => (int) $value,
                default => $value,
            };
        }

        return $data;
    }

    private function id(mixed $value): ?int
    {
        return filled($value) && ctype_digit((string) $value) ? (int) $value : null;
    }

    /**
     * Keep an uploaded photo on the private disk, where the web screens keep theirs.
     *
     * @return array{0: string, 1: string} path, original name
     */
    private function store(UploadedFile $file, string $directory): array
    {
        $path = $file->store($directory.'/'.now()->format('Y/m'), 'local');

        if (! is_string($path)) {
            throw ValidationException::withMessages(['files' => 'The photo could not be saved. Try again.']);
        }

        return [$path, str($file->getClientOriginalName())->limit(120, '')->toString() ?: basename($path)];
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array{0: list<string>, 1: array<string, string>} paths, path => original name
     */
    private function storeMany(array $files, string $directory): array
    {
        $paths = [];
        $names = [];

        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                [$path, $name] = $this->store($file, $directory);
                $paths[] = $path;
                $names[$path] = $name;
            }
        }

        return [$paths, $names];
    }

    private function myAssignment(User $user, Outage $outage): ?OutageAssignment
    {
        return OutageAssignment::query()
            ->where('outage_id', $outage->id)
            ->where('user_id', $user->id)
            ->active()
            ->latest('id')
            ->first();
    }
}
