<?php

namespace App\Services\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Outage;
use Illuminate\Support\Facades\Auth;

/** Builds concise, operator-friendly notification text for each event. */
class NotificationMessageFactory
{
    /** @return array{title: string, body: string, sms: string} */
    public function make(NotificationEvent $event, Outage $outage, array $context = []): array
    {
        $outage->loadMissing(['status', 'priority', 'vertical', 'outageType', 'majorOutageType', 'currentAssignee', 'team', 'subTeam', 'olts']);

        $ticket = $outage->ticket_number;
        $headline = "{$ticket} — {$outage->title}";

        $title = match ($event) {
            NotificationEvent::OutageCreated => "New outage ticket created: {$ticket}",
            NotificationEvent::NocEscalation => "Assigned to NOC for review: {$ticket}",
            NotificationEvent::NocConfirmed => "NOC confirmed outage: {$ticket}",
            NotificationEvent::NocRejected => "NOC rejected outage: {$ticket}",
            NotificationEvent::InfrastructureEscalation => "Assigned to Infrastructure: {$ticket}",
            NotificationEvent::Dispatched => "Dispatched to field team: {$ticket}",
            NotificationEvent::StatusChanged => "{$ticket} is now {$outage->status?->name}",
            NotificationEvent::UpdatePosted => "Update on {$ticket}",
            NotificationEvent::RestoreReported => "Closed by outage technician — restoration reported: {$ticket}",
            NotificationEvent::RestoreConfirmed => "NOC confirmed restoration: {$ticket}",
            NotificationEvent::Resolved => "Outage resolved and closed: {$ticket}",
            NotificationEvent::PriorityChanged => "Priority changed to {$outage->priority?->name}: {$ticket}",
            NotificationEvent::Assigned => "Ticket assigned to you: {$ticket}",
            NotificationEvent::AssignmentRejected => "Assignment rejected: {$ticket}",
            NotificationEvent::AssignmentTimeout => "Acknowledgement timeout: {$ticket}",
            NotificationEvent::QueueEscalation => "Queue escalation: {$ticket}",
            NotificationEvent::SlaPreBreach => "SLA approaching breach: {$ticket}",
            NotificationEvent::SlaBreach => "SLA BREACHED: {$ticket}",
            NotificationEvent::SlaEscalation => "SLA escalation: {$ticket}",
            NotificationEvent::DeskSlaPreBreach => 'Almost out of time at '.($context['queue'] ?? 'its desk').": {$ticket}",
            NotificationEvent::DeskSlaBreach => 'DESK SLA BREACHED at '.($context['queue'] ?? 'its desk').": {$ticket}",
            NotificationEvent::FieldSlaPreBreach => "Not yet on the field: {$ticket}",
            NotificationEvent::FieldSlaBreach => "NOT ON THE FIELD within the target: {$ticket}",
            NotificationEvent::CreditDue => "Customer credit may be due: {$ticket}",
            NotificationEvent::CreditShared => "Credit shared: {$ticket}",
            NotificationEvent::SmsSent => "Customer SMS sent: {$ticket}",
            NotificationEvent::ScheduledReport => 'Scheduled report',
            NotificationEvent::CxFollowUp => "CX follow-up recorded: {$ticket}",
            NotificationEvent::SiteVisitRaised => "Site visit raised: {$ticket}",
            NotificationEvent::SupportRequested => "Support technician requested on site: {$ticket}",
            NotificationEvent::SupportCompleted => "Support technician work completed: {$ticket}",
            NotificationEvent::ItEscalation => "Assigned to IT for review: {$ticket}",
            NotificationEvent::ReturnedToIt => "NOC resolved — returned to IT to confirm: {$ticket}",
            NotificationEvent::MaintenanceRequested => "Planned maintenance requested: {$ticket}",
            NotificationEvent::CrfSignoffRequested => "CRF sent for sign-off: {$ticket}",
            NotificationEvent::CrfSigned => "CRF signed ({$this->signProgress($context)}): {$ticket}",
            NotificationEvent::CrfChangesRequested => "CRF changes requested: {$ticket}",
            NotificationEvent::CrfApproved => "CRF fully signed — maintenance approved: {$ticket}",
            NotificationEvent::MaintenanceReminder => 'Planned maintenance '.($context['time_left'] ?? 'soon').": {$ticket}",
            NotificationEvent::MaintenanceStarted => "Planned maintenance has started: {$ticket}",
            NotificationEvent::MaintenanceRescheduled => "Planned maintenance rescheduled: {$ticket}",
            NotificationEvent::MaintenanceCompleted => "Planned maintenance completed: {$ticket}",
            NotificationEvent::MaintenanceCancelled => "Planned maintenance cancelled: {$ticket}",
            NotificationEvent::PreventiveRequested => "Preventive maintenance needs your approval: {$ticket}",
            NotificationEvent::PreventiveApproved => "Preventive maintenance downtime approved: {$ticket}",
            NotificationEvent::PreventiveRejected => "Preventive maintenance rejected: {$ticket}",
            NotificationEvent::CorrectiveRaised => "Corrective maintenance in progress: {$ticket}",
            NotificationEvent::MobilityVendorNotified => "Vendor notified by e-mail: {$ticket}",
            NotificationEvent::MobilityVendorMailFailed => "Vendor notification e-mail FAILED: {$ticket}",
            NotificationEvent::MobilityVendorUpdate => "Vendor update recorded: {$ticket}",
            NotificationEvent::MobilityVendorSilent => "No vendor update — follow up with the vendor: {$ticket}",
            NotificationEvent::MobilityVendorEscalation => "ESCALATION — vendor has not updated: {$ticket}",
            NotificationEvent::MobilityResolvedPending => "Resolved — pending NOC confirmation: {$ticket}",
            NotificationEvent::MobilityVerificationFailed => "NOC could not verify the fix — back to work: {$ticket}",
            NotificationEvent::MobilityReopened => "Ticket reopened: {$ticket}",
            NotificationEvent::MobilityCancelled => "Ticket cancelled: {$ticket}",
            NotificationEvent::MobilityEscalated => "Ticket escalated by NOC: {$ticket}",
            NotificationEvent::TicketRouted => 'Ticket escalated to '.($context['queue'] ?? 'another desk').": {$ticket}",
            NotificationEvent::TicketReturned => 'Ticket returned to '.($context['queue'] ?? 'the previous desk').": {$ticket}",
            NotificationEvent::TicketApproval => "Request approved / declined: {$ticket}",
            NotificationEvent::TicketFollowUpDue => "Pending past the follow-up time — call the customer: {$ticket}",
            NotificationEvent::TicketCreditReady => "Ready for credit: {$ticket}",
            NotificationEvent::TicketReopened => "Ticket reopened: {$ticket}",
            NotificationEvent::TicketIntervention => "Team leader intervention: {$ticket}",
            NotificationEvent::VisitScheduled => "Site visit assigned to a technician: {$ticket}",
            NotificationEvent::VisitRescheduled => "Site visit rescheduled: {$ticket}",
            NotificationEvent::VisitCompleted => "Site visit completed — dispatcher to verify: {$ticket}",
            NotificationEvent::TicketCustomerSms => "Customer SMS: {$ticket}",
            NotificationEvent::TicketVendorEscalated => 'Escalated to vendor '.($context['vendor'] ?? '').": {$ticket}",
            NotificationEvent::TicketVendorFeedback => 'Vendor feedback from '.($context['vendor'] ?? 'the vendor').": {$ticket}",
            NotificationEvent::TicketProgressDue => 'Update due — no update for '.($context['minutes'] ?? 20)." minutes: {$ticket}",
        };

        if ($outage->isTicket()) {
            $kind = $outage->isSiteVisit() ? 'site visit' : 'customer ticket';

            return $this->ticket($event, $outage, $context, match ($event) {
                NotificationEvent::OutageCreated => 'New '.$kind.": {$ticket}",
                NotificationEvent::Resolved => ucfirst($kind)." closed: {$ticket}",
                NotificationEvent::SlaPreBreach => "SLA at risk: {$ticket}",
                default => $title,
            });
        }

        if ($outage->isMobility()) {
            return $this->mobility($event, $outage, $context, match ($event) {
                NotificationEvent::OutageCreated => "New 4G outage ticket: {$ticket}",
                NotificationEvent::Resolved => "4G outage verified and closed: {$ticket}",
                default => $title,
            });
        }

        $owner = $outage->currentAssignee?->name
            ?? collect([$outage->team?->name, $outage->subTeam?->name])->filter()->implode(' / ');

        $lines = array_filter([
            $headline,
            'Type: '.collect([$outage->majorOutageType?->name, $outage->outageType?->name, $outage->vertical?->name])->filter()->implode(' / '),
            'Priority: '.($outage->priority?->name ?? 'Unset').' | Customers affected: '.number_format($outage->customers_affected),
            'Status: '.$outage->status?->name,
            ($where = $outage->olts->pluck('name')->implode(', ') ?: $outage->location) ? 'Location: '.$where : null,
            $owner ? 'Assigned to: '.$owner : null,
            $outage->sla_due_at && ! $outage->is_closed ? 'SLA due: '.$outage->sla_due_at->format('Y-m-d H:i') : null,
            $outage->final_reason && $outage->is_closed ? 'Final reason / RFO: '.$outage->final_reason : null,
            isset($context['reason']) && $context['reason'] ? ($event === NotificationEvent::UpdatePosted ? 'Update: ' : 'Note: ').$context['reason'] : null,
            isset($context['queue']) ? 'Queue: '.$context['queue'] : null,
            isset($context['attempts']) ? 'Attempts: '.$context['attempts'] : null,
            'By: '.($context['by'] ?? Auth::user()?->name ?? 'System'),
        ]);

        // SMS: short, plain text, most important facts first; trimmed to the configured length.
        $sms = collect([
            'OMS: '.$title,
            $outage->title,
            'Impact '.number_format($outage->customers_affected).' cust, '.($outage->priority?->name ?? 'no priority'),
            'Status: '.$outage->status?->name,
            $owner ? 'Owner: '.$owner : null,
            isset($context['reason']) && $context['reason'] ? str($context['reason'])->squish()->limit(80)->toString() : null,
        ])->filter()->implode('. ');

        if (isset($context['window'])) {
            array_splice($lines, 1, 0, ['Maintenance window: '.$context['window']]);
        }

        return [
            'title' => $title,
            'body' => implode("\n", $lines),
            'sms' => mb_strimwidth($sms, 0, max(70, (int) config('oms.sms.max_length', 306)), '…'),
        ];
    }

    /**
     * 4G NOC ticket: sites / transmission nodes, severity by elements affected, engineer or vendor.
     *
     * @return array{title: string, body: string, sms: string}
     */
    private function mobility(NotificationEvent $event, Outage $outage, array $context, string $title): array
    {
        // Whole models: the workflow keeps using this ticket instance after notifying.
        $outage->loadMissing(['enodebs', 'transmissionNodes', 'vendor', 'technician:id,name']);

        $owner = $outage->isVendorTicket()
            ? ($outage->vendor ? 'Vendor '.$outage->vendor->name : null)
            : ($outage->currentAssignee?->name ?? $outage->technician?->name);
        $where = $outage->sitesLabel(4) ?: $outage->location;
        $note = $context['reason'] ?? null;

        $lines = array_filter([
            "{$outage->ticket_number} — {$outage->title}",
            'Type: '.collect([$outage->outageType?->name, $outage->vertical?->name])->filter()->implode(' / '),
            'Severity: '.($outage->priority?->name ?? 'Unset').(($impact = $outage->impactLabel()) ? " | Affected: {$impact}" : ''),
            'Status: '.$outage->status?->name,
            $where ? 'Site(s): '.$where : null,
            ($nodes = $outage->nodesLabel(4)) ? 'Transmission: '.$nodes : null,
            $owner ? ($outage->isVendorTicket() ? 'With: ' : 'Assigned to: ').$owner : null,
            $outage->vendor_eta_at && ! $outage->is_closed ? 'Vendor ETA: '.$outage->vendor_eta_at->format('Y-m-d H:i') : null,
            $outage->sla_due_at && ! $outage->is_closed ? 'SLA due: '.$outage->sla_due_at->format('Y-m-d H:i') : null,
            $outage->is_closed && $outage->root_cause ? 'Root cause: '.$outage->root_cause : null,
            $outage->is_closed && $outage->resolution ? 'Resolution: '.$outage->resolution : null,
            $note ? ($event === NotificationEvent::UpdatePosted ? 'Update: ' : 'Note: ').$note : null,
            isset($context['queue']) ? 'Queue: '.$context['queue'] : null,
            'By: '.($context['by'] ?? Auth::user()?->name ?? 'System'),
        ]);

        $sms = collect([
            'OMS 4G: '.$title,
            $outage->title,
            ($outage->priority?->name ?? 'no severity').($impact ? ', '.$impact : ''),
            'Status: '.$outage->status?->name,
            $owner,
            $note ? str($note)->squish()->limit(80)->toString() : null,
        ])->filter()->implode('. ');

        return [
            'title' => $title,
            'body' => implode("\n", $lines),
            'sms' => mb_strimwidth($sms, 0, max(70, (int) config('oms.sms.max_length', 306)), '…'),
        ];
    }

    /**
     * Customer ticket / site visit: who the customer is, what they reported, the desk and person it is with.
     *
     * @return array{title: string, body: string, sms: string}
     */
    private function ticket(NotificationEvent $event, Outage $outage, array $context, string $title): array
    {
        // Whole models: the workflow keeps using this ticket instance after notifying.
        $outage->loadMissing(['ticket.subcategory', 'ticket.category', 'ticket.visitType', 'ticket.olt', 'ticket.region', 'technician']);
        $details = $outage->ticket;
        $desk = $outage->current_queue?->getLabel();
        $owner = $outage->currentAssignee?->name;
        $note = $context['reason'] ?? null;
        $customer = trim(($details?->customer_name ?: 'Customer').($details?->account_number ? " ({$details->account_number})" : ''));

        $lines = array_filter([
            "{$outage->ticket_number} — {$outage->title}",
            'Customer: '.$customer.(($phones = implode(', ', $details?->phones() ?? [])) ? ' | Tel: '.$phones : ''),
            'Issue: '.collect([$details?->category?->name, $details?->subcategory?->name, $details?->visitType?->name])->filter()->implode(' / '),
            ($where = collect([$details?->region?->name, $details?->olt?->name, $outage->location])->filter()->implode(' | ')) ? 'Where: '.$where : null,
            'Status: '.$outage->status?->name.($desk ? " | Desk: {$desk}" : ''),
            $owner ? 'Assigned to: '.$owner : null,
            $details?->scheduled_at && ! $outage->is_closed ? 'Visit booked for: '.$details->scheduled_at->format('Y-m-d H:i') : null,
            $outage->sla_due_at && ! $outage->is_closed ? 'SLA due: '.$outage->sla_due_at->format('Y-m-d H:i') : null,
            $outage->is_closed && $outage->final_reason ? 'Final reason: '.$outage->final_reason : null,
            $note ? ($event === NotificationEvent::UpdatePosted ? 'Update: ' : 'Note: ').$note : null,
            isset($context['attempts']) ? 'Attempts: '.$context['attempts'] : null,
            'By: '.($context['by'] ?? Auth::user()?->name ?? 'System'),
        ]);

        $sms = collect([
            'OMS: '.$title,
            $customer,
            $details?->subcategory?->name ?? $details?->visitType?->name,
            'Status: '.$outage->status?->name,
            $owner ?? $desk,
            $note ? str($note)->squish()->limit(80)->toString() : null,
        ])->filter()->implode('. ');

        return [
            'title' => $title,
            'body' => implode("\n", $lines),
            'sms' => mb_strimwidth($sms, 0, max(70, (int) config('oms.sms.max_length', 306)), '…'),
        ];
    }

    private function signProgress(array $context): string
    {
        return ($context['signed'] ?? '?').' of '.($context['total'] ?? '?');
    }
}
