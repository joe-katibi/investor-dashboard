<?php

namespace App\Jobs;

use App\Models\MobileDevice;
use App\Services\Mobile\FcmSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Pushes one notification to every phone a person is signed in on (Firebase Cloud Messaging), so
 * it arrives even when the mobile app is closed. Queued: a slow or unreachable Firebase never
 * holds up the ticket work that caused the notification.
 */
class SendPushNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public int $userId,
        public string $notificationId,
        public string $type,
        public string $title,
        public ?int $ticketId = null,
        public ?string $ticketNumber = null,
    ) {
        $this->afterCommit();
        $this->onQueue(config('oms.queues.notifications'));
    }

    public function backoff(): array
    {
        return [20, 120];
    }

    public function handle(FcmSender $fcm): void
    {
        if (! $fcm->configured()) {
            return;
        }

        $devices = MobileDevice::query()
            ->where('user_id', $this->userId)
            ->where('push_provider', FcmSender::PROVIDER)
            ->whereNotNull('push_token')
            ->get();

        $failure = null;

        foreach ($devices as $device) {
            try {
                $result = $fcm->send(
                    $device->push_token,
                    ['title' => $this->title, 'body' => $this->ticketNumber ? "Ticket {$this->ticketNumber}" : null],
                    [
                        'id' => $this->notificationId,
                        'type' => $this->type,
                        'title' => $this->title,
                        'ticket_id' => $this->ticketId,
                        'ticket_number' => $this->ticketNumber,
                    ],
                    $this->notificationId,
                );

                // The app was removed or its token replaced: stop pushing to it.
                if ($result === FcmSender::INVALID) {
                    $device->forceFill(['push_token' => null, 'push_provider' => null])->save();
                }
            } catch (Throwable $exception) {
                // One phone failing must not stop the others; the job is retried for the failure.
                $failure = $exception;
            }
        }

        if ($failure) {
            throw $failure;
        }
    }
}
