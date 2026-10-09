<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Middleware\EnsureMobileUser;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile app API (v1) — dispatchers, technicians and 4G engineers
|--------------------------------------------------------------------------
| JSON over HTTPS, a Sanctum personal access token per phone. The phone is an untrusted client:
| who the person is, what they may see and what they may do is decided here on every request.
| Versioned so a later /api/v2 does not break phones that have not updated yet.
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    // Sign-in: a handful of attempts per account + address, then a pause.
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:mobile-login')->name('auth.login');

    Route::middleware(['auth:sanctum', EnsureMobileUser::class, 'throttle:mobile'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::get('me', [MeController::class, 'show'])->name('me');
        Route::post('me/availability', [MeController::class, 'availability'])->name('me.availability');
        Route::get('dashboard', [MeController::class, 'dashboard'])->name('dashboard');
        Route::get('config', [MeController::class, 'config'])->name('config');

        Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
        Route::get('tickets/{ticket}', [TicketController::class, 'show'])->whereNumber('ticket')->name('tickets.show');
        Route::post('tickets/{ticket}/actions/{action}', [TicketController::class, 'act'])
            ->whereNumber('ticket')->where('action', '[a-z_]+')->middleware('throttle:mobile-write')->name('tickets.act');

        // Started from the app without a ticket: raise corrective / preventive maintenance on site.
        Route::get('actions', [TicketController::class, 'actions'])->name('actions.index');
        Route::post('actions/{action}', [TicketController::class, 'start'])
            ->where('action', '[a-z_]+')->middleware('throttle:mobile-write')->name('actions.start');

        Route::get('attachments/{attachment}', [TicketController::class, 'attachment'])->whereNumber('attachment')->name('attachments.show');
        Route::get('photos/{photo}', [TicketController::class, 'photo'])->whereNumber('photo')->name('photos.show');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification')->name('notifications.read');

        Route::post('devices', [DeviceController::class, 'store'])->middleware('throttle:mobile-write')->name('devices.store');
        Route::delete('devices/{device}', [DeviceController::class, 'destroy'])->whereNumber('device')->name('devices.destroy');

        // Reverb (app open): the phone proves it may listen to its own private channel (routes/channels.php decides).
        // Firebase (app in the background or closed) needs no route here: the push token comes in with POST devices.
        Route::post('broadcasting/auth', [MeController::class, 'broadcastAuth'])->name('broadcasting.auth');
    });
});
