<?php

namespace App\Enums;

use App\Models\Vertical;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The operational FIFO queues an outage passes through (spec §27.5 / §28).
 */
enum QueueType: string implements HasColor, HasLabel
{
    case NocReview = 'noc_review';
    case Infrastructure = 'infrastructure';
    case Technician = 'technician';
    case NocRestore = 'noc_restore';
    case CxFollowUp = 'cx_follow_up';
    case SupportTechnician = 'support_technician';
    case ItReview = 'it_review';
    case ItConfirmation = 'it_confirmation';
    case CxNotify = 'cx_notify';

    // 4G NOC: the engineer / technician working a ticket, and the NOC verifying a reported fix.
    case MobilityEngineer = 'mobility_engineer';
    case MobilityNoc = 'mobility_noc';

    // Customer tickets: the desks a ticket moves between.
    case CxTier2 = 'cx_tier2';
    case Billing = 'billing';
    case BillingManager = 'billing_manager';
    case Commercial = 'commercial';
    case CxManager = 'cx_manager';
    case CxCallback = 'cx_callback';
    case TicketNoc = 'ticket_noc';

    // 4G customer tickets: the 4G NOC, then the IP, RF and Core teams (who may call in a vendor).
    case Ticket4gNoc = 'ticket_4g_noc';
    case TicketIp = 'ticket_ip';
    case TicketRf = 'ticket_rf';
    case TicketCore = 'ticket_core';

    // Site visits: dispatch, the field, the desks a visit is escalated to, and the customer call-back.
    case VisitDispatch = 'visit_dispatch';
    // Shifting / Relocation visits have a dispatch desk of their own (visit types kept for some dispatchers).
    case ShiftingDispatch = 'shifting_dispatch';
    case VisitTechnician = 'visit_technician';
    case InfraSupportDispatch = 'infra_support_dispatch';
    case InfraSupportTechnician = 'infra_support_tech';
    case Design = 'design';
    case Access = 'access';
    case CxFieldSupport = 'cx_field_support';

    // Tickets pending past the follow-up trigger (9 hours): a call to the customer, alongside the main work.
    case TicketFollowUp = 'ticket_follow_up';

    // 72 hours after CX Field Support confirms a site visit: a call to check the service is still fine (the visit stays closed).
    case VisitAftercare = 'visit_aftercare';

    // A step of a custom workflow: one queue type, each entry for the team (and with the name) of its step.
    case CustomStep = 'custom_step';

    public function getLabel(): string
    {
        return match ($this) {
            self::NocReview => 'NOC Review',
            self::Infrastructure => 'Infrastructure Dispatch',
            self::Technician => 'Technician',
            self::NocRestore => 'NOC Restore Confirmation',
            self::CxFollowUp => 'CX Follow-up',
            self::SupportTechnician => 'Support Technician',
            self::ItReview => 'IT Review',
            self::ItConfirmation => 'IT Confirmation',
            self::CxNotify => 'CX Customer Notification',
            self::MobilityEngineer => '4G Engineer / Technician',
            self::MobilityNoc => '4G NOC Verification',
            self::CxTier2 => 'CX Tier II',
            self::Billing => 'Billing',
            self::BillingManager => 'Billing Manager',
            self::Commercial => 'Commercial / Sales Admin',
            self::CxManager => 'CX Manager',
            self::CxCallback => 'CX Frontline Follow-up',
            self::TicketNoc => 'NOC (customer tickets)',
            self::Ticket4gNoc => '4G NOC (customer tickets)',
            self::TicketIp => 'IP Team',
            self::TicketRf => 'RF Team',
            self::TicketCore => 'Core Team',
            self::VisitDispatch => 'Site Visit Dispatch',
            self::ShiftingDispatch => 'Shifting Dispatch',
            self::VisitTechnician => 'Site Visit Technician',
            self::InfraSupportDispatch => 'Infrastructure Support Dispatch',
            self::InfraSupportTechnician => 'Infrastructure Support Technician',
            self::Design => 'Design',
            self::Access => 'Access Team',
            self::CxFieldSupport => 'CX Field Support',
            self::TicketFollowUp => 'Pending Ticket Follow-up',
            self::VisitAftercare => 'CX Follow-up – Site Visit (72 h)',
            self::CustomStep => 'Custom Workflow Step',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NocReview => 'info',
            self::Infrastructure => 'warning',
            self::Technician => 'primary',
            self::NocRestore => 'success',
            self::CxFollowUp => 'danger',
            self::SupportTechnician => 'warning',
            self::ItReview, self::ItConfirmation => 'gray',
            self::CxNotify => 'success',
            self::MobilityEngineer => 'primary',
            self::MobilityNoc => 'success',
            self::CxTier2, self::CxCallback, self::CxFieldSupport => 'info',
            self::Billing, self::BillingManager, self::Commercial, self::CxManager => 'warning',
            self::TicketNoc, self::Design, self::Access => 'gray',
            self::Ticket4gNoc => 'success',
            self::TicketIp, self::TicketRf, self::TicketCore => 'primary',
            self::VisitDispatch, self::ShiftingDispatch, self::InfraSupportDispatch => 'warning',
            self::VisitTechnician, self::InfraSupportTechnician => 'primary',
            self::TicketFollowUp => 'danger',
            self::VisitAftercare => 'success',
            self::CustomStep => 'info',
        };
    }

    /** Permission a user needs to receive tickets from this queue. */
    public function defaultPermission(): string
    {
        return match ($this) {
            self::NocReview => 'queue.work_noc',
            self::Infrastructure => 'queue.work_infrastructure',
            self::Technician => 'queue.work_technician',
            self::NocRestore => 'queue.work_noc',
            self::CxFollowUp, self::CxNotify => 'queue.work_cx',
            self::SupportTechnician => 'queue.work_support',
            self::ItReview, self::ItConfirmation => 'queue.work_it',
            self::MobilityEngineer => 'queue.work_mobility_engineer',
            self::MobilityNoc => 'queue.work_mobility_noc',
            // Customer tickets and site visits: one permission per desk.
            default => 'queue.work_'.$this->value,
        };
    }

    /** Permission to assign / reassign tickets of this queue by hand and act on them without holding them. */
    public function overridePermission(): string
    {
        return $this->isTicketing() ? 'ticket.override' : 'queue.override';
    }

    /**
     * Department code that normally owns the queue. Ticket desks have none: who works a desk is
     * decided by the desk permission (and regions); a department can still be set on the queue.
     */
    public function defaultDepartmentCode(): string
    {
        return match ($this) {
            self::NocReview, self::NocRestore => 'NOC',
            self::Infrastructure, self::Technician => 'INFRA',
            self::CxFollowUp, self::CxNotify => 'CX',
            self::SupportTechnician => 'DISPATCH',
            self::ItReview, self::ItConfirmation => 'IT',
            self::MobilityEngineer, self::MobilityNoc => 'MOBILITY',
            default => '',
        };
    }

    /**
     * Parallel queues run alongside the main flow on the same ticket (a support technician
     * works the drop cable while the outage technician works the live line). They never
     * take over the ticket's current queue / owner.
     */
    public function isParallel(): bool
    {
        return in_array($this, [self::SupportTechnician, self::TicketFollowUp, self::VisitAftercare], true);
    }

    public function isMobility(): bool
    {
        return in_array($this, [self::MobilityEngineer, self::MobilityNoc], true);
    }

    /** A step of a workflow built from the screen: worked by the members of the step's team. */
    public function isCustom(): bool
    {
        return $this === self::CustomStep;
    }

    /** A desk of the customer ticketing / site visit module. */
    public function isTicketing(): bool
    {
        return in_array($this, self::ticketing(), true);
    }

    /**
     * The vertical a ticket desk serves (Vertical::FTTH / Vertical::MOBILITY_4G); null = tickets of any
     * vertical (CX Tier II, billing, approvals, call-backs). A 4G ticket never goes to the FTTH NOC or a
     * site visit desk, an FTTH ticket never to the 4G NOC or its IP / RF / Core teams.
     */
    public function verticalCode(): ?string
    {
        return match ($this) {
            self::Ticket4gNoc, self::TicketIp, self::TicketRf, self::TicketCore => Vertical::MOBILITY_4G,
            self::TicketNoc, self::VisitDispatch, self::ShiftingDispatch, self::VisitTechnician, self::InfraSupportDispatch, self::InfraSupportTechnician,
            self::Design, self::Access, self::CxFieldSupport, self::VisitAftercare => Vertical::FTTH,
            default => null,
        };
    }

    /** Whether a ticket of this vertical (its code) may be at this desk. */
    public function serves(?string $verticalCode): bool
    {
        return $this->verticalCode() === null || $verticalCode === null || $this->verticalCode() === $verticalCode;
    }

    /** The 4G engineering teams: they work a 4G ticket themselves or escalate it to one of their vendors. */
    public function isEngineeringTeam(): bool
    {
        return in_array($this, [self::TicketIp, self::TicketRf, self::TicketCore], true);
    }

    /** The vendor type that serves an engineering team (Vendor::TYPES). */
    public function vendorType(): ?string
    {
        return match ($this) {
            self::TicketIp => 'ip',
            self::TicketRf => 'rf',
            self::TicketCore => 'core',
            default => null,
        };
    }

    /** Desks where a request is approved or declined (refund, credit, billing escalation). */
    public function isApprovalDesk(): bool
    {
        return in_array($this, [self::Commercial, self::CxManager, self::BillingManager], true);
    }

    /** A dispatch desk of site visits: Site Visit Dispatch, or Shifting Dispatch for Shifting / Relocation visits. */
    public function isVisitDispatch(): bool
    {
        return $this === self::VisitDispatch || $this === self::ShiftingDispatch;
    }

    /** Desks where one named person does field work (the technician steps). */
    public function isFieldDesk(): bool
    {
        return in_array($this, [self::VisitTechnician, self::InfraSupportTechnician], true);
    }

    /** @return list<self> */
    public static function ticketing(): array
    {
        return [
            self::CxTier2, self::Billing, self::BillingManager, self::Commercial, self::CxManager, self::CxCallback, self::TicketNoc,
            self::Ticket4gNoc, self::TicketIp, self::TicketRf, self::TicketCore,
            self::VisitDispatch, self::ShiftingDispatch, self::VisitTechnician, self::InfraSupportDispatch, self::InfraSupportTechnician, self::Design, self::Access,
            self::CxFieldSupport, self::TicketFollowUp, self::VisitAftercare,
        ];
    }

    /** Desks a ticket can sit at (everything except the parallel follow-up queue), value => label. */
    public static function deskOptions(): array
    {
        return collect(self::ticketing())
            ->reject(fn (self $desk) => $desk->isParallel())
            ->mapWithKeys(fn (self $desk) => [$desk->value => $desk->getLabel()])
            ->all();
    }
}
