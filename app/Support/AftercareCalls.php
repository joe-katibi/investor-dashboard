<?php

namespace App\Support;

/**
 * The 72-hour follow-up call after a site visit (CX Follow-up – Site Visit): what the agent records.
 *
 *   Disposition (main → sub)  e.g. Dissatisfied → Slow Speed
 *   Call outcome              Connected / Unreachable / Callback required — follows from the disposition
 *   Customer feedback         Satisfied / Dissatisfied / Vandalism or Damage / Service not yet in use / Other
 *   Action required           what happens next: nothing, a revisit (the visit is reopened), a new ticket…
 *
 * Vandalism is kept apart from dissatisfaction, so the reports can count both.
 */
final class AftercareCalls
{
    /** main => [sub key => [label, description]] */
    public const DISPOSITIONS = [
        'Satisfied' => [
            'satisfied_service' => ['Satisfied – Service Working', 'Customer confirms the service is working well.'],
            'satisfied_installation' => ['Satisfied – Installation Experience', 'Customer is happy with the installation process.'],
            'satisfied_technician' => ['Satisfied – Technician Conduct', "Customer is satisfied with the technician's conduct."],
            'satisfied_overall' => ['Satisfied – Overall Experience', 'Customer is happy with both the service and installation.'],
        ],
        'Dissatisfied' => [
            'dissatisfied_slow' => ['Dissatisfied – Slow Speed', 'Customer reports slow internet speeds.'],
            'dissatisfied_no_internet' => ['Dissatisfied – No Internet', 'Customer has no internet connection.'],
            'dissatisfied_intermittent' => ['Dissatisfied – Intermittent Connection', 'Customer reports frequent disconnections.'],
            'dissatisfied_wifi' => ['Dissatisfied – Wi-Fi Coverage', 'Customer reports poor Wi-Fi coverage.'],
            'dissatisfied_installation' => ['Dissatisfied – Installation Quality', 'Customer is unhappy with the installation quality.'],
            'dissatisfied_technician' => ['Dissatisfied – Technician Conduct', "Customer complains about the technician's conduct."],
            'dissatisfied_expectations' => ['Dissatisfied – Service Expectations', 'Service does not meet customer expectations.'],
            'dissatisfied_other' => ['Dissatisfied – Other', 'Any other complaint raised by the customer.'],
        ],
        'Vandalism / Damage' => [
            'vandalism_fibre' => ['Fibre Cable Vandalized', 'Customer reports vandalism affecting the fibre cable.'],
            'vandalism_fibre_cut' => ['Fibre Cable Cut', 'Fibre cable is cut or damaged.'],
            'vandalism_drop_cable' => ['Drop Cable Damaged', 'Cable connecting the customer to the network is damaged.'],
            'vandalism_equipment' => ['Equipment Damaged', 'ONU/router or other installed equipment is damaged.'],
            'vandalism_suspected' => ['Suspected Vandalism', 'Customer suspects intentional damage to network equipment or cables.'],
        ],
        'Pending Resolution' => [
            'pending_fault_ticket' => ['Fault Reported – Ticket Raised', 'A fault has been reported and a ticket created.'],
            'pending_existing_ticket' => ['Existing Ticket – Follow-Up Required', 'Customer has an unresolved issue under an existing ticket.'],
            'pending_revisit' => ['Technician Revisit Required', 'A technician needs to revisit the customer.'],
            'pending_noc' => ['NOC Intervention Required', 'The issue requires investigation by the Network Operations Centre.'],
            'pending_education' => ['Customer Education Required', 'Customer needs assistance with Wi-Fi, equipment, or service usage.'],
        ],
        'Unreachable' => [
            'unreachable_no_answer' => ['No Answer', 'Customer does not answer the call.'],
            'unreachable_switched_off' => ['Phone Switched Off', "Customer's phone is switched off."],
            'unreachable_not_reachable' => ['Number Not Reachable', "Customer's number cannot be reached."],
            'unreachable_wrong_number' => ['Wrong Number', 'Contact number is incorrect.'],
            'unreachable_callback_requested' => ['Call Back Requested', 'Customer requests a callback at a later time.'],
            'unreachable_callback_scheduled' => ['Callback Scheduled', 'A specific callback has been arranged.'],
        ],
        'Other Outcomes' => [
            'other_declined' => ['Customer Declined Feedback', 'Customer does not wish to provide feedback.'],
            'other_not_in_use' => ['Service Not Yet in Use', 'Customer has not started using the service.'],
            'other_not_installed' => ['Service Not Installed', 'Installation is incomplete or was not completed.'],
        ],
    ];

    public const CALL_OUTCOMES = ['connected' => 'Connected', 'unreachable' => 'Unreachable', 'callback' => 'Callback Required'];

    public const FEEDBACK = [
        'satisfied' => 'Satisfied', 'dissatisfied' => 'Dissatisfied', 'vandalism' => 'Vandalism or Damage',
        'not_in_use' => 'Service Not Yet in Use', 'other' => 'Other',
    ];

    /** What happens next: [label, effect]. */
    public const ACTIONS = [
        'no_action' => 'No Action — close with the feedback',
        'existing_ticket' => 'Existing Ticket Follow-Up — close; the open ticket carries on',
        'revisit' => 'Technician Revisit — same issue: reopen the visit for a team',
        'noc' => 'NOC Escalation — same issue: reopen the visit (dispatch sends it to the NOC)',
        'raise_ticket' => 'Raise Ticket — a different issue: a new ticket for it',
        'callback' => 'Callback — call again later',
    ];

    /** The actions that reopen the visit (the same issue is back). */
    public const REOPENS = ['revisit', 'noc'];

    public const QUALITY = ['satisfactory' => 'Satisfactory', 'unsatisfactory' => 'Unsatisfactory'];

    /** sub key => "Main: Sub" for a grouped pick-list: main => [key => label]. */
    public static function options(): array
    {
        return array_map(fn (array $subs) => array_map(fn (array $sub) => $sub[0], $subs), self::DISPOSITIONS);
    }

    public static function main(?string $disposition): ?string
    {
        foreach (self::DISPOSITIONS as $main => $subs) {
            if (isset($subs[$disposition])) {
                return $main;
            }
        }

        return null;
    }

    public static function label(?string $disposition): ?string
    {
        $main = self::main($disposition);

        return $main ? self::DISPOSITIONS[$main][$disposition][0] : null;
    }

    public static function callOutcome(string $disposition): string
    {
        return match (true) {
            in_array($disposition, ['unreachable_callback_requested', 'unreachable_callback_scheduled'], true) => 'callback',
            self::main($disposition) === 'Unreachable' => 'unreachable',
            default => 'connected',
        };
    }

    public static function feedback(string $disposition): ?string
    {
        return match (self::main($disposition)) {
            'Satisfied' => 'satisfied',
            'Dissatisfied', 'Pending Resolution' => 'dissatisfied',
            'Vandalism / Damage' => 'vandalism',
            'Other Outcomes' => $disposition === 'other_not_in_use' ? 'not_in_use' : 'other',
            default => null, // not reached
        };
    }

    /** The action a disposition suggests (the agent may choose another). */
    public static function suggestedAction(?string $disposition): string
    {
        return match (true) {
            $disposition === null => 'no_action',
            self::callOutcome($disposition) !== 'connected' => 'callback',
            $disposition === 'pending_revisit' => 'revisit',
            $disposition === 'pending_noc' => 'noc',
            $disposition === 'pending_existing_ticket' => 'existing_ticket',
            // 72 hours after the visit, a complaint is as a rule the same issue back; damage is a new fault.
            self::feedback($disposition) === 'dissatisfied' => 'revisit',
            self::feedback($disposition) === 'vandalism' => 'raise_ticket',
            default => 'no_action',
        };
    }
}
