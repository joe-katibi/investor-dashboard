<?php

namespace App\Services\Notifications\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Twilio Programmable Messaging via the REST API (no SDK dependency). */
class TwilioSmsGateway implements SmsGateway
{
    public function __construct(
        private readonly ?string $sid,
        private readonly ?string $token,
        private readonly ?string $from,
    ) {}

    public function name(): string
    {
        return 'Twilio';
    }

    public function send(string $to, string $message): void
    {
        if (blank($this->sid) || blank($this->token) || blank($this->from)) {
            throw new RuntimeException('TWILIO_SID, TWILIO_TOKEN and TWILIO_FROM must be configured.');
        }

        $response = Http::timeout(15)
            ->withBasicAuth((string) $this->sid, (string) $this->token)
            ->asForm()
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->sid}/Messages.json", [
                'To' => $to,
                'From' => $this->from,
                'Body' => $message,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Twilio responded '.$response->status().': '.mb_substr((string) ($response->json('message') ?? $response->body()), 0, 300));
        }
    }
}
