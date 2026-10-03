<?php

namespace App\Filament\Resources\MobilityOutages;

use App\Enums\QueueType;
use App\Enums\StatusCode;
use App\Filament\Resources\Outages\OutageActions;
use App\Models\Department;
use App\Models\Outage;
use App\Models\OutageAttachment;
use App\Models\OutageStatus;
use App\Models\OutageType;
use App\Models\Team;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorUpdate;
use App\Services\Outages\OutageWorkflow;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * 4G NOC workflow actions, shared by the 4G ticket page, the 4G list, My Work and the FIFO queue.
 * Every action delegates to OutageWorkflow, where the policy decides: engineers and technicians
 * can resolve but never close, and only the NOC records vendor updates.
 */
final class MobilityActions
{
    /** @return list<Action> */
    public static function primary(): array
    {
        return [
            self::assignEngineer(), self::startWork(), self::markResolved(), self::addVendorUpdate(),
            self::verifyAndClose(), self::rejectResolution(), self::reopen(),
        ];
    }

    /** @return list<Action> */
    public static function secondary(): array
    {
        return [
            self::setStatus(), self::addAttachment(), self::sendVendorNotification(), self::changeVendor(),
            self::escalate(), self::cancel(),
        ];
    }

    // ------------------------------------------------------------------ internal tickets

    public static function assignEngineer(): Action
    {
        return Action::make('mobilityAssign')
            ->label(fn (Outage $record) => $record->technician_id || $record->current_queue ? 'Reassign' : 'Assign '.OutageType::handlerLabel($record->fault_domain))
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('warning')
            ->visible(fn (Outage $record) => Auth::user()?->can('mobilityAssign', $record))
            ->modalDescription(fn (Outage $record) => self::handlerRule($record->fault_domain).' Pick a person, or leave it on automatic: the ticket then goes to the 4G FIFO queue and the next available '
                .OutageType::handlerLabel($record->fault_domain).' gets it (and the one after, if it is rejected or not accepted in time).')
            ->schema([
                Select::make('engineer_id')->label(fn (Outage $record) => ucfirst(OutageType::handlerLabel($record->fault_domain)))->searchable()->live()
                    ->placeholder(fn (Outage $record) => 'Automatic — next available '.OutageType::handlerLabel($record->fault_domain).' (FIFO)')
                    ->options(fn (Outage $record) => self::engineerOptions($record->fault_domain)),
                Select::make('team_id')->label('Only offer it to this team (optional)')
                    ->options(fn () => self::teamOptions())
                    ->visible(fn (Get $get) => blank($get('engineer_id')) && self::teamOptions() !== []),
                Textarea::make('note')->label('Instructions (optional)')->maxLength(2000),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->assignMobilityEngineer(
                $record, Auth::user(), filled($data['engineer_id'] ?? null) ? (int) $data['engineer_id'] : null, $data['note'] ?? null,
                filled($data['team_id'] ?? null) ? (int) $data['team_id'] : null,
            ), filled($data['engineer_id'] ?? null) ? 'Ticket assigned' : 'Placed in the 4G queue (FIFO)'));
    }

    public static function startWork(): Action
    {
        return Action::make('mobilityStart')
            ->label('Start investigation')
            ->icon(Heroicon::OutlinedPlay)
            ->color('primary')
            ->visible(fn (Outage $record) => Auth::user()?->can('mobilityStart', $record))
            ->schema([Textarea::make('note')->label('Note (optional)')->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->startMobilityWork($record, Auth::user(), $data['note'] ?? null), 'Investigation started'));
    }

    public static function markResolved(): Action
    {
        return Action::make('mobilityResolve')
            ->label('Mark resolved')
            ->icon(Heroicon::OutlinedWrenchScrewdriver)
            ->color('success')
            ->visible(fn (Outage $record) => Auth::user()?->can('mobilityResolve', $record))
            ->modalHeading('Resolved – Pending NOC Confirmation')
            ->modalDescription('The ticket returns to the 4G NOC, who verify the service and close it.')
            // fillForm replaces field defaults, so the time is set here too.
            ->fillForm(fn (Outage $record) => [
                'restored_at' => now()->startOfMinute()->toDateTimeString(), 'root_cause' => $record->root_cause, 'resolution' => $record->resolution,
            ])
            ->schema([
                DateTimePicker::make('restored_at')->label('Restoration time')->seconds(false)->maxDate(fn () => now()->addMinutes(5)->startOfMinute()),
                Textarea::make('root_cause')->label('Root cause')->required()->rows(3)->maxLength(5000),
                Textarea::make('resolution')->label('Resolution / work done')->required()->rows(3)->maxLength(5000),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->markMobilityResolved($record, Auth::user(), $data), 'Resolved — sent to the NOC to confirm'));
    }

    // ------------------------------------------------------------------ NOC verification

    public static function verifyAndClose(): Action
    {
        return Action::make('mobilityClose')
            ->label('Verify & close')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('success')
            ->visible(fn (Outage $record) => Auth::user()?->can('mobilityClose', $record))
            ->modalHeading('NOC verification')
            ->modalDescription('Confirm the affected 4G service / site has recovered. Closing is final unless the ticket is reopened.')
            ->fillForm(fn (Outage $record) => ['root_cause' => $record->root_cause, 'resolution' => $record->resolution])
            ->schema([
                Textarea::make('root_cause')->label('Root cause')->required()->rows(3)->maxLength(5000),
                Textarea::make('resolution')->label('Resolution')->required()->rows(3)->maxLength(5000),
                Textarea::make('note')->label('Verification note (optional)')->maxLength(2000)->placeholder('e.g. All cells on air, alarms cleared, traffic normal.'),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->closeMobility($record, Auth::user(), $data), 'Verified — ticket closed'));
    }

    public static function rejectResolution(): Action
    {
        return Action::make('mobilityRejectResolution')
            ->label('Not restored — send back')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Outage $record) => Auth::user()?->can('mobilityClose', $record))
            ->schema([Textarea::make('reason')->label('What is still failing?')->required()->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->rejectMobilityResolution($record, Auth::user(), $data['reason']),
                $record->isVendorTicket() ? 'Returned — follow up with the vendor' : 'Returned to the engineer'));
    }

    // ------------------------------------------------------------------ vendor tickets

    public static function addVendorUpdate(): Action
    {
        return Action::make('mobilityVendorUpdate')
            ->label('Add vendor update')
            ->icon(Heroicon::OutlinedChatBubbleLeftRight)
            ->color('primary')
            ->visible(fn (Outage $record) => Auth::user()?->can('mobilityVendorUpdate', $record))
            ->modalHeading(fn (Outage $record) => 'Vendor update — '.($record->vendor?->name ?? 'vendor'))
            ->modalDescription('Record what the vendor told you by e-mail, phone or WhatsApp. “Power Restored” / “Resolved” sends the ticket to NOC verification.')
            ->modalWidth('3xl')
            // fillForm replaces field defaults, so the time and method are set here too.
            ->fillForm(fn (Outage $record) => [
                'occurred_at' => now()->startOfMinute()->toDateTimeString(), 'method' => 'phone', 'contact_person' => $record->vendor?->contact_person,
            ])
            ->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    DateTimePicker::make('occurred_at')->label('Date / time of the update')->seconds(false)->required()
                        ->maxDate(fn () => now()->addMinutes(5)->startOfMinute()),
                    Select::make('method')->label('Communication method')->options(VendorUpdate::METHODS)->required(),
                    Select::make('vendor_status')->label('Vendor status')->options(VendorUpdate::STATUSES)->required(),
                    TextInput::make('contact_person')->label('Vendor contact person')->maxLength(150),
                    DateTimePicker::make('eta_at')->label('Vendor ETA')->seconds(false),
                    DateTimePicker::make('next_update_at')->label('Next update expected')->seconds(false)
                        ->helperText('You are reminded if no update is recorded by then.'),
                ]),
                Textarea::make('comments')->label('Vendor comments')->rows(3)->maxLength(5000),
                self::attachmentUpload('attachments', 'Attachments / evidence')->storeFileNamesIn('attachment_names'),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->recordVendorUpdate($record, Auth::user(), $data), 'Vendor update recorded'));
    }

    public static function sendVendorNotification(): Action
    {
        return Action::make('mobilityNotifyVendor')
            ->label('Send vendor notification')
            ->icon(Heroicon::OutlinedEnvelope)
            ->visible(fn (Outage $record) => Auth::user()?->can('mobilityNotifyVendor', $record))
            ->modalDescription(fn (Outage $record) => $record->vendor?->email
                ? "E-mails {$record->vendor->name} at {$record->vendor->email}".($record->vendor->ccList() ? ' (CC '.implode(', ', $record->vendor->ccList()).')' : '').' using the vendor template.'
                : ($record->vendor?->name ?? 'The vendor').' has no e-mail address — add one under 4G NOC → Vendors first.')
            ->schema([Textarea::make('note')->label('Note for the e-mail (optional — {{reason}} in the template)')->maxLength(1000)])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->resendVendorNotification($record, Auth::user(), $data['note'] ?? null), 'Vendor notification queued'));
    }

    public static function changeVendor(): Action
    {
        return Action::make('mobilityChangeVendor')
            ->label('Change vendor')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->visible(fn (Outage $record) => Auth::user()?->can('mobilityChangeVendor', $record))
            ->modalDescription('The new vendor is e-mailed and the vendor steps start again.')
            ->schema([
                Select::make('vendor_id')->label('New vendor')->required()->searchable()
                    ->options(fn (Outage $record) => Vendor::query()->active()->whereKeyNot($record->vendor_id ?? 0)->orderBy('name')->pluck('name', 'id')),
                Textarea::make('reason')->label('Reason')->required()->maxLength(1000),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->changeMobilityVendor($record, Auth::user(), (int) $data['vendor_id'], $data['reason']), 'Vendor changed and notified'));
    }

    // ------------------------------------------------------------------ status / escalate / attach / reopen / cancel

    public static function setStatus(): Action
    {
        return Action::make('mobilitySetStatus')
            ->label('Set status')
            ->icon(Heroicon::OutlinedSignal)
            ->visible(fn (Outage $record) => Auth::user()?->can('mobilitySetStatus', $record) && self::statusOptions($record) !== [])
            ->schema([
                Select::make('status')->label('Status')->required()->options(fn (Outage $record) => self::statusOptions($record)),
                Textarea::make('note')->label('Note')->maxLength(2000),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->setMobilityStatus(
                $record, StatusCode::from($data['status']), Auth::user(), $data['note'] ?? null,
            ), 'Status updated'));
    }

    public static function escalate(): Action
    {
        return Action::make('mobilityEscalate')
            ->label('Escalate')
            ->icon(Heroicon::OutlinedArrowUpCircle)
            ->color('danger')
            ->visible(fn (Outage $record) => Auth::user()?->can('mobilityEscalate', $record))
            ->modalDescription('Notifies the escalation roles and addresses set in 4G Settings (and the “4G: Ticket Escalated” notification rules).')
            ->schema([Textarea::make('reason')->label('Reason for escalating')->required()->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->escalateMobility($record, Auth::user(), $data['reason']), 'Ticket escalated'));
    }

    public static function addAttachment(): Action
    {
        return Action::make('mobilityAttach')
            ->label('Attach files')
            ->icon(Heroicon::OutlinedPaperClip)
            ->visible(fn (Outage $record) => Auth::user()?->can('mobilityAttach', $record))
            ->schema([
                self::attachmentUpload('files', 'Files')->required()->storeFileNamesIn('file_names'),
                TextInput::make('note')->label('Note (optional)')->maxLength(255),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->addMobilityAttachments(
                $record, Auth::user(), (array) ($data['files'] ?? []), (array) ($data['file_names'] ?? []), $data['note'] ?? null,
            ), 'Files attached'));
    }

    public static function reopen(): Action
    {
        return Action::make('mobilityReopen')
            ->label('Reopen')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->visible(fn (Outage $record) => Auth::user()?->can('mobilityReopen', $record))
            ->modalDescription('Use when the service failed again, the ticket was closed by mistake, or the vendor / verification shows it is not fully resolved. The previous closure is kept in the reopening history.')
            ->schema([Textarea::make('reason')->label('Reason for reopening')->required()->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->reopenMobility($record, Auth::user(), $data['reason']), 'Ticket reopened'));
    }

    public static function cancel(): Action
    {
        return Action::make('mobilityCancel')
            ->label('Cancel ticket')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (Outage $record) => Auth::user()?->can('mobilityCancel', $record))
            ->modalDescription('For duplicates and false alarms. A cancelled ticket has no SLA outcome.')
            ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->cancelMobility($record, Auth::user(), $data['reason']), 'Ticket cancelled'));
    }

    // ------------------------------------------------------------------ helpers

    /** "Transmission outages are handled by a 4G engineer." — empty for a domain open to both. */
    public static function handlerRule(?string $domain): string
    {
        return OutageType::handlerPermission($domain)
            ? (OutageType::FAULT_DOMAINS[$domain] ?? ucfirst((string) $domain)).' outages are handled by a '.OutageType::handlerLabel($domain).'.'
            : '';
    }

    /**
     * Active users who can receive this kind of 4G ticket — 4G engineers for transmission outages,
     * 4G technicians for eNodeB outages — with their availability and current workload.
     */
    public static function engineerOptions(?string $domain = null): array
    {
        try {
            $query = User::permission(QueueType::MobilityEngineer->defaultPermission());

            if ($handles = OutageType::handlerPermission($domain)) {
                $query->permission($handles);
            }
        } catch (PermissionDoesNotExist) {
            return [];
        }

        return $query
            ->active()
            ->with('roles:id,name')
            ->withCount('activeAssignments')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user) => [$user->id => "{$user->name} — ".($user->roles->pluck('name')->implode(', ') ?: 'no role')
                .", {$user->availability_status->getLabel()}, {$user->active_assignments_count} active"])
            ->all();
    }

    /** Teams of the 4G NOC department (e.g. RAN, Transmission): FIFO can be limited to one of them. */
    public static function teamOptions(): array
    {
        return Team::query()->active()
            ->whereIn('department_id', Department::query()->where('code', QueueType::MobilityEngineer->defaultDepartmentCode())->pluck('id'))
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    /** Statuses this user may set by hand on this ticket (code => name). */
    public static function statusOptions(Outage $record): array
    {
        $user = Auth::user();
        $codes = collect(OutageWorkflow::MOBILITY_MANUAL_STATUSES[$record->workflow] ?? [])
            ->filter(fn (StatusCode $code) => $user?->can('mobility.verify')
                || (! $record->isVendorTicket() && in_array($code, [StatusCode::MobilityInProgress, StatusCode::MobilityMonitoring], true)))
            ->map(fn (StatusCode $code) => $code->value)
            ->reject(fn (string $code) => $code === OutageStatus::codeForId($record->status_id));

        return OutageStatus::query()->whereIn('code', $codes->all())->orderBy('sort_order')->pluck('name', 'code')->all();
    }

    public static function attachmentUpload(string $name, string $label): FileUpload
    {
        return FileUpload::make($name)->label($label)
            ->multiple()->maxFiles(8)->maxSize(15360)
            ->disk('local')->directory(OutageAttachment::DIRECTORY.'/'.now()->format('Y/m'))->visibility('private')
            ->acceptedFileTypes([
                'image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'text/plain', 'text/csv', 'message/rfc822',
                'application/vnd.ms-outlook', 'application/msword', 'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->helperText('Images, PDF, Office documents, text or saved e-mails — up to 15 MB each.');
    }
}
