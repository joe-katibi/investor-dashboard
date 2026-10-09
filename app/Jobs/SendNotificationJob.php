<?php

namespace App\Jobs;

use App\Enums\NotificationChannel;
use App\Events\DesktopAlert;
use App\Events\MobileNotification;
use App\Filament\Support\OutageLink;
use App\Mail\MaintenanceMail;
use App\Mail\OutageNotificationMail;
use App\Mail\VendorNotificationMail;
use App\Models\MobileDevice;
use App\Models\NotificationLog;
use App\Services\Mobile\FcmSender;
use App\Services\Notifications\Sms\SmsGateway;
use App\Services\Outages\OutageWorkflow;
use Filament\Actions\Action;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Delivers one logged notification. Retries with backoff (spec §22 reliability) and records
 * attempts, final status and failure reason on the log row.
 */
class SendNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public int $logId)
    {
        $this->afterCommit();
        $this->onQueue(config('oms.queues.notifications'));
    }

    public function backoff(): array
    {
        return [30, 60, 180, 600];
    }

    public function handle(SmsGateway $sms): void
    {
        $log = NotificationLog::query()->with(['outage', 'recipientUser'])->find($this->logId);

        if (! $log || $log->status === 'sent') {
            return;
        }

        $log->forceFill(['attempts' => $log->attempts + 1])->save();

        try {
            match (NotificationChannel::from($log->channel)) {
                NotificationChannel::InApp => $this->sendInApp($log),
                NotificationChannel::Mail => $this->sendMail($log),
                NotificationChannel::Sms => $sms->send((string) $log->recipient, (string) $log->body),
            };

            $log->forceFill(['status' => 'sent', 'sent_at' => now(), 'failure_reason' => null])->save();
        } catch (Throwable $exception) {
            $log->forceFill([
                'status' => $this->attempts() >= $this->tries ? 'failed' : 'retrying',
                'failure_reason' => Str::limit($exception->getMessage(), 1000),
            ])->save();

            throw $exception;
        }

        // 4G vendor e-mail delivered: timeline entry and "Vendor Notification Sent". Outside the
        // try above so a problem here can never cause the vendor to be e-mailed twice.
        if ($this->isVendorMail($log)) {
            try {
                app(OutageWorkflow::class)->vendorMailDelivered($log);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        NotificationLog::query()->whereKey($this->logId)->update([
            'status' => 'failed',
            'failure_reason' => Str::limit((string) $exception?->getMessage(), 1000),
        ]);

        $log = NotificationLog::query()->find($this->logId);

        if ($log && $this->isVendorMail($log)) {
            try {
                app(OutageWorkflow::class)->vendorMailFailed($log, $exception?->getMessage());
            } catch (Throwable $inner) {
                report($inner);
            }
        }
    }

    private function sendMail(NotificationLog $log): void
    {
        // Vendor e-mails carry their own TO / CC lists; everything else goes to the one logged recipient.
        $mail = Mail::to(! empty($log->meta['to']) ? $log->meta['to'] : $log->recipient);

        if (! empty($log->meta['cc'])) {
            $mail->cc($log->meta['cc']);
        }

        $mail->send(match (true) {
            $this->isVendorMail($log) => new VendorNotificationMail($log),
            isset($log->meta['html']) => new MaintenanceMail($log),
            default => new OutageNotificationMail($log),
        });
    }

    private function isVendorMail(NotificationLog $log): bool
    {
        return ($log->meta['kind'] ?? null) === 'vendor';
    }

    private function sendInApp(NotificationLog $log): void
    {
        $user = $log->recipientUser;

        if (! $user || ! $user->is_active) {
            return;
        }

        $notification = Notification::make()
            ->title($log->subject)
            ->body(nl2br(e((string) $log->body)))
            ->icon('heroicon-o-bell-alert')
            ->iconColor(str_contains($log->event, 'breach') || str_contains($log->event, 'escalation') ? 'danger' : 'primary');

        if ($log->outage_id) {
            $notification->actions([
                Action::make('view')
                    ->label('Open ticket')
                    ->url(OutageLink::url($log->outage ?? $log->outage_id))
                    ->markAsRead(),
            ]);
        }

        // What the mobile app needs to open the ticket (it never follows the web link above).
        $notification->viewData(['oms' => [
            'type' => $log->event,
            'ticket_id' => $log->outage_id,
            'ticket_number' => $log->outage?->ticket_number,
        ]]);

        // Store for the bell now: Filament's sendToDatabase() queues a second job, so without
        // a worker the bell would stay empty. The live refresh + toast over Reverb stay queued.
        $stored = $notification->toDatabase();
        $stored->id = (string) Str::uuid();
        $user->notifyNow($stored);
        DatabaseNotificationsSent::dispatch($user);
        $notification->broadcast($user);

        // …and for the desktop: the person's open OMS pages show a browser notification, also from a background tab.
        DesktopAlert::dispatch($user->id, DesktopAlert::describe($stored->id, $log->subject, $log->event, $log->outage_id, $log->outage?->ticket_number, $log->body));

        // The same alert for the phones this person is signed in on with the mobile app, two ways:
        //   Reverb   — instant, while the app is open;
        //   Firebase — a push notification, so it also arrives when the app is in the background or closed.
        // The app shows a notification that reaches it both ways only once.
        $devices = MobileDevice::query()->where('user_id', $user->id)->get(['id', 'push_token']);

        if ($devices->isNotEmpty()) {
            $payload = [$user->id, $stored->id, (string) $log->event, (string) $log->subject, $log->outage_id, $log->outage?->ticket_number];
            MobileNotification::dispatch(...$payload);

            if ($devices->contains(fn (MobileDevice $device) => filled($device->push_token)) && app(FcmSender::class)->configured()) {
                SendPushNotificationJob::dispatch(...$payload);
            }
        }
    }
}
