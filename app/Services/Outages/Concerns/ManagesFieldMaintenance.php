<?php

namespace App\Services\Outages\Concerns;

use App\Enums\AssignmentType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\QueueType;
use App\Enums\StatusCode;
use App\Enums\TeamType;
use App\Models\MaintenanceApproval;
use App\Models\Outage;
use App\Models\OutagePhoto;
use App\Models\OutageType;
use App\Models\User;
use App\Services\Maintenance\MaintenanceNotifier;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Field maintenance raised on site by an Infrastructure technician, with "before" photos (outside
 * the FAT / FDT showing its label, and inside):
 *
 *   PREVENTIVE: RAISE → AWAITING APPROVAL → Dispatcher + Infrastructure Manager approve (two
 *   different people) → everyone notified of the approved downtime (photos attached) → TEAM
 *   DISPATCHED to the technician who raised it.
 *   CORRECTIVE (fallen pole, dropped cable…): RAISE → TEAM DISPATCHED at once, everyone notified,
 *   optional support technician request for the drop cable.
 *
 * Both close through "Report work completed" with two "after" photos, then the usual NOC
 * restore confirmation and resolution.
 */
trait ManagesFieldMaintenance
{
    /**
     * @param  array<string, mixed>  $data  kind, title, description, vertical_id, site_label, location, olt_ids…, customers_affected,
     *                                      downtime_start / downtime_end (preventive), photos: {outside, inside},
     *                                      support: {work_required, customer_details} (corrective, optional)
     */
    public function raiseFieldMaintenance(array $data, User $by): Outage
    {
        Gate::forUser($by)->authorize('raiseFieldMaintenance', Outage::class);

        $kind = $data['kind'] ?? null;
        if (! in_array($kind, [OutageType::PREVENTIVE, OutageType::CORRECTIVE], true)) {
            throw ValidationException::withMessages(['kind' => 'Choose preventive or corrective maintenance.']);
        }

        $preventive = $kind === OutageType::PREVENTIVE;
        $this->requireText($data['title'] ?? null, 'title', 'Give the maintenance a title.');
        $this->requireText($data['description'] ?? null, 'description', $preventive ? 'Describe the issue found on site.' : 'Describe what happened and the work needed.');
        $this->requireText($data['site_label'] ?? null, 'site_label', 'Enter the FAT / FDT label.');
        if ($this->maintenancePhotosRequired()) {
            $this->requirePhotos($data['photos'] ?? [], 'before');
        }
        [$start, $end] = $preventive ? $this->maintenanceWindow($data['downtime_start'] ?? null, $data['downtime_end'] ?? null) : [null, null];

        $type = OutageType::query()->active()->where('maintenance_kind', $kind)->orderBy('sort_order')->first()
            ?? throw ValidationException::withMessages(['kind' => 'No active '.$kind.' maintenance type is configured.']);

        $result = DB::transaction(function () use ($data, $by, $type, $preventive, $start, $end): Outage {
            $outage = $this->newOutage(
                array_merge($data, ['start_time' => $start ?? now()]),
                $by, $type,
                $preventive ? StatusCode::PmPendingApproval : StatusCode::TeamDispatched,
                $preventive ? 'Preventive maintenance raised on site' : 'Corrective maintenance raised on site',
            );

            $outage->forceFill([
                'maintenance_kind' => $type->maintenance_kind,
                'site_label' => trim((string) $data['site_label']),
                'downtime_start' => $start,
                'downtime_end' => $end,
                'latest_update' => $data['description'],
            ])->save();

            $photos = $this->storeSitePhotos($outage, $by, OutagePhoto::BEFORE, (array) ($data['photos'] ?? []));
            $this->addSystemUpdate($outage, ($preventive ? 'Preventive' : 'Corrective')." maintenance raised on site at {$outage->site_label}: {$data['description']}", $by);

            if ($preventive) {
                $approvers = User::query()->active()->permission(array_column(MaintenanceApproval::SLOTS, 1))->get();
                $context = ['window' => $this->downtimeWindow($outage), 'reason' => $data['description'], 'by' => $by->name, 'attach_photo_ids' => $photos->pluck('id')->all()];
                $this->notifier->notifyUsers(NotificationEvent::PreventiveRequested, $outage, $approvers, [NotificationChannel::InApp->value, NotificationChannel::Mail->value], $context);
                $this->notifier->dispatch(NotificationEvent::PreventiveRequested, $outage, $context);
            } else {
                $this->assignToFieldTechnician($outage, $by, $by, 'Corrective maintenance raised on site', transition: false);
                $this->announceFieldMaintenance(NotificationEvent::CorrectiveRaised, $outage, [
                    'reason' => $data['description'], 'by' => $by->name, 'attach_photo_ids' => $photos->pluck('id')->all(),
                ]);

                if (filled($data['support']['work_required'] ?? null)) {
                    $this->requestSupport($outage->refresh(), $by, $data['support']);
                }
            }

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /** Dispatcher or Infrastructure Manager approves the downtime; both approvals start the work. */
    public function approvePreventive(Outage $outage, User $by, ?string $comment = null): Outage
    {
        $result = DB::transaction(function () use ($outage, $by, $comment): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('approvePreventive', $outage);
            $slot = MaintenanceApproval::openSlotFor($outage, $by);

            MaintenanceApproval::query()->create([
                'outage_id' => $outage->id, 'slot' => $slot, 'user_id' => $by->id, 'decision' => 'approved', 'comment' => $comment,
            ]);

            $label = MaintenanceApproval::SLOTS[$slot][0];
            $this->addSystemUpdate($outage, "Downtime approved by {$by->name} ({$label})".($comment ? ": {$comment}" : '.'), $by);

            $approvals = MaintenanceApproval::query()->where('outage_id', $outage->id)->where('decision', 'approved')->with('user:id,name')->get();

            if ($approvals->pluck('slot')->unique()->count() < count(MaintenanceApproval::SLOTS)) {
                return $outage->refresh();
            }

            // Both approved: everyone hears about the downtime, and the job goes to the technician who raised it.
            $outage->forceFill(['pm_approved_at' => now()])->save();
            $technician = User::query()->active()->find($outage->created_by);

            if ($technician) {
                $this->assignToFieldTechnician($outage, $technician, $by, 'Preventive maintenance approved', transition: true);
            } else {
                $outage->forceFill(['infra_escalated_at' => now()]);
                $this->transition($outage, StatusCode::AssignedToInfrastructure, $by, 'Preventive maintenance approved');
                $this->fifo->enqueue($outage, QueueType::Infrastructure, ['reason' => 'Preventive maintenance approved']);
            }

            $this->announceFieldMaintenance(NotificationEvent::PreventiveApproved, $outage->refresh(), [
                'window' => $this->downtimeWindow($outage),
                'reason' => 'Downtime approved by '.$approvals->map(fn (MaintenanceApproval $a) => $a->user?->name.' ('.$a->slotLabel().')')->implode(' and '),
                'by' => $by->name,
                'attach_photo_ids' => $outage->photos()->where('stage', OutagePhoto::BEFORE)->pluck('id')->all(),
                'extra_user_ids' => [$outage->created_by, ...$approvals->pluck('user_id')->all()],
            ]);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    public function rejectPreventive(Outage $outage, User $by, string $reason): Outage
    {
        $this->requireText($reason, 'reason', 'Give the reason for rejecting.');

        $result = DB::transaction(function () use ($outage, $by, $reason): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('rejectPreventive', $outage);
            $slot = MaintenanceApproval::openSlotFor($outage, $by);

            MaintenanceApproval::query()->create([
                'outage_id' => $outage->id, 'slot' => $slot, 'user_id' => $by->id, 'decision' => 'rejected', 'comment' => $reason,
            ]);

            $outage->forceFill(['final_reason' => "Rejected by {$by->name}: {$reason}", 'end_time' => now()]);
            $this->transition($outage, StatusCode::PmRejected, $by, $reason);
            $this->addSystemUpdate($outage, "Preventive maintenance rejected by {$by->name} (".MaintenanceApproval::SLOTS[$slot][0]."): {$reason}", $by);

            $told = User::query()->active()->whereIn('id', array_filter([
                $outage->created_by,
                ...MaintenanceApproval::query()->where('outage_id', $outage->id)->pluck('user_id')->all(),
            ]))->get();
            $this->notifier->notifyUsers(NotificationEvent::PreventiveRejected, $outage, $told, [NotificationChannel::InApp->value, NotificationChannel::Mail->value], ['reason' => $reason]);
            $this->notifier->dispatch(NotificationEvent::PreventiveRejected, $outage, ['reason' => $reason]);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    // ------------------------------------------------------------------ internals

    /** Photos come from the upload fields: both positions, stored under outage-photos/ on the private disk. */
    private function requirePhotos(array $photos, string $stage): void
    {
        foreach (array_keys(OutagePhoto::POSITIONS) as $position) {
            if ($this->photoPath($photos[$position] ?? null) === null) {
                throw ValidationException::withMessages(["photos.{$position}" => 'Add the '.$stage.' photo '.Str::lower(OutagePhoto::POSITIONS[$position]).'.']);
            }
        }
    }

    /**
     * $photos, for each position: the stored path (or the upload field's one-file list), and optionally
     * "{position}_name" (file name), "{position}_taken_at" (when the camera took it) and
     * "{position}_source" — or one "source" for all of them (camera | upload).
     *
     * @return Collection<int, OutagePhoto>
     */
    private function storeSitePhotos(Outage $outage, User $by, string $stage, array $photos): Collection
    {
        if ($this->maintenancePhotosRequired()) {
            $this->requirePhotos($photos, $stage);
        }

        // Optional photos: whichever of the two were added.
        return collect(array_keys(OutagePhoto::POSITIONS))
            ->filter(fn (string $position) => $this->photoPath($photos[$position] ?? null) !== null)
            ->map(fn (string $position) => $this->createPhoto($outage, $by, $stage, $position, $photos))->values();
    }

    /** Field maintenance photos are mandatory unless made optional (FIFO & SLA Settings → Field photos). */
    private function maintenancePhotosRequired(): bool
    {
        return app(Settings::class)->bool('photos.maintenance_required');
    }

    /**
     * The site photos of an Emergency / Regular outage that were handed in — any of the three.
     * Same input as {@see storeSitePhotos()}, with the positions of OutagePhoto::SITE_PHOTOS.
     *
     * @return Collection<int, OutagePhoto> the photos added now
     */
    private function storeOutagePhotos(Outage $outage, User $by, array $photos): Collection
    {
        return collect(array_keys(OutagePhoto::SITE_PHOTOS))
            ->filter(fn (string $position) => filled($photos[$position] ?? null))
            ->map(function (string $position) use ($outage, $by, $photos): OutagePhoto {
                $path = $this->photoPath($photos[$position]);

                if ($path === null) {
                    throw ValidationException::withMessages(["photos.{$position}" => 'The photo “'.OutagePhoto::SITE_PHOTOS[$position][0].'” could not be saved. Add it again.']);
                }

                return $this->createPhoto($outage, $by, OutagePhoto::SITE, $position, $photos);
            })->values();
    }

    private function createPhoto(Outage $outage, User $by, string $stage, string $position, array $photos): OutagePhoto
    {
        $source = $photos[$position.'_source'] ?? $photos['source'] ?? OutagePhoto::SOURCE_UPLOAD;
        $takenAt = $photos[$position.'_taken_at'] ?? null;

        return OutagePhoto::query()->create([
            'outage_id' => $outage->id,
            'stage' => $stage,
            'position' => $position,
            'path' => $this->photoPath($photos[$position]),
            'original_name' => $photos[$position.'_name'] ?? null,
            // Only the camera knows when a picture was taken; an uploaded file is dated by its upload.
            'taken_at' => $source === OutagePhoto::SOURCE_CAMERA && filled($takenAt) ? $takenAt : null,
            'source' => $source === OutagePhoto::SOURCE_CAMERA ? OutagePhoto::SOURCE_CAMERA : OutagePhoto::SOURCE_UPLOAD,
            'user_id' => $by->id,
        ]);
    }

    /** A photo already stored under outage-photos/ on the private disk — nothing else is accepted. */
    private function photoPath(mixed $value): ?string
    {
        $path = is_array($value) ? reset($value) : $value;

        return is_string($path) && Str::startsWith($path, 'outage-photos/') && ! str_contains($path, '..') && Storage::disk('local')->exists($path)
            ? $path
            : null;
    }

    private function hasAfterPhotos(Outage $outage): bool
    {
        return $outage->photos()->where('stage', OutagePhoto::AFTER)->distinct()->count('position') >= count(OutagePhoto::POSITIONS);
    }

    /** The technician on site owns the job: technician queue item assigned (and, when they raised it, accepted). */
    private function assignToFieldTechnician(Outage $outage, User $technician, User $by, string $reason, bool $transition): void
    {
        $team = $technician->teams()->orderBy('teams.id')->first();
        $teamId = $team?->parent_id ?? $team?->id;
        $subTeamId = $team?->parent_id ? $team->id : null;

        $outage->forceFill([
            'team_type' => $team?->team_type ?? TeamType::InHouse,
            'team_id' => $teamId,
            'sub_team_id' => $subTeamId,
            'dispatched_at' => now(),
            'dispatched_by' => $by->id,
        ])->save();

        $entry = $this->fifo->enqueue($outage, QueueType::Technician, ['team_id' => $teamId, 'sub_team_id' => $subTeamId, 'reason' => $reason]);
        $assignment = $this->fifo->assign($entry, $technician, AssignmentType::Dispatch, $by, $reason);

        if ($by->is($technician)) {
            $this->fifo->accept($assignment, $technician);
        }

        $outage->refresh();

        if ($transition) {
            $this->transition($outage, StatusCode::TeamDispatched, $by, $reason);
        }
    }

    /** Everyone: maintenance stakeholders (roles + e-mails in Maintenance Settings) in-app and by e-mail, plus rules. */
    private function announceFieldMaintenance(NotificationEvent $event, Outage $outage, array $context): void
    {
        $users = app(MaintenanceNotifier::class)->stakeholderUsers()
            ->merge(User::query()->active()->whereIn('id', array_filter((array) ($context['extra_user_ids'] ?? [])))->get())
            ->unique('id');
        unset($context['extra_user_ids']);

        $channels = [NotificationChannel::InApp->value, NotificationChannel::Mail->value];
        $this->notifier->notifyUsers($event, $outage, $users, $channels, $context);

        $userEmails = $users->pluck('email')->map(fn ($e) => Str::lower((string) $e))->all();
        $emails = collect((array) app(Settings::class)->get('maintenance.stakeholder_emails'))
            ->map(fn ($e) => Str::lower(trim((string) $e)))
            ->reject(fn (string $e) => in_array($e, $userEmails, true))->values()->all();

        if ($emails !== []) {
            $this->notifier->notifyTargets($event, $outage, ['emails' => $emails], [NotificationChannel::Mail->value], $context);
        }

        $this->notifier->dispatch($event, $outage, $context);
    }

    private function downtimeWindow(Outage $outage): string
    {
        if (! $outage->downtime_start) {
            return 'now';
        }

        return $outage->downtime_start->format('d/m/Y H:i').' – '
            .($outage->downtime_end?->isSameDay($outage->downtime_start) ? $outage->downtime_end->format('H:i') : $outage->downtime_end?->format('d/m/Y H:i')).' Hrs';
    }
}
