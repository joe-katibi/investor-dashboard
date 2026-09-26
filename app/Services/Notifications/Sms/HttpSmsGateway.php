<?php

namespace App\Services\Notifications\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Generic HTTP SMS provider: POSTs JSON {to, message, sender} with a bearer token.
 * Adapt the payload in one place when the provider is chosen.
 */
class HttpSmsGateway implements SmsGateway
{
    public function __construct(
        private readonly string $url,
        private readonly ?string $token,
        private readonly ?string $sender,
    ) {}

    public function name(): string
    {
        return 'HTTP ('.(parse_url($this->url, PHP_URL_HOST) ?: 'not configured').')';
    }

    public function send(string $to, string $message): void
    {
        if ($this->url === '') {
            throw new RuntimeException('SMS_URL is not configured.');
        }

        $response = Http::timeout(10)
            ->withToken((string) $this->token)
            ->acceptJson()
            ->post($this->url, ['to' => $to, 'message' => $message, 'sender' => $this->sender]);

        if ($response->failed()) {
            throw new RuntimeException('SMS provider responded '.$response->status().': '.mb_substr($response->body(), 0, 300));
        }
    }
}
