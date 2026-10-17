<?php

namespace App\Services\Outages\Concerns;

use App\Enums\NotificationEvent;
use App\Enums\QueueType;
use App\Events\OutageUpdated;
use App\Models\Fdt;
use App\Models\Olt;
use App\Models\Outage;
use App\Models\Region;
use App\Models\User;
use App\Support\VisitPhotos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shifting / relocation visits (visit types marked "Relocation"):
 *
 *   technician on site → FTTH NOC provisions the service → back to the same technician → speed
 *   tests and smart-device photos → complete → dispatcher verifies and closes → CX Field Support
 *   confirms with the customer (the existing flow);
 *
 *   and, when the customer moves to another OLT or region, the dispatcher records the new OLT,
 *   address, road and landmark and hands the visit to a dispatcher of the new area.
 */
trait ManagesRelocations
{
    /**
     * The technician on site asks the FTTH NOC to provision the service: at the new location of a
     * relocation, or the new ONU (an ONU issue, or "ONU replaced" on any visit) — then with a photo of
     * the ONU's MAC address. $data: "onu_mac" (the stored path) and "onu_mac_name".
     */
    public function requestProvisioning(Outage $outage, User $by, string $note, array $data = []): Outage
    {
        $this->requireText($note, 'note', 'Say what the NOC should provision (ONU serial number, port…).');

        $result = DB::transaction(function () use ($outage, $by, $note, $data): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('visitProvisionRequest', $outage);
            $ticket = $this->ticketOf($outage);
            $this->fifo->authorizeWork($outage, QueueType::VisitTechnician, $by);

            // Anything but a relocation: a new ONU went in, and the NOC needs its MAC address.
            if (! VisitPhotos::isRelocation($outage)) {
                $ticket->forceFill(['onu_status' => VisitPhotos::ONU_REPLACED])->save();
                $outage->setRelation('ticket', $ticket);
                $this->storeTicketPhotos($outage, $by, ['onu_mac' => [$data['onu_mac'] ?? null, $data['onu_mac_name'] ?? null, VisitPhotos::short('onu_mac'), null, false]]);

                if (VisitPhotos::taken($outage)['onu_mac'] === null) {
                    throw ValidationException::withMessages(['onu_mac' => 'Take the photo of the MAC address at the back of the new ONU.']);
                }
            }

            $this->resumeTicketTimer($ticket);
            $ticket->forceFill(['provision_requested_at' => now(), 'provision_requested_by' => $by->id, 'provisioned_at' => null, 'provisioned_by' => null])->save();
            $outage->forceFill(['latest_update' => Str::limit("Provisioning requested: {$note}", 2000)])->save();
            $this->ticketEvent($outage, 'provision_requested', $by, QueueType::VisitTechnician, Str::limit($note, 500));
            // A step of the relocation, not an escalation: the visit still closes as an ordinary one.
            $this->moveTicket($outage, $ticket, QueueType::TicketNoc, $by, 'provision', $note, announce: false);
            $this->ticketUpdate($outage, "Sent to the FTTH NOC for provisioning: {$note}", $by, 'action', 'provision_requested');
            $this->notifier->dispatch(NotificationEvent::TicketRouted, $outage, ['reason' => "Provisioning requested: {$note}", 'queue' => QueueType::TicketNoc->getLabel()]);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /**
     * The NOC has provisioned the service: the visit goes back to the technician who asked (or the
     * technician queue, when that technician can no longer take it), who then takes the speed tests
     * and the smart-device photos and completes the visit.
     */
    public function confirmProvisioning(Outage $outage, User $by, ?string $note = null): Outage
    {
        $result = DB::transaction(function () use ($outage, $by, $note): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('visitProvisioned', $outage);
            $ticket = $this->ticketOf($outage);
            $this->fifo->authorizeWork($outage, QueueType::TicketNoc, $by);

            $note = filled($note) ? trim((string) $note) : null;
            $ticket->forceFill(['provisioned_at' => now(), 'provisioned_by' => $by->id])->save();

            $technician = User::query()->find($ticket->provision_requested_by ?? $outage->technician_id);
            $back = $technician && $technician->is_active && $technician->can(QueueType::VisitTechnician->defaultPermission()) ? $technician : null;
            $text = 'Provisioning done by the NOC'.($note ? ": {$note}" : '.').' Take the speed tests and the smart-device photos, then complete the visit.';

            $outage->forceFill(['latest_update' => Str::limit($text, 2000)])->save();
            $this->ticketEvent($outage, 'provisioned', $by, QueueType::TicketNoc, $note);
            $this->moveTicket($outage, $ticket, QueueType::VisitTechnician, $by, 'provision', $text, ['user' => $back], announce: false);
            $this->ticketUpdate($outage, $text.' Back to '.($back ? "technician {$back->name}" : 'the technician queue').'.', $by, 'action', 'provisioned');
            $this->notifier->dispatch(NotificationEvent::TicketReturned, $outage, ['reason' => $text, 'queue' => QueueType::VisitTechnician->getLabel()]);
            $this->tellTicketUsers(NotificationEvent::TicketReturned, $outage, [$back?->id], ['reason' => $text]);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /**
     * The customer moves to another OLT / region: the visit carries the new position and address, and
     * goes to a dispatcher of the new area (named, or the dispatch queue — people locked to regions
     * only get visits of theirs).
     *
     * @param  array{olt_id: mixed, fdt_id?: mixed, region_id?: mixed, address: ?string, road?: ?string, landmark?: ?string, dispatcher_id?: mixed, note?: ?string}  $data
     */
    public function relocateVisit(Outage $outage, User $by, array $data): Outage
    {
        $id = fn (mixed $value) => is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
        $olt = Olt::query()->find($id($data['olt_id'] ?? null))
            ?? throw ValidationException::withMessages(['olt_id' => 'Choose the OLT of the new location.']);
        $fdt = $id($data['fdt_id'] ?? null) ? Fdt::query()->where('olt_id', $olt->id)->find($id($data['fdt_id'])) : null;
        $regionId = $id($data['region_id'] ?? null) ?? $olt->region_id;
        $this->requireText($data['address'] ?? null, 'address', 'Enter the new address.');

        if ($regionId && ! Region::query()->whereKey($regionId)->exists()) {
            throw ValidationException::withMessages(['region_id' => 'Choose the region of the new location.']);
        }

        $result = DB::transaction(function () use ($outage, $by, $data, $olt, $fdt, $regionId, $id): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('visitRelocate', $outage);
            $ticket = $this->ticketOf($outage);

            if ($ticket->desk === QueueType::VisitDispatch) {
                $this->fifo->authorizeWork($outage, QueueType::VisitDispatch, $by);
            }

            $dispatcher = $id($data['dispatcher_id'] ?? null) ? $this->deskUser($id($data['dispatcher_id']), QueueType::VisitDispatch, $outage) : null;
            $before = $ticket->only(['olt_id', 'fdt_id', 'slot_id', 'port_id', 'region_id', 'address', 'road', 'landmark']);
            $from = collect([Olt::query()->whereKey($before['olt_id'])->value('name'), $before['address']])->filter()->implode(', ') ?: 'not on file';
            $to = collect([$olt->name, $fdt?->name, trim((string) $data['address']), filled($data['road'] ?? null) ? trim((string) $data['road']) : null])->filter()->implode(', ');
            $note = filled($data['note'] ?? null) ? trim((string) $data['note']) : null;

            $ticket->forceFill([
                'olt_id' => $olt->id, 'fdt_id' => $fdt?->id, 'slot_id' => null, 'port_id' => null, 'region_id' => $regionId,
                'address' => Str::limit(trim((string) $data['address']), 250, ''),
                'road' => filled($data['road'] ?? null) ? Str::limit(trim((string) $data['road']), 250, '') : null,
                'landmark' => filled($data['landmark'] ?? null) ? Str::limit(trim((string) $data['landmark']), 250, '') : $ticket->landmark,
                'relocated_at' => now(),
                // A technician of the old area had it: the new area starts from dispatch again.
                'en_route_at' => null, 'arrived_at' => null, 'paused_at' => null,
            ])->save();
            $outage->forceFill(['technician_id' => null])->save();

            $text = "Relocated from {$from} to {$to}".($note ? ". {$note}" : '.');
            $outage->forceFill(['latest_update' => Str::limit($text, 2000)])->save();
            $this->ticketEvent($outage, 'relocated', $by, $ticket->desk, Str::limit($text, 500), ['from' => $before, 'to' => $ticket->only(array_keys($before))]);
            $this->moveTicket($outage, $ticket, QueueType::VisitDispatch, $by, 'relocate', $text, ['user' => $dispatcher], announce: false);
            $this->ticketUpdate($outage, $text.' Sent to '.($dispatcher ? "dispatcher {$dispatcher->name}" : 'the dispatchers of the new area').' to assign a team.', $by, 'action', 'relocated');
            $this->notifier->dispatch(NotificationEvent::TicketRouted, $outage, ['reason' => $text, 'queue' => QueueType::VisitDispatch->getLabel()]);
            event(new OutageUpdated($outage->id, $outage->ticket_number));

            return $outage->refresh();
        });

        return $result->refresh();
    }
}
