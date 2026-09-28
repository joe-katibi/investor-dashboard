<?php

use App\Http\Controllers\CrfDocumentController;
use App\Http\Controllers\CrfSignController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// CRF sign-off from the e-mailed personal link (no login; signed URL + single-use token).
Route::middleware(['signed', 'throttle:30,1'])->prefix('crf/sign/{signatory}')->group(function (): void {
    Route::get('/', [CrfSignController::class, 'show'])->name('crf.sign');
    Route::post('/', [CrfSignController::class, 'store'])->name('crf.sign.store');
    Route::get('/pdf', [CrfSignController::class, 'pdf'])->name('crf.sign.pdf');
    Route::get('/done', [CrfSignController::class, 'done'])->name('crf.sign.done');
});

// View / download a ticket's CRF (signed-in users).
Route::middleware(['auth', 'throttle:60,1'])->get('/crf/{outage}/pdf', CrfDocumentController::class)->name('crf.pdf');
