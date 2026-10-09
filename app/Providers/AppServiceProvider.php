<?php

namespace App\Providers;

use App\Events\AssignmentTimeout;
use App\Events\OmsBroadcastEvent;
use App\Events\OutageAccepted;
use App\Events\OutageAssigned;
use App\Events\OutageRejected;
use App\Events\OutageUpdated;
use App\Models\NotificationRule;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\Sms\AfricasTalkingSmsGateway;
use App\Services\Notifications\Sms\HttpSmsGateway;
use App\Services\Notifications\Sms\LogSmsGateway;
use App\Services\Notifications\Sms\SmsGateway;
use App\Services\Notifications\Sms\TwilioSmsGateway;
use App\Services\Outages\OutageWorkflow;
use App\Services\Reports\DashboardMetrics;
use App\Support\ActivityLog;
use App\Support\Settings;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One settings instance per request/job; values are cached in the shared cache store.
        $this->app->scoped(Settings::class);

        $this->app->bind(SmsGateway::class, fn () => match (config('oms.sms.driver')) {
            'africastalking' => new AfricasTalkingSmsGateway(
                (string) config('oms.sms.africastalking.username'),
                config('oms.sms.africastalking.api_key'),
                config('oms.sms.sender'),
                (bool) config('oms.sms.africastalking.sandbox'),
            ),
            'twilio' => new TwilioSmsGateway(config('oms.sms.twilio.sid'), config('oms.sms.twilio.token'), config('oms.sms.twilio.from')),
            'http' => new HttpSmsGateway((string) config('oms.sms.http.url'), config('oms.sms.http.token'), config('oms.sms.sender')),
            default => new LogSmsGateway,
        });
    }

    public function boot(): void
    {
        $production = $this->app->isProduction();

        // Catch N+1 queries, silently discarded attributes and missing attributes during development.
        Model::shouldBeStrict(! $production);
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
            logger()->warning('N+1 lazy load detected', ['model' => $model::class, 'relation' => $relation]);
        });

        // Protect production data from accidental `migrate:fresh`, `db:wipe`, etc.
        DB::prohibitDestructiveCommands($production);

        $proxies = trim((string) config('oms.trusted_proxies'));
        if ($proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        if ($production || config('app.force_https')) {
            URL::forceScheme('https');
        }

        Password::defaults(fn () => $production
            ? Password::min(12)->mixedCase()->numbers()->symbols()
            : Password::min(8));

        // Super Admin holds every *permission* (names contain a dot, e.g. "outage.confirm").
        // Policy abilities ("confirm", "resolve", ...) still run so workflow-state rules apply
        // to everyone — a Super Admin cannot NOC-confirm an outage that is already dispatched.
        Gate::before(fn (User $user, string $ability) => str_contains($ability, '.') && $user->hasRole('Super Admin') ? true : null);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('exports', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));

        // Mobile app: sign-in attempts per account + address, then reads and writes per person.
        RateLimiter::for('mobile-login', fn (Request $request) => [
            Limit::perMinute(5)->by('login|'.mb_strtolower((string) $request->input('login')).'|'.$request->ip()),
            Limit::perMinute(30)->by('login-ip|'.$request->ip()),
        ]);
        RateLimiter::for('mobile', fn (Request $request) => Limit::perMinute(180)->by('mobile|'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('mobile-write', fn (Request $request) => Limit::perMinute(40)->by('mobile-write|'.($request->user()?->id ?: $request->ip())));

        // The audit trail of signing in on the website (the mobile app writes its own entries: mobile_login…).
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User && $event->guard !== 'sanctum') {
                $event->user->audit('auth_login', ['person' => $event->user->name, 'remembered' => $event->remember ? 'yes' : 'no'], [], $event->user->id);
            }
        });
        Event::listen(Logout::class, function (Logout $event): void {
            if ($event->user instanceof User && $event->guard !== 'sanctum') {
                $event->user->audit('auth_logout', ['person' => $event->user->name], [], $event->user->id);
            }
        });
        // A failed attempt: on the account when there is one, otherwise with the address that was tried. Never the password.
        Event::listen(Failed::class, function (Failed $event): void {
            $login = Str::limit((string) ($event->credentials['email'] ?? $event->credentials['username'] ?? ''), 150, '');

            $event->user instanceof User
                ? $event->user->audit('auth_login_failed', ['person' => $event->user->name, 'reason' => $event->user->is_active ? 'Wrong password' : 'Account is inactive'], [], false)
                : ActivityLog::record(User::class, 'auth_login_failed', ['login' => $login, 'reason' => 'No such account'], [], false);
        });
        Event::listen(Lockout::class, function (Lockout $event): void {
            ActivityLog::record(User::class, 'auth_lockout', [
                'login' => Str::limit((string) ($event->request->input('email') ?? $event->request->input('data.email') ?? ''), 150, ''),
                'reason' => 'Too many sign-in attempts',
            ], [], false);
        });
        Event::listen(PasswordReset::class, function (PasswordReset $event): void {
            if ($event->user instanceof User) {
                $event->user->audit('auth_password_reset', ['person' => $event->user->name, 'how' => 'By the e-mailed link'], [], $event->user->id);
            }
        });

        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                // The app signs in with a token ("sanctum"); everything else is the website.
                $event->user->forceFill([
                    'last_login_at' => now(),
                    $event->guard === 'sanctum' ? 'last_mobile_login_at' : 'last_web_login_at' => now(),
                ])->saveQuietly();
            }
        });

        // Any real-time OMS event invalidates the shared dashboard cache.
        Event::listen('App\Events\*', function (string $eventName): void {
            if (is_subclass_of($eventName, OmsBroadcastEvent::class)) {
                DashboardMetrics::bump();
            }
        });

        // 4G tickets follow their engineer FIFO item: when the FIFO engine assigns one the ticket
        // becomes "Assigned", when it takes it back (reject, timeout, engineer unavailable) "Open".
        Event::listen([OutageAssigned::class, OutageRejected::class, AssignmentTimeout::class, OutageUpdated::class], function (OmsBroadcastEvent $event): void {
            if (! $event->outageId) {
                return;
            }

            try {
                app(OutageWorkflow::class)->syncMobilityAssignment($event->outageId);
            } catch (Throwable $exception) {
                // The assignment itself is already committed; the next event (or the sweep) syncs again.
                report($exception);
            }
        });

        // Customer tickets and site visits follow the FIFO item of their desk in the same way: who
        // holds it, when it was assigned / accepted, the customer SMS when a technician gets a visit.
        Event::listen([OutageAssigned::class, OutageAccepted::class, OutageRejected::class, AssignmentTimeout::class, OutageUpdated::class], function (OmsBroadcastEvent $event): void {
            if (! $event->outageId) {
                return;
            }

            try {
                app(OutageWorkflow::class)->syncTicketAssignment($event->outageId);
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        NotificationRule::saved(fn () => Cache::forget(NotificationDispatcher::RULES_CACHE_KEY));
        NotificationRule::deleted(fn () => Cache::forget(NotificationDispatcher::RULES_CACHE_KEY));
    }
}
