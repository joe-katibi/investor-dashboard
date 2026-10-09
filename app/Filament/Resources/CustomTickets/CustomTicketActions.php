<?php

namespace App\Filament\Resources\CustomTickets;

use App\Enums\QueueType;
use App\Filament\Resources\Outages\OutageActions;
use App\Models\Outage;
use App\Models\User;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Services\Outages\OutageWorkflow;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/** What can be done on a ticket of a custom workflow. The rules are in OutagePolicy and the workflow service. */
class CustomTicketActions
{
    public static function complete(): Action
    {
        $last = fn (Outage $record) => (bool) $record->customTicket?->isLastStep();
        $needsNote = fn (Outage $record) => (bool) ($record->customTicket?->step()['note_required'] ?? false)
            || ($last($record) && ($record->customTicket?->flow?->requires_resolution ?? true));

        return Action::make('customComplete')
            ->label(fn (Outage $record) => $last($record) ? 'Complete ticket' : 'Complete step')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Outage $record) => Auth::user()?->can('workCustomStep', $record))
            ->modalHeading(fn (Outage $record) => $record->customTicket?->progressLabel())
            ->modalDescription(fn (Outage $record) => $record->customTicket?->step()['instructions'] ?? null)
            ->schema(fn (Outage $record) => [
                Textarea::make('note')->label($last($record) ? 'How was it resolved?' : 'Note for the next step')->rows(4)->maxLength(5000)->required($needsNote($record)),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->completeCustomStep($record, Auth::user(), $data['note'] ?? null), 'Step completed'));
    }

    public static function sendBack(): Action
    {
        return Action::make('customSendBack')
            ->label('Send back')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->visible(fn (Outage $record) => Auth::user()?->can('workCustomStep', $record)
                && ($record->customTicket?->step_index ?? 0) > 0 && ($record->customTicket?->step()['can_send_back'] ?? false))
            ->modalDescription(fn (Outage $record) => 'Back to “'.($record->customTicket?->step($record->customTicket->step_index - 1)['name'] ?? 'the step before').'”.')
            ->schema([Textarea::make('reason')->label('What is missing or wrong?')->required()->rows(3)->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->sendBackCustomStep($record, Auth::user(), $data['reason']), 'Sent back'));
    }

    /** A manager gives the ticket to someone of the step's team (a manual step, or to move it on). */
    public static function assign(): Action
    {
        return Action::make('customAssign')
            ->label('Assign')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('gray')
            ->visible(fn (Outage $record) => $record->isCustomFlow() && Auth::user()?->can('overrideAssignment', $record))
            ->schema(fn (Outage $record) => [
                Select::make('user_id')->label('Assign to')->required()->searchable()->options(CustomTicketResource::stepTeamOptions($record))
                    ->helperText('The members of the team of this step.'),
                Textarea::make('reason')->label('Reason')->required()->rows(2)->maxLength(1000),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(FifoAssignmentEngine::class)->manualAssign(
                $record, QueueType::CustomStep, User::query()->findOrFail($data['user_id']), Auth::user(), $data['reason'],
            ), 'Ticket assigned'));
    }

    public static function cancel(): Action
    {
        return Action::make('customCancel')
            ->label('Cancel ticket')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (Outage $record) => Auth::user()?->can('cancelCustom', $record))
            ->modalDescription('The ticket stops where it is. It has no SLA outcome; the reason is kept.')
            ->schema([Textarea::make('reason')->label('Reason')->required()->rows(3)->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->cancelCustomTicket($record, Auth::user(), $data['reason']), 'Ticket cancelled'));
    }
}
