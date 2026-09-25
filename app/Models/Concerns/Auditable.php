<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use App\Support\AuditContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Writes an append-only audit row for every create / update / delete (spec §21).
 * Hidden attributes (passwords, MFA secrets) are never written to the log.
 */
trait Auditable
{
    /** Attributes that are noise in an audit diff. */
    protected static array $auditIgnored = ['updated_at', 'created_at', 'remember_token', 'updated_by'];

    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => $model->writeAudit('created', [], $model->auditableAttributes($model->getAttributes())));

        static::updated(function (Model $model): void {
            $changes = $model->auditableAttributes($model->getChanges());

            if ($changes === []) {
                return;
            }

            $original = array_intersect_key($model->getRawOriginal(), $changes);
            $model->writeAudit('updated', $original, $changes);
        });

        static::deleted(fn (Model $model) => $model->writeAudit('deleted', $model->auditableAttributes($model->getAttributes()), []));
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable')->latest('created_at');
    }

    protected function auditableAttributes(array $attributes): array
    {
        $excluded = array_merge(static::$auditIgnored, $this->getHidden());

        return array_diff_key($attributes, array_flip($excluded));
    }

    protected function writeAudit(string $event, array $old, array $new): void
    {
        $request = app()->runningInConsole() ? null : Request::instance();

        AuditLog::query()->create([
            'auditable_type' => $this->getMorphClass(),
            'auditable_id' => $this->getKey(),
            'event' => $event,
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'user_id' => Auth::id(),
            'trigger' => AuditContext::trigger(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'url' => $request ? mb_substr($request->fullUrl(), 0, 500) : null,
            'created_at' => now(),
        ]);
    }
}
