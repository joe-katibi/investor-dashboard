<?php

namespace App\Filament\Concerns;

use App\Support\ActivityLog;
use Illuminate\Support\Facades\Auth;

/**
 * A ticket page that records who opened it (audit trail: "Ticket opened"). The entry is written
 * after the page has checked that the person may see the ticket — a refused attempt opens nothing
 * and records nothing here.
 */
trait LogsTicketView
{
    public function mount(int|string $record): void
    {
        parent::mount($record);

        ActivityLog::viewed($this->getRecord(), Auth::user());
    }
}
