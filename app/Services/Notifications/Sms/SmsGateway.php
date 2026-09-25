<?php

namespace App\Services\Notifications\Sms;

interface SmsGateway
{
    /** Send a message; throw on failure so the job retries and the log records the reason. */
    public function send(string $to, string $message): void;
}
