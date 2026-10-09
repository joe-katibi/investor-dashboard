<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/** Append-only: audit rows are never updated or deleted by the application. */
#[Fillable([
    'auditable_type', 'auditable_id', 'event', 'old_values', 'new_values', 'user_id', 'trigger',
    'ip_address', 'user_agent', 'url', 'created_at',
])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** Named actions on 4G NOC tickets: 4g_created, 4g_engineer_assigned, 4g_vendor_update, 4g_closed… */
    public const MOBILITY_PREFIX = '4g_';

    /** Everything else that belongs to the 4G NOC module (records and configuration). */
    public const MOBILITY_MODELS = [
        Vendor::class, VendorUpdate::class, VendorTemplate::class, Enodeb::class, TransmissionNode::class,
        ImpactRule::class, OutageAttachment::class, OutageReopening::class,
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The whole 4G NOC audit trail: every entry of a 4G ticket (named actions and field changes),
     * vendors, vendor updates, attachments, reopenings, sites, transmission nodes, severity rules,
     * vendor templates, and the 4G settings.
     */
    public function scopeMobility(Builder $query): void
    {
        $morph = fn (string $class) => (new $class)->getMorphClass();

        $query->where(fn (Builder $q) => $q
            ->where('event', 'like', self::MOBILITY_PREFIX.'%')
            ->orWhereIn('auditable_type', array_map($morph, self::MOBILITY_MODELS))
            ->orWhere(fn (Builder $ticket) => $ticket->where('auditable_type', $morph(Outage::class))
                ->whereIn('auditable_id', Outage::query()->mobility()->select('id')))
            ->orWhere(fn (Builder $setting) => $setting->where('auditable_type', $morph(Setting::class))
                ->whereIn('auditable_id', Setting::query()->where('key', 'like', 'mobility.%')->select('id'))));
    }

    public function isMobilityAction(): bool
    {
        return str_starts_with((string) $this->event, self::MOBILITY_PREFIX);
    }

    /** Named actions on customer tickets and site visits: tkt_created, tkt_escalated, tkt_closed… */
    public const TICKET_PREFIX = 'tkt_';

    /** Records and configuration of the customer ticketing module. */
    public const TICKET_MODELS = [
        Customer::class, Region::class, TicketCategory::class, TicketSubcategory::class, VisitType::class,
        FinalReason::class, DelayReason::class, DeskRoute::class,
    ];

    /** The customer ticketing audit trail: every ticket / site visit action, customers, and its configuration. */
    public function scopeTicketing(Builder $query): void
    {
        $morph = fn (string $class) => (new $class)->getMorphClass();

        $query->where(fn (Builder $q) => $q
            ->where('event', 'like', self::TICKET_PREFIX.'%')
            ->orWhereIn('auditable_type', array_map($morph, self::TICKET_MODELS))
            ->orWhere(fn (Builder $ticket) => $ticket->where('auditable_type', $morph(Outage::class))
                ->whereIn('auditable_id', Outage::query()->tickets()->select('id')))
            ->orWhere(fn (Builder $setting) => $setting->where('auditable_type', $morph(Setting::class))
                ->whereIn('auditable_id', Setting::query()->where('key', 'like', 'ticketing.%')->select('id'))));
    }

    public function isTicketAction(): bool
    {
        return str_starts_with((string) $this->event, self::TICKET_PREFIX);
    }

    /** Named actions on tickets of custom workflows: wf_raised, wf_step_completed, wf_sent_back, wf_completed, wf_cancelled. */
    public const WORKFLOW_PREFIX = 'wf_';

    /** What a person did in the mobile app: mobile_login, mobile_logout, mobile_action, mobile_raised… */
    public const MOBILE_PREFIX = 'mobile_';

    public function isMobileAppAction(): bool
    {
        return str_starts_with((string) $this->event, self::MOBILE_PREFIX);
    }

    /** A named workflow action (4G, customer ticket, mobile app) carrying ticket / status / details. */
    public function isNamedAction(): bool
    {
        return $this->isMobilityAction() || $this->isTicketAction() || $this->isMobileAppAction() || str_starts_with((string) $this->event, self::WORKFLOW_PREFIX);
    }

    /** "4g_vendor_update" → "4G: Vendor update"; "created" → "Created". */
    public function eventLabel(): string
    {
        return self::labelFor((string) $this->event);
    }

    public static function labelFor(string $event): string
    {
        return match (true) {
            str_starts_with($event, self::MOBILITY_PREFIX) => '4G: '.Str::of($event)->after(self::MOBILITY_PREFIX)->replace('_', ' ')->ucfirst()->replace('noc', 'NOC')->replace('fifo', 'FIFO')->replace('Fifo', 'FIFO'),
            str_starts_with($event, self::TICKET_PREFIX) => 'Ticket: '.Str::of($event)->after(self::TICKET_PREFIX)->replace('_', ' ')->ucfirst()->replace('noc', 'NOC')->replace('fifo', 'FIFO')->replace('Fifo', 'FIFO')->replace('sms', 'SMS')->replace('Sms', 'SMS'),
            str_starts_with($event, self::WORKFLOW_PREFIX) => 'Workflow: '.Str::of($event)->after(self::WORKFLOW_PREFIX)->replace('_', ' ')->ucfirst(),
            str_starts_with($event, self::MOBILE_PREFIX) => 'Mobile app: '.match ($event) {
                'mobile_login' => 'Signed in',
                'mobile_logout' => 'Signed out',
                'mobile_login_failed' => 'Sign-in failed',
                'mobile_action' => 'Action on a ticket',
                'mobile_raised' => 'Raised from the app',
                default => Str::of($event)->after(self::MOBILE_PREFIX)->replace('_', ' ')->ucfirst()->toString(),
            },
            isset(self::ACTIVITY_LABELS[$event]) => self::ACTIVITY_LABELS[$event],
            default => Str::of($event)->replace('_', ' ')->ucfirst()->toString(),
        };
    }

    /** Entries that are not a change of one record: signing in, roles, permissions, exports, imports. */
    public const ACTIVITY_LABELS = [
        'auth_login' => 'Website: Signed in',
        'auth_logout' => 'Website: Signed out',
        'auth_login_failed' => 'Website: Sign-in failed',
        'auth_lockout' => 'Website: Too many sign-in attempts',
        'auth_password_reset' => 'Password reset by e-mail link',
        'auth_password_changed' => 'Password changed',
        'roles_changed' => 'Roles of a person changed',
        'teams_changed' => 'Teams of a person changed',
        'permissions_changed' => 'Permissions of a role changed',
        'report_exported' => 'Report exported',
        'customers_imported' => 'Customers imported',
        'customers_import_previewed' => 'Customer import previewed',
        'viewed' => 'Ticket opened',
    ];

    public function subjectLabel(): string
    {
        // Action entries carry the ticket number, which says more than "Outage #12".
        if (($this->isNamedAction() || $this->event === 'viewed') && filled($this->new_values['ticket'] ?? null)) {
            return (string) $this->new_values['ticket'];
        }

        return class_basename($this->auditable_type).' #'.$this->auditable_id;
    }

    /** One line per value: "status: New → Assigned", or just "details: …" when there was no previous value. */
    public function changeLines(): array
    {
        $format = fn (mixed $value) => $value === null ? '∅' : (is_scalar($value) ? (string) $value : json_encode($value));
        $old = $this->old_values ?? [];

        return collect($this->new_values ?? [])
            ->map(fn ($new, $key) => array_key_exists($key, $old) ? $key.': '.$format($old[$key]).' → '.$format($new) : $key.': '.$format($new))
            ->values()->all();
    }
}
