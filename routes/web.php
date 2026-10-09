<?php

use App\Filament\Support\OutageLink;
use App\Http\Controllers\CrfDocumentController;
use App\Http\Controllers\CrfSignController;
use App\Http\Controllers\DesktopAlertController;
use App\Http\Controllers\OutageAttachmentController;
use App\Http\Controllers\OutagePhotoController;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\Outage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// CRF sign-off from the e-mailed personal link (no login; signed URL + single-use token).
Route::middleware(['signed', 'throttle:30,1'])->prefix('crf/sign/{signatory}')->group(function (): void {
    Route::get('/', [CrfSignController::class, 'show'])->name('crf.sign');
    Route::post('/', [CrfSignController::class, 'store'])->name('crf.sign.store');
    Route::get('/pdf', [CrfSignController::class, 'pdf'])->name('crf.sign.pdf');
    Route::get('/done', [CrfSignController::class, 'done'])->name('crf.sign.done');
});

// A ticket link meant for the mobile app (https://…/mobile/tickets/123), opened in a browser instead:
// go to the ticket's page on the website. The same permission check as everywhere else applies there.
Route::middleware(['auth', 'throttle:60,1'])->get('/mobile/tickets/{outage}', function (Outage $outage) {
    Gate::authorize('view', $outage);

    return redirect()->to(OutageLink::url($outage));
})->whereNumber('outage')->name('mobile.ticket');

// Desktop notifications: the person's open pages ask whether there is anything new for them (their own unread notifications only).
Route::middleware(['auth', EnsureUserIsActive::class, 'throttle:120,1'])->get('/desktop-alerts', DesktopAlertController::class)->name('desktop-alerts');

// View / download a ticket's CRF (signed-in users).
Route::middleware(['auth', 'throttle:60,1'])->get('/crf/{outage}/pdf', CrfDocumentController::class)->name('crf.pdf');

// Field maintenance site photos (signed-in users who can view the ticket).
Route::middleware(['auth', 'throttle:240,1'])->get('/photos/{photo}', OutagePhotoController::class)->name('outage-photos.show');

// 4G ticket attachments / vendor evidence (signed-in users who can view the ticket).
Route::middleware(['auth', 'throttle:240,1'])->get('/attachments/{attachment}', OutageAttachmentController::class)->name('outage-attachments.show');
