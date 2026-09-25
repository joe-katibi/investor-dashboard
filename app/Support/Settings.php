<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * UI-configurable settings with code defaults. Values are cached in the shared cache store
 * so every node behind the load balancer sees a change immediately after it is saved.
 */
class Settings
{
    public const CACHE_KEY = 'oms.settings.v1';

    public const DEFAULTS = [
        // FIFO assignment engine (spec §27.8)
        'fifo.enabled' => true,
        'fifo.assignment_mode' => 'auto',          // auto | manual
        'fifo.priority_aware' => true,
        'fifo.max_active_per_user' => 5,
        'fifo.ack_timeout_minutes' => 15,
        'fifo.requeue_on_rejection' => true,
        'fifo.requeue_on_timeout' => true,
        'fifo.exclude_on_leave' => true,
        'fifo.respect_shift' => true,
        'fifo.require_availability' => true,
        'fifo.skill_based' => false,
        'fifo.manual_override' => true,
        'fifo.critical_bypass' => true,            // Critical tickets ignore the workload cap
        'fifo.requeue_on_unavailable' => 'unaccepted', // none | unaccepted | all
        'fifo.escalate_after_attempts' => 3,
        'fifo.enforce_assignee' => true,           // only the assignee (or an override holder) works the step

        // SLA
        'sla.pause_enabled' => true,
        'sla.default_resolution_minutes' => 240,
        'sla.default_pre_breach_minutes' => 30,

        // Notifications
        'notifications.credit_due_on_breach' => true,
    ];

    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : ($default ?? Arr::get(self::DEFAULTS, $key));
    }

    public function bool(string $key): bool
    {
        return filter_var($this->get($key), FILTER_VALIDATE_BOOL);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $stored = Cache::rememberForever(self::CACHE_KEY, function (): array {
            try {
                return Setting::query()->pluck('value', 'key')->all();
            } catch (\Throwable) {
                // Table not migrated yet (fresh install / tests before migrate).
                return [];
            }
        });

        return $this->loaded = array_merge(self::DEFAULTS, $stored);
    }

    /** @param array<string, mixed> $values */
    public function set(array $values, ?int $userId = null): void
    {
        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
        }

        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->loaded = null;
    }
}
