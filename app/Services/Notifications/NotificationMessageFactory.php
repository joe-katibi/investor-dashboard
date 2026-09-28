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
        };

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

    private function signProgress(array $context): string
    {
        return ($context['signed'] ?? '?').' of '.($context['total'] ?? '?');
    }
}
