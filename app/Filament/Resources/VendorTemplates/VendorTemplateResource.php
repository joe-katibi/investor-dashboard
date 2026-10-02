<?php

namespace App\Filament\Resources\VendorTemplates;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\VendorTemplates\Pages\ManageVendorTemplates;
use App\Filament\Support\AuditFields;
use App\Models\Outage;
use App\Models\OutageType;
use App\Models\VendorTemplate;
use App\Services\Mobility\VendorNotifier;
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
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** Editable e-mails to external vendors: wording, recipients and when each one is sent. */
class VendorTemplateResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'mobility.configure';

    protected static ?string $model = VendorTemplate::class;

    protected static ?string $slug = '4g/vendor-templates';

    protected static ?string $modelLabel = 'vendor e-mail template';

    protected static ?string $navigationLabel = 'Vendor E-mail Templates';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelopeOpen;

    protected static string|UnitEnum|null $navigationGroup = '4G NOC';

    protected static ?int $navigationSort = 9;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Template name')->required()->maxLength(150),
            TextInput::make('code')->required()->maxLength(50)->alphaDash()->unique(ignoreRecord: true),
            Section::make('When to send')->schema([
                Select::make('trigger')->label('Trigger')->options(VendorTemplate::TRIGGERS)->required(),
                Select::make('outage_type_id')->label('Only for outage type')->placeholder('Any vendor outage type')
                    ->options(fn () => OutageType::query()->where('workflow', OutageType::WORKFLOW_MOBILITY_VENDOR)->orderBy('sort_order')->pluck('name', 'id'))
                    ->helperText('A template for one outage type replaces the general one for the same trigger.'),
            ])->columns(2)->columnSpanFull(),
            Section::make('Recipients')->schema([
                Toggle::make('send_to_vendor')->label('TO: the vendor’s e-mail address')->default(true)
                    ->helperText('{{vendor.email}} — from the vendor record of the ticket.'),
                Toggle::make('cc_vendor_cc')->label('CC: the vendor’s CC addresses')->default(true)
                    ->helperText('{{vendor.cc_emails}} — from the vendor record.'),
                TagsInput::make('to')->label('Also TO')->nestedRecursiveRules(['email'])->placeholder('name@company.com'),
                TagsInput::make('cc')->label('Also CC')->nestedRecursiveRules(['email'])->placeholder('noc@company.com')
                    ->helperText('e.g. the 4G NOC mailbox, so replies reach the team.'),
            ])->columns(2)->columnSpanFull(),
            Section::make('Content')->schema([
                TextInput::make('subject')->required()->maxLength(255)->columnSpanFull()
                    ->placeholder('[4G POWER OUTAGE] {{ticket_number}} - {{site_name}}'),
                Textarea::make('body')->label('Body')->required()->rows(18)->columnSpanFull()
                    ->helperText(new HtmlString(
                        '<strong>Bold:</strong> <code>**text**</code> on one line. Use <em>Preview</em> to check the result.<br>'.
                        'Variables: <code>'.e(implode(', ', array_map(fn (string $key) => '{{'.$key.'}}', VendorTemplate::PLACEHOLDERS))).'</code>'
                    )),
            ])->columnSpanFull(),
            AuditFields::description(),
            AuditFields::activeToggle()->helperText('Inactive templates are not sent.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->description('Vendors have no access to this system. These e-mails are how a ticket reaches them; every one sent is recorded on the ticket timeline and in the notification log.')
            ->modifyQueryUsing(fn ($query) => $query->with('outageType:id,name'))
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold')->wrap(),
                TextColumn::make('trigger')->label('Sent when')->wrap()->formatStateUsing(fn (VendorTemplate $record) => $record->triggerLabel()),
                TextColumn::make('outageType.name')->label('Outage type')->placeholder('Any')->visibleFrom('md'),
                TextColumn::make('subject')->wrap()->visibleFrom('lg'),
                IconColumn::make('send_to_vendor')->label('To vendor')->boolean()->visibleFrom('md'),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->recordActions([
                EditAction::make()->modalWidth('5xl'),
                Action::make('preview')
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (VendorTemplate $record) => 'Preview: '.$record->name)
                    ->modalWidth('5xl')
                    ->modalSubmitAction(false)
                    ->modalContent(function (VendorTemplate $record) {
                        $notifier = app(VendorNotifier::class);
                        $renderer = app(TemplateRenderer::class);

                        // The latest real vendor ticket when there is one, otherwise sample values.
                        $ticket = Outage::query()->where('workflow', OutageType::WORKFLOW_MOBILITY_VENDOR)->whereNotNull('vendor_id')->latest('id')->first();
                        $data = $ticket ? $notifier->data($ticket, ['reason' => 'Example reason']) : $notifier->sampleData();

                        return view('filament.email-preview', [
                            'subject' => $renderer->renderText($record->subject, $data).($ticket ? '' : ' (sample values)'),
                            'html' => $renderer->render($record->body, $data),
                        ]);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageVendorTemplates::route('/')];
    }
}
