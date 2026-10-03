<?php

namespace App\Filament\Resources\EmailTemplates;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\EmailTemplates\Pages\ManageEmailTemplates;
use App\Filament\Support\AuditFields;
use App\Jobs\SendScheduledReportJob;
use App\Models\EmailTemplate;
use App\Services\Reports\ProductivityReport;
use App\Services\Reports\ScheduledReportService;
use App\Services\Reports\TemplateRenderer;
use App\Services\Tickets\TicketReportData;
use BackedEnum;
use Cron\CronExpression;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

class EmailTemplateResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'templates.manage';

    protected static ?string $model = EmailTemplate::class;

    protected static ?string $navigationLabel = 'Email Templates & Schedules';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 23;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Template name')->required()->maxLength(150),
            TextInput::make('code')->required()->maxLength(50)->alphaDash()->unique(ignoreRecord: true),
            Select::make('report_type')->options(EmailTemplate::REPORT_TYPES)->default('custom')->required(),
            TextInput::make('period_hours')->label('Reporting period (hours)')->numeric()->minValue(1)->maxValue(744)->default(24)->required(),
            Section::make('Schedule')->schema([
                Select::make('schedule_type')->options(EmailTemplate::SCHEDULE_TYPES)->default('interval')->live()->required(),
                TextInput::make('interval_minutes')->label('Every (minutes)')->numeric()->minValue(5)
                    ->visible(fn (Get $get) => $get('schedule_type') === 'interval')
                    ->required(fn (Get $get) => $get('schedule_type') === 'interval'),
                TagsInput::make('daily_times')->label('Times (HH:MM, 24h)')->placeholder('06:00')
                    ->nestedRecursiveRules(['regex:/^([01]?\d|2[0-3]):[0-5]\d$/'])
                    ->visible(fn (Get $get) => $get('schedule_type') === 'daily_times')
                    ->required(fn (Get $get) => $get('schedule_type') === 'daily_times'),
                TextInput::make('cron_expression')->placeholder('0 */2 * * *')
                    ->rule(fn () => function (string $attribute, $value, $fail): void {
                        if ($value && ! CronExpression::isValidExpression($value)) {
                            $fail('Invalid cron expression.');
                        }
                    })
                    ->visible(fn (Get $get) => $get('schedule_type') === 'cron')
                    ->required(fn (Get $get) => $get('schedule_type') === 'cron'),
            ])->columns(2)->columnSpanFull(),
            Section::make('Recipients')->schema([
                TagsInput::make('to')->label('TO')->required()->nestedRecursiveRules(['email']),
                TagsInput::make('cc')->label('CC')->nestedRecursiveRules(['email']),
            ])->columns(2)->columnSpanFull(),
            Section::make('Content')->schema([
                TextInput::make('subject')->required()->maxLength(255)->columnSpanFull(),
                Textarea::make('body')->label('Body template')->required()->rows(18)->columnSpanFull()
                    ->helperText(new HtmlString(
                        '<strong>Bold:</strong> wrap text in double asterisks on one line, e.g. <code>**LIVE NETWORK OUTAGES**</code> or '.
                        '<code>**{{ticket}} | {{location}}**</code> inside a loop. Use <em>Preview</em> to check the result.<br>'.
                        'Placeholders: <code>{{ key }}</code>. Loops: <code>{{#for each active network outage}} … {{/for}}</code> '.
                        '(also major incident, core outage, system outage, 4G outage, closed outage) with item keys '.
                        '<code>ticket, title, location, olt_fdt, start_time, customers, noc_time, maintenance_time, sms_status, credit_status, sla_status, status, owner, latest_action, final_reason</code>.<br>'.
                        'Values: <code>'.e(implode(', ', TemplateRenderer::placeholders())).'</code><br>'.
                        '<strong>4G NOC:</strong> loops <code>{{#for each active 4G outage}} … {{/for}}</code> (also 4G eNodeB outage, 4G transmission outage, '.
                        '4G power outage, 4G vendor ticket, closed 4G outage) with item keys <code>'.e(implode(', ', TemplateRenderer::MOBILITY_ITEM_KEYS)).'</code>. '.
                        '4G values: <code>'.e(implode(', ', TemplateRenderer::mobilityPlaceholders())).'</code><br>'.
                        '<strong>Customer tickets</strong> (report types “Tickets: …”): <code>'.e(implode(', ', TicketReportData::PLACEHOLDERS)).'</code>. '.
                        'Everything ending in <code>_table</code> is a ready-made table or chart; “Attach … Excel” attaches the list of pending tickets.<br>'.
                        '<strong>Staff productivity</strong> (report types “Staff Productivity: …”): <code>'.e(implode(', ', ProductivityReport::PLACEHOLDERS)).'</code>. '.
                        '“Last N hours” uses “Period (hours)”; “Attach … Excel” attaches the full report of the period.'
                    )),
                Toggle::make('attach_excel')->label('Attach summary report as Excel (.xlsx)'),
            ])->columnSpanFull(),
            AuditFields::description(),
            AuditFields::activeToggle(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('report_type')->badge()->formatStateUsing(fn (string $state) => EmailTemplate::REPORT_TYPES[$state] ?? $state),
                TextColumn::make('schedule')->state(fn (EmailTemplate $record) => match ($record->schedule_type) {
                    'interval' => 'Every '.$record->interval_minutes.' min',
                    'daily_times' => 'Daily at '.implode(', ', $record->daily_times ?? []),
                    'cron' => 'Cron '.$record->cron_expression,
                    default => '—',
                }),
                TextColumn::make('to')->label('TO')->badge()->color('gray')->limitList(2),
                TextColumn::make('last_run_at')->label('Last sent')->dateTime()->placeholder('Never'),
                TextColumn::make('next_run_at')->label('Next run')->dateTime()->placeholder('—'),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->recordActions([
                EditAction::make()->modalWidth('6xl'),
                ActionGroup::make([
                    Action::make('preview')
                        ->icon(Heroicon::OutlinedEye)
                        ->modalHeading(fn (EmailTemplate $record) => 'Preview: '.$record->name)
                        ->modalWidth('6xl')
                        ->modalSubmitAction(false)
                        ->modalContent(function (EmailTemplate $record) {
                            $rendered = app(ScheduledReportService::class)->render($record);

                            return view('filament.email-preview', ['subject' => $rendered['subject'], 'html' => $rendered['html']]);
                        }),
                    Action::make('sendNow')
                        ->label('Send now')
                        ->icon(Heroicon::OutlinedPaperAirplane)
                        ->requiresConfirmation()
                        ->modalDescription(fn (EmailTemplate $record) => 'Send to: '.implode(', ', array_merge($record->to ?? [], $record->cc ?? [])))
                        ->action(function (EmailTemplate $record): void {
                            SendScheduledReportJob::dispatch($record->id, now()->toIso8601String());
                            Notification::make()->title('Report queued for delivery')->success()->send();
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageEmailTemplates::route('/')];
    }
}
