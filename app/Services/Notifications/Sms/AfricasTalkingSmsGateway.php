<?php

namespace App\Services\Notifications\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Africa's Talking bulk SMS (https://developers.africastalking.com/docs/sms/sending/bulk).
 * Uses the sandbox endpoint when AFRICASTALKING_SANDBOX=true.
 */
class AfricasTalkingSmsGateway implements SmsGateway
{
    public function __construct(
        private readonly string $username,
        private readonly ?string $apiKey,
        private readonly ?string $from,
        private readonly bool $sandbox,
    ) {}

    public function name(): string
    {
        return "Africa's Talking".($this->sandbox ? ' (sandbox)' : '');
    }

    public function send(string $to, string $message): void
    {
        if (blank($this->apiKey)) {
            throw new RuntimeException('AFRICASTALKING_API_KEY is not configured.');
        }

        $url = $this->sandbox
            ? 'https://api.sandbox.africastalking.com/version1/messaging'
            : 'https://api.africastalking.com/version1/messaging';

        $payload = array_filter([
            'username' => $this->username,
            'to' => $to,
            'message' => $message,
            // Only send a sender ID when one is registered (alphanumeric or short code).
            'from' => $this->from && $this->from !== 'OMS' ? $this->from : null,
        ]);

        $response = Http::timeout(15)
            ->withHeaders(['apiKey' => (string) $this->apiKey])
            ->acceptJson()
            ->asForm()
            ->post($url, $payload);

        if ($response->failed()) {
            throw new RuntimeException("Africa's Talking responded ".$response->status().': '.mb_substr($response->body(), 0, 300));
        }

        // A 2xx can still carry a per-recipient failure (e.g. invalid number, insufficient balance).
        $recipient = $response->json('SMSMessageData.Recipients.0');
        if (is_array($recipient) && ! in_array((int) ($recipient['statusCode'] ?? 0), [100, 101, 102], true)) {
            throw new RuntimeException("Africa's Talking rejected {$to}: ".($recipient['status'] ?? 'unknown status'));
        }
    }
}
