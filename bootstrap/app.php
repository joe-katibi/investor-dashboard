<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        // Load balancer health check endpoint.
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a load balancer / reverse proxy: trust its forwarded headers so client IPs,
        // HTTPS detection and signed URLs are correct. The proxy list comes from
        // config('oms.trusted_proxies') (TRUSTED_PROXIES) and is applied in AppServiceProvider.
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        // Global (not just the "web" group) so Filament panel routes are covered too.
        $middleware->append(SecurityHeaders::class);

        // Non-panel pages that need a login (CRF PDF) send guests to the panel login.
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Never leak internals on error pages in production (APP_DEBUG=false handles the rest).
        $exceptions->dontReportDuplicates();
    })->create();
