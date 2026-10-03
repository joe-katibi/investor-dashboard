<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Jobs\SendNotificationJob;
use App\Models\NotificationLog;
use App\Models\NotificationRule;
use App\Models\Outage;
use App\Models\Team;
use App\Models\User;
use App\Services\Notifications\Sms\PhoneNumber;
use App\Support\AuditContext;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Event-driven notifications (spec §12). Every recipient/channel pair gets a delivery log
 * row first, then a queued job delivers it with retries — so failures are always visible
 * and retried instead of silently lost.
 */
class NotificationDispatcher
{
    public const RULES_CACHE_KEY = 'oms.notification-rules';

    public function __construct(private readonly NotificationMessageFactory $messages) {}

    /** Fire configured notification rules for an outage event. */
    public function dispatch(NotificationEvent $event, Outage $outage, array $context = []): void
    {
        // Only raw attribute arrays are cached (the cache refuses to unserialize objects).
        $rules = NotificationRule::hydrate(Cache::remember(
            self::RULES_CACHE_KEY,
            now()->addMinutes(5),
            fn () => NotificationRule::query()->active()->get()->map->getAttributes()->all(),
        ));

        // One recipient gets one message per channel per event, however many rules match them.
        $seen = [];

        foreach ($rules->where('event', $event->value) as $rule) {
            if (! $rule->matches($outage)) {
                continue;
            }

            $this->deliver($event, $outage, $rule->channels ?? [], $this->resolveTargets([
                'roles' => $rule->recipient_roles,
                'user_ids' => $rule->recipient_user_ids,
                'emails' => $rule->recipient_emails,
                'phones' => $rule->recipient_phones,
                'assignee' => $rule->notify_assignee,
                'team_leaders' => $rule->notify_team_leaders,
            ], $outage, $context), $context, $rule->id, $seen);
        }

        // People directly involved (ticket reporter, support requester): in-app + e-mail, once.
        if (! empty($context['notify_user_ids'])) {
            $users = User::query()->active()->whereIn('id', array_filter((array) $context['notify_user_ids']))->get();
            $this->deliver($event, $outage, [NotificationChannel::InApp->value, NotificationChannel::Mail->value], $users->map(fn (User $user) => ['user' => $user, 'email' => null, 'phone' => null]), $context, null, $seen);
        }

        $this->deliverToAlertList($event, $outage, $context, $seen);
    }

    /**
     * The alert mailing list (Configuration → Alert E-mails): specific addresses that receive
     * every selected event by e-mail, independent of roles and rules.
     */
    private function deliverToAlertList(NotificationEvent $event, Outage $outage, array $context, array &$seen): void
    {
        $settings = app(Settings::class);

        // 4G NOC tickets go to the 4G lists (4G NOC → 4G Settings), never to the FTTH ones.
        // …and customer tickets / site visits to theirs (Customer Care → Ticket Settings).
        [$emailsKey, $eventsKey, $phonesKey, $smsEventsKey] = match (true) {
            $outage->isTicket() => ['ticketing.alert_emails', 'ticketing.alert_events', 'ticketing.alert_phones', 'ticketing.alert_sms_events'],
            $outage->isMobility() => ['mobility.alert_emails', 'mobility.alert_events', 'mobility.alert_phones', 'mobility.alert_sms_events'],
            default => ['alerts.emails', 'alerts.events', 'alerts.phones', 'alerts.sms_events'],
        };

        if (in_array($event->value, (array) $settings->get($eventsKey), true)) {
            $emails = collect((array) $settings->get($emailsKey))
                ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
                ->map(fn (string $email) => ['user' => null, 'email' => strtolower($email), 'phone' => null])
                ->values();

            $this->deliver($event, $outage, [NotificationChannel::Mail->value], $emails, $context, null, $seen);
        }

        if (in_array($event->value, (array) $settings->get($smsEventsKey), true)) {
            $phones = collect((array) $settings->get($phonesKey))
                ->map(fn ($phone) => ['user' => null, 'email' => null, 'phone' => (string) $phone])
                ->values();

            $this->deliver($event, $outage, [NotificationChannel::Sms->value], $phones, $context, null, $seen);
        }
    }

    /** Channels used when a ticket is assigned to a person (in-app always; e-mail / SMS if enabled). */
    public function assignmentChannels(): array
    {
        $settings = app(Settings::class);

        return array_values(array_filter([
            NotificationChannel::InApp->value,
            $settings->bool('alerts.email_assignee') ? NotificationChannel::Mail->value : null,
            $settings->bool('alerts.sms_assignee') ? NotificationChannel::Sms->value : null,
        ]));
    }

    /**
     * Notify an explicit target set (SLA policy recipients, escalations).
     *
     * @param  array{roles?: ?array, user_ids?: ?array, emails?: ?array, phones?: ?array, assignee?: bool, team_leaders?: bool}  $targets
     */
    public function notifyTargets(NotificationEvent $event, Outage $outage, array $targets, array $channels, array $context = []): void
    {
        $this->deliver($event, $outage, $channels, $this->resolveTargets($targets, $outage, $context), $context);
    }

    /** @param iterable<User> $users */
    public function notifyUsers(NotificationEvent $event, Outage $outage, iterable $users, array $channels = [NotificationChannel::InApp->value], array $context = []): void
    {
        $recipients = collect($users)->filter()->map(fn (User $user) => ['user' => $user, 'email' => null, 'phone' => null]);

        $this->deliver($event, $outage, $channels, $recipients, $context);
    }

    /** Team leaders and managers for the outage's team/sub-team (fallback to queue department managers). */
    public function teamLeaders(Outage $outage): Collection
    {
        $teams = Team::query()->whereIn('id', array_filter([$outage->team_id, $outage->sub_team_id]))->get();

        return User::query()
            ->active()
            ->whereIn('id', $teams->flatMap(fn (Team $team) => [$team->leader_id, $team->manager_id])->filter()->unique())
            ->get();
    }

    /** @return Collection<int, array{user: ?User, email: ?string, phone: ?string}> */
    private function resolveTargets(array $targets, Outage $outage, array $context): Collection
    {
        $users = collect();

        if (! empty($targets['roles'])) {
            $users = $users->merge(User::query()->active()->role($targets['roles'])->get());
        }

        if (! empty($targets['user_ids'])) {
            $users = $users->merge(User::query()->active()->whereIn('id', $targets['user_ids'])->get());
        }

        if (! empty($targets['assignee'])) {
            $assigneeId = $context['assignee_id'] ?? $outage->current_assignee_id;
            if ($assigneeId && $assignee = User::query()->active()->find($assigneeId)) {
                $users->push($assignee);
            }
        }

        if (! empty($targets['team_leaders'])) {
            $users = $users->merge($this->teamLeaders($outage));
        }

        $recipients = $users->unique('id')->map(fn (User $user) => ['user' => $user, 'email' => null, 'phone' => null]);

        foreach ($targets['emails'] ?? [] as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients->push(['user' => null, 'email' => $email, 'phone' => null]);
            }
        }

        foreach ($targets['phones'] ?? [] as $phone) {
            if (trim((string) $phone) !== '') {
                $recipients->push(['user' => null, 'email' => null, 'phone' => trim((string) $phone)]);
            }
        }

        return $recipients->values();
    }

    /** @param array<string, bool> $seen channel|address pairs already notified for this event */
    private function deliver(NotificationEvent $event, Outage $outage, array $channels, Collection $recipients, array $context, ?int $ruleId = null, array &$seen = []): void
    {
        if ($recipients->isEmpty() || $channels === []) {
            return;
        }

        $message = $this->messages->make($event, $outage, $context);

        foreach ($channels as $channelValue) {
            $channel = NotificationChannel::tryFrom($channelValue);
            if (! $channel) {
                continue;
            }

            foreach ($recipients as $recipient) {
                $address = match ($channel) {
                    NotificationChannel::InApp => $recipient['user']?->id,
                    NotificationChannel::Mail => strtolower((string) ($recipient['email'] ?? $recipient['user']?->email)) ?: null,
                    // E.164, so "0991 234 567" and "+265991234567" are the same recipient; invalid numbers are skipped.
                    NotificationChannel::Sms => PhoneNumber::normalise($recipient['phone'] ?? $recipient['user']?->phone),
                };

                if (! $address || isset($seen[$channel->value.'|'.$address])) {
                    continue;
                }
                $seen[$channel->value.'|'.$address] = true;

                $log = NotificationLog::query()->create([
                    'notification_rule_id' => $ruleId,
                    'outage_id' => $outage->id,
                    'event' => $event->value,
                    'channel' => $channel->value,
                    'recipient_user_id' => $recipient['user']?->id,
                    'recipient' => $channel === NotificationChannel::InApp ? $recipient['user']->name : (string) $address,
                    'subject' => $message['title'],
                    'body' => $channel === NotificationChannel::Sms ? $message['sms'] : $message['body'],
                    // Site photos (field maintenance) travel as e-mail attachments.
                    'meta' => $channel === NotificationChannel::Mail && ! empty($context['attach_photo_ids'])
                        ? ['photo_ids' => array_values((array) $context['attach_photo_ids'])]
                        : null,
                    'status' => 'pending',
                    'trigger' => AuditContext::trigger(),
                ]);

                if ($channel === NotificationChannel::InApp) {
                    $this->deliverInAppAfterCommit($log->id);
                } else {
                    SendNotificationJob::dispatch($log->id);
                }
            }
        }
    }

    /**
     * In-app alerts are a local database write, so they are delivered right after the
     * surrounding transaction commits instead of waiting for a queue worker; the bell fills
     * even when no worker is running. Only a failure falls back to the queue for retries.
     */
    private function deliverInAppAfterCommit(int $logId): void
    {
        DB::afterCommit(function () use ($logId): void {
            try {
                SendNotificationJob::dispatchSync($logId);
            } catch (Throwable $exception) {
                report($exception);
                SendNotificationJob::dispatch($logId);
            }
        });
    }
}
