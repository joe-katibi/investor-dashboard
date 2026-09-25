<?php

namespace App\Filament\Resources\Outages;

use App\Enums\AssignmentStatus;
use App\Enums\QueueType;
use App\Enums\StatusCode;
use App\Enums\TeamType;
use App\Models\Department;
use App\Models\Outage;
use App\Models\OutageAssignment;
use App\Models\OutageStatus;
use App\Models\OutageUpdate;
use App\Models\Team;
use App\Models\User;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Services\Outages\OutageWorkflow;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Workflow actions shared by the outage page, FIFO queue and My Work screens. Every action
 * delegates to OutageWorkflow / FifoAssignmentEngine, so permissions and FIFO ownership are
 * enforced identically everywhere.
 */
final class OutageActions
{
    /** @return list<Action> */
    public static function primary(): array
    {
        return [
            self::accept(), self::rejectAssignment(), self::nocConfirm(), self::nocReject(), self::dispatch(),
            self::reportRestored(), self::confirmRestoration(), self::rejectRestoration(), self::resolve(),
        ];
    }

    /** @return list<Action> */
    public static function secondary(): array
    {
        return [self::addUpdate(), self::setStatus(), self::customerCommunication(), self::reassign()];
    }

    // ------------------------------------------------------------------ FIFO assignment

    public static function accept(): Action
    {
        return Action::make('acceptAssignment')
            ->label('Accept')
            ->icon(Heroicon::OutlinedHandThumbUp)
            ->color('success')
            ->visible(fn (Outage $record) => self::myAssignment($record)?->status === AssignmentStatus::Assigned)
            ->action(fn (Outage $record) => self::run($record, fn () => app(FifoAssignmentEngine::class)->accept(self::myAssignment($record), Auth::user()), 'Ticket accepted'));
    }

    public static function rejectAssignment(): Action
    {
        return Action::make('rejectAssignment')
            ->label('Reject assignment')
            ->icon(Heroicon::OutlinedHandThumbDown)
            ->color('gray')
            ->visible(fn (Outage $record) => self::myAssignment($record) !== null)
            ->schema([Textarea::make('reason')->label('Why can you not take this ticket?')->required()->maxLength(1000)])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(FifoAssignmentEngine::class)->reject(self::myAssignment($record), Auth::user(), $data['reason']), 'Ticket returned to the queue'));
    }

    public static function reassign(): Action
    {
        return Action::make('reassign')
            ->label('Assign / override FIFO')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('danger')
            ->visible(fn (Outage $record) => Auth::user()?->can('overrideAssignment', $record))
            ->modalDescription('Manual assignment bypasses FIFO order. It is recorded with your name, the time and your reason.')
            ->fillForm(fn (Outage $record) => ['queue_type' => $record->current_queue?->value ?? QueueType::NocReview->value])
            ->schema([
                Select::make('queue_type')->label('Queue')->options(QueueType::class)->required()->live()
                    ->afterStateUpdated(fn (Set $set) => $set('user_id', null)),
                Select::make('user_id')->label('Assign to')->required()->searchable()
                    ->options(fn (Get $get) => self::queueUserOptions(QueueType::tryFrom((string) $get('queue_type')))),
                Textarea::make('reason')->label('Override reason')->required()->maxLength(1000),
            ])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(FifoAssignmentEngine::class)->manualAssign(
                $record, QueueType::from($data['queue_type']), User::query()->findOrFail($data['user_id']), Auth::user(), $data['reason'],
            ), 'Assignment overridden'));
    }

    // ------------------------------------------------------------------ NOC

    public static function nocConfirm(): Action
    {
        return Action::make('nocConfirm')
            ->label('NOC Confirm')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->visible(fn (Outage $record) => Auth::user()?->can('confirm', $record))
            ->schema([Textarea::make('note')->label('Confirmation note (optional)')->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->confirmByNoc($record, Auth::user(), $data['note'] ?? null), 'Outage confirmed — routed automatically'));
    }

    public static function nocReject(): Action
    {
        return Action::make('nocReject')
            ->label('NOC Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Outage $record) => Auth::user()?->can('confirm', $record))
            ->schema([Textarea::make('reason')->label('Rejection reason (mandatory)')->required()->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->rejectByNoc($record, Auth::user(), $data['reason']), 'Outage rejected'));
    }

    // ------------------------------------------------------------------ Infrastructure / dispatch

    public static function dispatch(): Action
    {
        return Action::make('dispatch')
            ->label('Dispatch')
            ->icon(Heroicon::OutlinedTruck)
            ->color('warning')
            ->visible(fn (Outage $record) => Auth::user()?->can('dispatch', $record))
            ->modalWidth('2xl')
            ->fillForm(fn (Outage $record) => [
                'team_type' => $record->team_type?->value ?? TeamType::InHouse->value,
                'team_id' => $record->team_id,
                'sub_team_id' => $record->sub_team_id,
            ])
            ->schema([
                Select::make('team_type')->label('Team type')->options(TeamType::class)->required()->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('team_id', null);
                        $set('sub_team_id', null);
                        $set('technician_id', null);
                    }),
                Select::make('team_id')->label('Assigned team')->required()->live()
                    ->options(fn (Get $get) => self::teamOptions($get('team_type')))
                    ->afterStateUpdated(function (Set $set): void {
                        $set('sub_team_id', null);
                        $set('technician_id', null);
                    }),
                Select::make('sub_team_id')->label('Assigned sub-team')->live()
                    ->options(fn (Get $get) => $get('team_id') ? Team::query()->active()->where('parent_id', $get('team_id'))->orderBy('name')->pluck('name', 'id') : [])
                    ->afterStateUpdated(fn (Set $set) => $set('technician_id', null)),
                Select::make('technician_id')->label('Technician')
                    ->placeholder('Automatic — next eligible technician (FIFO)')
                    ->options(fn (Get $get) => self::technicianOptions($get('team_id'), $get('sub_team_id')))
                    ->searchable()
                    ->helperText('Only active members of the selected team / sub-team are listed; full eligibility (availability, shift, leave, workload) is verified on submit.'),
                Textarea::make('note')->label('Dispatch note')->maxLength(2000),
            ])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->dispatch($record, $data, Auth::user()), 'Dispatched'));
    }

    // ------------------------------------------------------------------ field / restoration

    public static function addUpdate(): Action
    {
        return Action::make('addUpdate')
            ->label('Add update')
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->visible(fn (Outage $record) => Auth::user()?->can('addUpdate', $record))
            ->schema([
                Select::make('type')->options(collect(OutageUpdate::TYPES)->except('system'))->default('progress')->required(),
                Textarea::make('body')->label('Update / action taken')->required()->rows(4)->maxLength(5000),
            ])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->addUpdate($record, Auth::user(), $data['body'], $data['type']), 'Update posted'));
    }

    public static function reportRestored(): Action
    {
        return Action::make('reportRestored')
            ->label('Report restored')
            ->icon(Heroicon::OutlinedWrenchScrewdriver)
            ->color('success')
            ->visible(fn (Outage $record) => Auth::user()?->can('reportRestored', $record))
            ->schema([
                DateTimePicker::make('restored_at')->label('Restored at')->default(now())->seconds(false)->maxDate(now()->addMinutes(5)),
                Textarea::make('note')->label('Restoration details')->required()->maxLength(5000),
            ])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->reportRestored($record, Auth::user(), $data['note'], $data['restored_at'] ?? null), 'Restoration reported — awaiting NOC confirmation'));
    }

    public static function confirmRestoration(): Action
    {
        return Action::make('confirmRestoration')
            ->label('NOC Restore Confirmed')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('success')
            ->visible(fn (Outage $record) => Auth::user()?->can('confirmRestoration', $record))
            ->schema([Textarea::make('note')->label('Verification note (optional)')->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->confirmRestoration($record, Auth::user(), $data['note'] ?? null), 'Restoration confirmed'));
    }

    public static function rejectRestoration(): Action
    {
        return Action::make('rejectRestoration')
            ->label('Restoration not confirmed')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Outage $record) => Auth::user()?->can('confirmRestoration', $record))
            ->schema([Textarea::make('reason')->label('What is still failing?')->required()->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->rejectRestoration($record, Auth::user(), $data['reason']), 'Returned to the field team'));
    }

    public static function resolve(): Action
    {
        return Action::make('resolve')
            ->label('Resolve outage')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Outage $record) => Auth::user()?->can('resolve', $record))
            ->schema([
                Textarea::make('final_reason')->label('Final Reason / RFO')->rows(4)->maxLength(5000)
                    ->required(fn () => OutageStatus::forCode(StatusCode::Resolved)->prompt_final_reason)
                    ->default(fn (Outage $record) => $record->final_reason),
            ])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->resolve($record, Auth::user(), $data['final_reason'] ?? null), 'Outage resolved'));
    }

    public static function setStatus(): Action
    {
        return Action::make('setStatus')
            ->label('Set status')
            ->icon(Heroicon::OutlinedSignal)
            ->visible(fn (Outage $record) => Auth::user()?->can('changeStatus', $record))
            ->schema([
                Select::make('status_id')->label('Status')->required()->live()
                    ->options(fn () => OutageStatus::query()->active()->where('is_manual_selectable', true)->orderBy('sort_order')->pluck('name', 'id')),
                Textarea::make('reason')
                    ->label(fn (Get $get) => self::promptsFinalReason($get('status_id')) ? 'Final Reason (required by this status)' : 'Note')
                    ->required(fn (Get $get) => self::promptsFinalReason($get('status_id')))
                    ->maxLength(5000),
            ])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->changeStatus($record, OutageStatus::query()->findOrFail($data['status_id']), Auth::user(), $data['reason'] ?? null), 'Status updated'));
    }

    public static function customerCommunication(): Action
    {
        return Action::make('customerCommunication')
            ->label('SMS / Credit')
            ->icon(Heroicon::OutlinedDevicePhoneMobile)
            ->visible(fn (Outage $record) => Auth::user()?->can('updateFlags', $record))
            ->fillForm(fn (Outage $record) => ['sms_sent' => $record->sms_sent, 'credit_shared' => $record->credit_shared])
            ->schema([
                Toggle::make('sms_sent')->label('Customer SMS sent'),
                Toggle::make('credit_shared')->label('Credit shared'),
            ])
            ->action(function (Outage $record, array $data): void {
                self::run($record, function () use ($record, $data): void {
                    $workflow = app(OutageWorkflow::class);
                    foreach (['sms_sent', 'credit_shared'] as $flag) {
                        if ((bool) $data[$flag] !== (bool) $record->{$flag}) {
                            $workflow->setFlag($record, $flag, (bool) $data[$flag], Auth::user());
                        }
                    }
                }, 'Customer communication updated');
            });
    }

    // ------------------------------------------------------------------ helpers

    public static function myAssignment(Outage $outage): ?OutageAssignment
    {
        $userId = Auth::id();

        if (! $userId) {
            return null;
        }

        // Tables eager-load activeAssignments so row actions don't query per row.
        if ($outage->relationLoaded('activeAssignments')) {
            return $outage->activeAssignments->where('user_id', $userId)->sortByDesc('id')->first();
        }

        return OutageAssignment::query()
            ->where('outage_id', $outage->id)
            ->where('user_id', $userId)
            ->active()
            ->latest('id')
            ->first();
    }

    public static function teamOptions(?string $teamType): array
    {
        return Team::query()->active()->topLevel()
            ->whereIn('department_id', Department::query()->whereIn('code', ['INFRA', 'DISPATCH'])->pluck('id'))
            ->when($teamType, fn ($q) => $q->where('team_type', $teamType))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public static function technicianOptions(?int $teamId, ?int $subTeamId): array
    {
        if (! $teamId) {
            return [];
        }

        $teamIds = $subTeamId ? [$subTeamId] : Team::query()->find($teamId)?->selfAndSubTeamIds() ?? [];

        return User::permission('queue.work_technician')
            ->active()
            ->whereHas('teams', fn ($q) => $q->whereIn('teams.id', $teamIds))
            ->withCount('activeAssignments')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user) => [$user->id => "{$user->name} — {$user->availability_status->getLabel()}, {$user->active_assignments_count} active"])
            ->all();
    }

    public static function queueUserOptions(?QueueType $type): array
    {
        if (! $type) {
            return [];
        }

        return User::permission($type->defaultPermission())
            ->active()
            ->withCount('activeAssignments')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user) => [$user->id => "{$user->name} — {$user->availability_status->getLabel()}, {$user->active_assignments_count} active"])
            ->all();
    }

    private static function promptsFinalReason(mixed $statusId): bool
    {
        return $statusId && (bool) OutageStatus::query()->whereKey($statusId)->value('prompt_final_reason');
    }

    /** Run a workflow call, surface domain errors as notifications and refresh the record. */
    public static function run(Outage $record, callable $callback, string $success): void
    {
        try {
            $callback();
            Notification::make()->title($success)->success()->send();
        } catch (ValidationException $exception) {
            Notification::make()->title('Action not completed')->body(implode(' ', $exception->validator->errors()->all()))->danger()->send();
        } catch (AuthorizationException $exception) {
            Notification::make()->title('Not allowed')->body($exception->getMessage())->danger()->send();
        }

        $record->refresh();
    }
}
