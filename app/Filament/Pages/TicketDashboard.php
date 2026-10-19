<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Filament\Resources\SiteVisits\SiteVisitResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Http\Controllers\LiveTicketSummaryController;
use App\Models\Outage;
use App\Services\Reports\InsightReports;
use App\Services\Tickets\BusinessClock;
use App\Services\Tickets\TicketMetrics;
use App\Services\Tickets\TicketTables;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Customer Care dashboard: pending tickets against the SLA per department, OLT and region, issues
 * per OLT, tickets in follow-up, today's movement and the 14-day trend. The same figures as the
 * scheduled ticket e-mails.
 */
class TicketDashboard extends Page
{
    use RefreshesOnOmsEvents;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Customer Care';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'customer-care/dashboard';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Customer Care dashboard';

    protected string $view = 'filament.pages.ticket-dashboard';

    /** all | ticket | visit */
    #[Url]
    public string $kind = 'all';

    public const KINDS = ['all' => 'All', 'ticket' => 'Customer tickets', 'visit' => 'Site visits'];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('ticket.view') && (bool) Auth::user()?->can('ticket.dashboard');
    }

    public function getSubheading(): ?string
    {
        return 'Live. SLA clock: '.app(BusinessClock::class)->describe().'.';
    }

    protected function getHeaderActions(): array
    {
        return [
            ...collect(self::KINDS)->map(fn (string $label, string $kind) => Action::make('kind_'.$kind)
                ->label($label)
                ->color(fn () => $this->selected() === $kind ? 'primary' : 'gray')
                ->outlined(fn () => $this->selected() !== $kind)
                ->action(fn () => $this->kind = $kind))->values()->all(),
            Action::make('raise')->label('Raise ticket')->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => Auth::user()?->can('createTicket', Outage::class))
                ->url(fn () => TicketResource::getUrl('create')),
            Action::make('book')->label('Book site visit')->icon(Heroicon::OutlinedMapPin)->color('gray')
                ->visible(fn () => Auth::user()?->can('createVisit', Outage::class))
                ->url(fn () => SiteVisitResource::getUrl('create')),
            $this->liveLink(),
        ];
    }

    /**
     * The live "Tickets – Pending / SLA" page for management: a link with a secret, no sign-in, figures only.
     * Switch it on or off, and make a new link (the old one stops working at once).
     */
    private function liveLink(): Action
    {
        $settings = app(Settings::class);
        $url = fn (): ?string => $settings->bool('live.tickets_enabled') && filled($settings->get('live.tickets_token'))
            ? route('live.tickets', ['token' => $settings->get('live.tickets_token')]) : null;

        return Action::make('liveLink')->label('Live link for management')->icon(Heroicon::OutlinedLink)->color('gray')
            ->visible(fn () => (bool) Auth::user()?->can('templates.manage'))
            ->modalHeading('Live link: Tickets – Pending / SLA')
            ->modalDescription('A page that refreshes itself every minute with the pending / SLA summary — figures by department, OLT and region, no customer names or accounts. Anyone with the link can open it without signing in: send it to management only.')
            ->fillForm(fn () => ['enabled' => $settings->bool('live.tickets_enabled'), 'renew' => false, 'link' => $url()])
            ->schema([
                TextInput::make('link')->label('Link')->readOnly()->placeholder('Switched off')->copyable(),
                Toggle::make('enabled')->label('Link switched on'),
                Toggle::make('renew')->label('Make a new link (the current one stops working)'),
            ])
            ->modalSubmitActionLabel('Save')
            ->action(function (array $data) use ($settings, $url): void {
                $token = ! empty($data['renew']) || blank($settings->get('live.tickets_token')) ? LiveTicketSummaryController::newToken() : $settings->get('live.tickets_token');
                $settings->set(['live.tickets_enabled' => (bool) ($data['enabled'] ?? false), 'live.tickets_token' => $token], Auth::id());
                $settings->flush();

                ($link = $url())
                    ? Notification::make()->title('Live link saved')->body($link)->success()->persistent()->send()
                    : Notification::make()->title('Live link switched off')->warning()->send();
            });
    }

    protected function getViewData(): array
    {
        $metrics = app(TicketMetrics::class);
        $filters = $this->selected() === 'all' ? [] : ['kind' => $this->selected()];
        $period = $metrics->period('day', now(), $filters);
        $trend = collect($period['trend']);

        return [
            's' => $metrics->snapshot(now(), max(1, (int) now()->diffInHours(now()->startOfDay())), $filters),
            'period' => $period,
            'pct' => fn (?float $value) => $metrics->pct($value),
            // Final reasons of what was closed, day by day for a week against the week before.
            'reasons' => app(InsightReports::class)->finalReasons(now(), 7, $filters),
            'inflowChart' => TicketTables::bars($trend->pluck('label')->all(), [
                'Inflow' => ['color' => '#2563eb', 'values' => $trend->pluck('inflow')->all()],
                'Closed' => ['color' => '#16a34a', 'values' => $trend->pluck('closed')->all()],
            ]),
            'slaChart' => TicketTables::line($trend->pluck('label')->all(), [
                'Closed within SLA %' => ['color' => '#0d9488', 'values' => $trend->pluck('pct')->all()],
            ], '%', 100.0),
            'url' => fn (array $row) => $row['kind'] === 'visit'
                ? SiteVisitResource::getUrl('view', ['record' => $row['id']])
                : TicketResource::getUrl('view', ['record' => $row['id']]),
            'ticketTab' => fn (string $tab) => TicketResource::getUrl('index', ['tab' => $tab]),
            'visitTab' => fn (string $tab) => SiteVisitResource::getUrl('index', ['tab' => $tab]),
        ];
    }

    private function selected(): string
    {
        return array_key_exists($this->kind, self::KINDS) ? $this->kind : 'all';
    }
}
