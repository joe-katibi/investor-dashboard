<?php

namespace App\Services\Mobile;

use App\Enums\AssignmentStatus;
use App\Models\Outage;
use App\Models\OutageAssignment;
use App\Models\OutageAttachment;
use App\Models\OutagePhoto;
use App\Support\VisitPhotos;
use App\Models\OutageUpdate;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * A ticket as the mobile app sees it: one shape for outages, 4G tickets, site visits and customer
 * tickets, so the app has one list and one ticket screen. Only what a dispatcher, technician or
 * engineer needs in the field is included.
 */
final class TicketPresenter
{
    /** Relations the list needs (the list must not load anything row by row). */
    public const LIST_RELATIONS = [
        'priority:id,name,code,color', 'status:id,name,code,color', 'outageType:id,name,is_planned', 'currentAssignee:id,name', 'technician:id,name',
        'ticket:id,outage_id,customer_name,account_number,address,scheduled_at,visit_type_id,completed_at', 'ticket.visitType:id,name',
    ];

    public const DETAIL_RELATIONS = [
        'priority', 'status', 'outageType', 'majorOutageType', 'team:id,name', 'subTeam:id,name', 'technician:id,name,phone', 'currentAssignee:id,name',
        'olts:id,name', 'fdts:id,name,olt_id', 'enodebs', 'transmissionNodes', 'openSupportRequest', 'vendor:id,name',
        'ticket.visitType', 'ticket.category', 'ticket.subcategory', 'ticket.region', 'ticket.olt', 'ticket.fdt', 'ticket.slot', 'ticket.port',
        'ticket.finalReason', 'ticket.delayReason',
    ];

    public function __construct(private readonly MobileActions $actions) {}

    /** @return array<string, mixed> one row of a ticket list */
    public function summary(Outage $outage, User $user, ?OutageAssignment $mine = null): array
    {
        $ticket = $outage->isTicket() ? $outage->ticket : null;
        $sla = $outage->currentSlaState();

        return [
            'id' => $outage->id,
            'number' => $outage->ticket_number,
            'module' => $this->module($outage),
            'module_label' => $this->moduleLabel($outage),
            'type' => $ticket?->visitType?->name ?? $outage->outageType?->name,
            'title' => $outage->title,
            'customer_name' => $ticket?->customer_name,
            'location' => $ticket?->address ?: $outage->location,
            'priority' => $outage->priority ? [
                'name' => $outage->priority->name,
                'color' => $outage->priority->color,
                'critical' => $outage->priority->isCritical(),
            ] : null,
            'status' => [
                'name' => $outage->status?->name,
                'code' => $outage->status?->code,
                'color' => $outage->status?->color,
            ],
            'sla' => $sla ? [
                'state' => $sla->value,
                'label' => $sla->getLabel(),
                'color' => $sla->getColor(),
                'due_at' => $this->iso($outage->sla_due_at),
            ] : null,
            'assigned_to' => $outage->currentAssignee?->name ?? $outage->technician?->name,
            'queue' => $outage->current_queue?->getLabel(),
            'my_assignment' => $mine ? [
                'status' => $mine->status->value,
                'label' => $mine->status->getLabel(),
                'step' => $mine->queue_type?->getLabel(),
                'needs_acceptance' => $mine->status === AssignmentStatus::Assigned,
                'accept_by' => $this->iso($mine->ack_due_at),
                'assigned_at' => $this->iso($mine->assigned_at),
            ] : null,
            'scheduled_at' => $this->iso($ticket?->scheduled_at),
            'is_closed' => (bool) $outage->is_closed,
            'created_at' => $this->iso($outage->start_time ?? $outage->created_at),
            'updated_at' => $this->iso($outage->updated_at),
        ];
    }

    /** @return array<string, mixed> everything the ticket screen shows, and what this person may do */
    public function detail(Outage $outage, User $user): array
    {
        $outage->loadMissing(self::DETAIL_RELATIONS);
        $ticket = $outage->isTicket() ? $outage->ticket : null;
        $mine = OutageAssignment::query()->where('outage_id', $outage->id)->where('user_id', $user->id)->active()->latest('id')->first();

        return $this->summary($outage, $user, $mine) + [
            'description' => $outage->description,
            'latest_update' => $outage->latest_update,
            'customers_affected' => $outage->isTicket() ? null : $outage->customers_affected,
            'network' => $this->network($outage),
            'customer' => $ticket ? [
                'name' => $ticket->customer_name,
                'account' => $ticket->account_number,
                'phones' => $ticket->phones(),
                // Calling, primary and alternative numbers, each named: if one is unreachable
                // the person in the field picks another.
                'contacts' => $ticket->contacts(),
                'address' => $ticket->address,
                'landmark' => $ticket->landmark,
                'region' => $ticket->region?->name,
                'line' => $ticket->topologyPath() ?: null,
                'bandwidth' => $ticket->bandwidth,
                'availability' => $ticket->preferred_time,
            ] : null,
            'visit' => $outage->isSiteVisit() && $ticket ? [
                'type' => $ticket->visitType?->name,
                'category' => collect([$ticket->category?->name, $ticket->subcategory?->name])->filter()->implode(' › ') ?: null,
                'scheduled_at' => $this->iso($ticket->scheduled_at),
                'en_route_at' => $this->iso($ticket->en_route_at),
                'arrived_at' => $this->iso($ticket->arrived_at),
                'paused_at' => $this->iso($ticket->paused_at),
                'pause_reason' => $ticket->paused_at ? $ticket->pause_reason : null,
                'completed_at' => $this->iso($ticket->completed_at),
                'verified_at' => $this->iso($ticket->verified_at),
                'on_premise_seconds' => $ticket->onPremiseSeconds(),
                'fat_number' => $ticket->fat_number,
                'final_reason' => $ticket->finalReason?->name,
                'delay_reason' => $ticket->delayReason?->name,
                'resolution_notes' => $ticket->resolution_notes,
            ] : null,
            'assignment' => [
                'technician' => $outage->technician?->name,
                'technician_phone' => $outage->technician?->phone,
                'team' => collect([$outage->team?->name, $outage->subTeam?->name])->filter()->implode(' / ') ?: null,
                'holder' => $outage->currentAssignee?->name,
                'queue' => $outage->current_queue?->getLabel(),
                'dispatched_at' => $this->iso($outage->dispatched_at),
            ],
            'support_request' => $outage->openSupportRequest ? [
                'work_required' => $outage->openSupportRequest->work_required,
                'customer_details' => $outage->openSupportRequest->customer_details,
                'location' => $outage->openSupportRequest->location,
            ] : null,
            'resolution' => array_filter([
                'root_cause' => $outage->root_cause,
                'resolution' => $outage->resolution,
                'restored_at' => $this->iso($outage->restored_at),
                'final_reason' => $outage->final_reason,
            ]) ?: null,
            'timeline' => $this->timeline($outage),
            'attachments' => $this->attachments($outage),
            'actions' => $this->actions->for($user, $outage),
        ];
    }

    /** outage | mobility | site_visit | customer_ticket | maintenance */
    private function module(Outage $outage): string
    {
        return match (true) {
            $outage->isSiteVisit() => 'site_visit',
            $outage->isCxTicket() => 'customer_ticket',
            $outage->isMobility() => 'mobility',
            $outage->maintenance_kind !== null || $outage->outageType?->is_planned => 'maintenance',
            default => 'outage',
        };
    }

    private function moduleLabel(Outage $outage): string
    {
        return match ($this->module($outage)) {
            'site_visit' => 'Site visit',
            'customer_ticket' => 'Customer ticket',
            'mobility' => '4G outage',
            'maintenance' => $outage->maintenanceKindLabel() ?? 'Planned maintenance',
            default => 'Outage',
        };
    }

    /** @return array<string, mixed>|null where on the network the fault is */
    private function network(Outage $outage): ?array
    {
        if ($outage->isTicket()) {
            return null;
        }

        if ($outage->isMobility()) {
            return array_filter([
                'domain' => $outage->domainLabel(),
                'sites' => $outage->sitesLabel(12) ?: null,
                'nodes' => $outage->transmissionNodes->pluck('code')->take(12)->implode(', ') ?: null,
                'impact' => $outage->impactLabel() ?: null,
                'cells_down' => $outage->cells_down ?: null,
                'link' => collect([$outage->transmission_link_id, Outage::TRANSMISSION_TYPES[$outage->transmission_type] ?? null])->filter()->implode(' · ') ?: null,
                'vendor' => $outage->vendor?->name,
            ]) ?: null;
        }

        return array_filter([
            'olts' => $outage->olts->pluck('name')->implode(', ') ?: null,
            'fdts' => $outage->fdts->pluck('name')->take(20)->implode(', ') ?: null,
            'site' => $outage->site_label,
            'downtime' => $outage->downtime_start
                ? $outage->downtime_start->format('d M Y H:i').' – '.($outage->downtime_end?->format('d M Y H:i') ?? '—')
                : null,
        ]) ?: null;
    }

    /** @return list<array<string, mixed>> oldest first: what happened to the ticket, and who did it */
    private function timeline(Outage $outage): array
    {
        return OutageUpdate::query()
            ->where('outage_id', $outage->id)
            ->with('user:id,name')
            ->latest('id')
            ->limit(100)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (OutageUpdate $update) => [
                'id' => $update->id,
                'type' => $update->type,
                'type_label' => OutageUpdate::TYPES[$update->type] ?? ucfirst((string) $update->type),
                'body' => $update->body,
                'by' => $update->user?->name ?? 'System',
                'actor' => $update->actor_type,
                'system' => (bool) $update->is_system,
                'at' => $this->iso($update->created_at),
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> photos and files, each opened through the API with the app's token */
    private function attachments(Outage $outage): array
    {
        $files = OutageAttachment::query()->where('outage_id', $outage->id)->orderBy('id')->limit(60)->get()
            ->map(fn (OutageAttachment $file) => [
                'id' => 'a'.$file->id,
                'name' => $file->name(),
                'label' => match (true) {
                    VisitPhotos::short($file->kind) !== null => VisitPhotos::short($file->kind),
                    $file->kind === 'evidence' => 'Evidence',
                    default => $file->note ?: 'Attachment',
                },
                'is_image' => (bool) preg_match('/\.(jpe?g|png|webp)$/i', $file->path),
                'url' => route('api.v1.attachments.show', $file),
                'at' => $this->iso($file->created_at),
            ]);

        $photos = OutagePhoto::query()->where('outage_id', $outage->id)->orderBy('id')->limit(20)->get()
            ->map(fn (OutagePhoto $photo) => [
                'id' => 'p'.$photo->id,
                'name' => $photo->original_name ?: basename($photo->path),
                'label' => $photo->label(),
                'is_image' => true,
                'url' => route('api.v1.photos.show', $photo),
                // When the camera took it; for a photo uploaded on the website, when it was uploaded.
                'at' => $this->iso($photo->takenAt()),
            ]);

        return $photos->concat($files)->values()->all();
    }

    /** Times leave with their UTC offset, so every phone shows them in its own time zone. */
    private function iso(mixed $moment): ?string
    {
        return $moment instanceof DateTimeInterface ? Carbon::instance($moment)->toIso8601String() : null;
    }
}
