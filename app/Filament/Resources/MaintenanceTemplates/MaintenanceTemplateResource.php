<?php

namespace App\Filament\Resources\MaintenanceTemplates;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\MaintenanceTemplates\Pages\ManageMaintenanceTemplates;
use App\Filament\Support\AuditFields;
use App\Models\ChangeRequest;
use App\Models\MaintenanceTemplate;
use App\Services\Maintenance\MaintenanceNotifier;
use App\Services\Reports\TemplateRenderer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** Editable planned-maintenance e-mails: wording, recipients, CRF attachment and when each is sent. */
class MaintenanceTemplateResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'maintenance.configure';

    protected static ?string $model = MaintenanceTemplate::class;

    protected static ?string $navigationLabel = 'Maintenance E-mails';

    protected static ?string $modelLabel = 'maintenance e-mail template';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelopeOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 24;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(150),
            TextInput::make('code')->required()->maxLength(50)->alphaDash()->unique(ignoreRecord: true),
            Section::make('When to send')->schema([
                Select::make('trigger')->label('Send when')->options(MaintenanceTemplate::TRIGGERS)->required()->live(),
                TextInput::make('offset_minutes')->label('Minutes before the start')->numeric()->minValue(1)->maxValue(43200)
                    ->helperText('e.g. 1440 = 24 hours, 90 = 1½ hours.')
                    ->visible(fn (Get $get) => $get('trigger') === 'before_start')
                    ->required(fn (Get $get) => $get('trigger') === 'before_start'),
            ])->columns(2)->columnSpanFull(),
            Section::make('Recipients')->schema([
                Toggle::make('send_to_stakeholders')->label('Stakeholders (Maintenance Settings + extra e-mails on the CRF)')
                    ->hidden(fn (Get $get) => $get('trigger') === 'sign_request'),
                Toggle::make('send_to_signatories')->label('CRF signatories')
                    ->hidden(fn (Get $get) => $get('trigger') === 'sign_request'),
                TagsInput::make('to')->label('Also TO')->nestedRecursiveRules(['email'])->placeholder('name@company.com')
                    ->hidden(fn (Get $get) => $get('trigger') === 'sign_request'),
                TagsInput::make('cc')->label('Also CC')->nestedRecursiveRules(['email'])->placeholder('name@company.com')
                    ->hidden(fn (Get $get) => $get('trigger') === 'sign_request'),
                TextInput::make('sign_request_note')->hiddenLabel()->disabled()->dehydrated(false)
                    ->default('Sign-off requests always go to each signatory individually, with their own link.')
                    ->visible(fn (Get $get) => $get('trigger') === 'sign_request')->columnSpanFull(),
            ])->columns(2)->columnSpanFull(),
            Section::make('Content')->schema([
                TextInput::make('subject')->required()->maxLength(255)->columnSpanFull(),
                Textarea::make('body')->label('Body')->required()->rows(16)->columnSpanFull()
                    ->helperText(new HtmlString(
                        '<strong>Bold:</strong> <code>**text**</code> on one line. Buttons: <code>{{sign_button}}</code> (sign-off request only), <code>{{ticket_button}}</code>.<br>'.
                        'Placeholders: <code>'.e(implode(', ', MaintenanceTemplate::PLACEHOLDERS)).'</code>'
                    )),
                Toggle::make('attach_crf')->label('Attach the CRF (PDF) and the customer list'),
            ])->columnSpanFull(),
            AuditFields::description(),
            AuditFields::activeToggle(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold')->wrap(),
                TextColumn::make('trigger')->label('Sent when')->wrap()
                    ->formatStateUsing(fn (MaintenanceTemplate $record) => $record->trigger === 'before_start'
                        ? MaintenanceNotifier::duration((int) $record->offset_minutes).' before the start'
                        : (MaintenanceTemplate::TRIGGERS[$record->trigger] ?? $record->trigger)),
                IconColumn::make('send_to_stakeholders')->label('Stakeholders')->boolean()->visibleFrom('md'),
                IconColumn::make('send_to_signatories')->label('Signatories')->boolean()->visibleFrom('md'),
                IconColumn::make('attach_crf')->label('CRF')->boolean()->visibleFrom('md'),
                AuditFields::activeColumn(),
            ])
            ->recordActions([
                EditAction::make()->modalWidth('5xl'),
                Action::make('preview')
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (MaintenanceTemplate $record) => 'Preview: '.$record->name)
                    ->modalWidth('5xl')
                    ->modalSubmitAction(false)
                    ->modalContent(function (MaintenanceTemplate $record) {
                        $changeRequest = ChangeRequest::query()->with('outage')->latest('id')->first();

                        if (! $changeRequest) {
                            return new HtmlString('<p>Preview needs at least one planned maintenance ticket.</p>');
                        }

                        $notifier = app(MaintenanceNotifier::class);
                        $data = $notifier->data($changeRequest, [
                            'signatory_name' => 'Jane Doe', 'signatory_role' => 'CE Manager', 'reason' => 'Example reason',
                            'previous_window' => $notifier->window($changeRequest),
                            'sign_button' => '<a href="#" style="display:inline-block;padding:10px 18px;background:#d97706;color:#fff;border-radius:6px;font-weight:bold;text-decoration:none;">Review &amp; sign the CRF</a>',
                        ]);
                        $renderer = app(TemplateRenderer::class);

                        return view('filament.email-preview', [
                            'subject' => $renderer->renderText($record->subject, $data).' (example: '.$changeRequest->outage->ticket_number.')',
                            'html' => $renderer->render($record->body, $data),
                        ]);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMaintenanceTemplates::route('/')];
    }
}
