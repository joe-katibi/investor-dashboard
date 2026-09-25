<?php

namespace App\Services\Notifications\Sms;

use Illuminate\Support\Facades\Log;

/** Development / fallback driver: writes SMS to the log instead of sending. */
class LogSmsGateway implements SmsGateway
{
    public function send(string $to, string $message): void
    {
        Log::info('SMS (log driver)', ['to' => $to, 'message' => $message]);
    }
}
