<?php

namespace App\Providers;

use App\Events\OmsBroadcastEvent;
use App\Models\NotificationRule;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\Sms\HttpSmsGateway;
use App\Services\Notifications\Sms\LogSmsGateway;
use App\Services\Notifications\Sms\SmsGateway;
use App\Services\Reports\DashboardMetrics;
use App\Support\Settings;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Auth\Events\Login;
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
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One settings instance per request/job; values are cached in the shared cache store.
        $this->app->scoped(Settings::class);

        $this->app->bind(SmsGateway::class, fn () => match (config('oms.sms.driver')) {
            'http' => new HttpSmsGateway((string) config('oms.sms.url'), config('oms.sms.token'), config('oms.sms.sender')),
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

        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });

        FilamentAsset::register([Css::make('oms', resource_path('css/oms.css'))]);

        // Any real-time OMS event invalidates the shared dashboard cache.
        Event::listen('App\Events\*', function (string $eventName): void {
            if (is_subclass_of($eventName, OmsBroadcastEvent::class)) {
                DashboardMetrics::bump();
            }
        });

        NotificationRule::saved(fn () => Cache::forget(NotificationDispatcher::RULES_CACHE_KEY));
        NotificationRule::deleted(fn () => Cache::forget(NotificationDispatcher::RULES_CACHE_KEY));
    }
}
