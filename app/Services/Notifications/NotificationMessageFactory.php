<?php

namespace App\Services\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Outage;

/** Builds concise, operator-friendly notification text for each event. */
class NotificationMessageFactory
{
    /** @return array{title: string, body: string, sms: string} */
    public function make(NotificationEvent $event, Outage $outage, array $context = []): array
    {
        $outage->loadMissing(['status', 'priority', 'vertical', 'outageType', 'majorOutageType']);

        $ticket = $outage->ticket_number;
        $headline = "{$ticket} — {$outage->title}";

        $title = match ($event) {
            NotificationEvent::OutageCreated => "New outage {$ticket}",
            NotificationEvent::NocEscalation => "NOC review required: {$ticket}",
            NotificationEvent::NocConfirmed => "NOC confirmed {$ticket}",
            NotificationEvent::NocRejected => "NOC rejected {$ticket}",
            NotificationEvent::InfrastructureEscalation => "Escalated to Infrastructure: {$ticket}",
            NotificationEvent::Dispatched => "Team dispatched: {$ticket}",
            NotificationEvent::StatusChanged => "{$ticket} is now {$outage->status?->name}",
            NotificationEvent::RestoreReported => "Restoration reported: {$ticket}",
            NotificationEvent::RestoreConfirmed => "NOC restore confirmed: {$ticket}",
            NotificationEvent::Resolved => "Outage resolved: {$ticket}",
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
        };

        $lines = array_filter([
            $headline,
            'Type: '.collect([$outage->majorOutageType?->name, $outage->outageType?->name, $outage->vertical?->name])->filter()->implode(' / '),
            'Priority: '.($outage->priority?->name ?? 'Unset').' | Customers affected: '.number_format($outage->customers_affected),
            'Status: '.$outage->status?->name,
            $outage->sla_due_at ? 'SLA due: '.$outage->sla_due_at->format('Y-m-d H:i') : null,
            isset($context['reason']) && $context['reason'] ? 'Reason: '.$context['reason'] : null,
            isset($context['queue']) ? 'Queue: '.$context['queue'] : null,
            isset($context['attempts']) ? 'Attempts: '.$context['attempts'] : null,
        ]);

        return [
            'title' => $title,
            'body' => implode("\n", $lines),
            'sms' => mb_substr("{$title}. {$outage->title}. Impact {$outage->customers_affected}. Status: {$outage->status?->name}", 0, 300),
        ];
    }
}
