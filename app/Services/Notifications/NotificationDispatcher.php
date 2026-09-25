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
use App\Support\AuditContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

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
            ], $outage, $context), $context, $rule->id);
        }
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

    private function deliver(NotificationEvent $event, Outage $outage, array $channels, Collection $recipients, array $context, ?int $ruleId = null): void
    {
        if ($recipients->isEmpty() || $channels === []) {
            return;
        }

        $message = $this->messages->make($event, $outage, $context);
        $seen = [];

        foreach ($channels as $channelValue) {
            $channel = NotificationChannel::tryFrom($channelValue);
            if (! $channel) {
                continue;
            }

            foreach ($recipients as $recipient) {
                $address = match ($channel) {
                    NotificationChannel::InApp => $recipient['user']?->id,
                    NotificationChannel::Mail => $recipient['email'] ?? $recipient['user']?->email,
                    NotificationChannel::Sms => $recipient['phone'] ?? $recipient['user']?->phone,
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
                    'status' => 'pending',
                    'trigger' => AuditContext::trigger(),
                ]);

                SendNotificationJob::dispatch($log->id);
            }
        }
    }
}
