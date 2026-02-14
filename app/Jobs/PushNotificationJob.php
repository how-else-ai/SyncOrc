<?php

namespace App\Jobs;

use App\Models\Device;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

abstract class PushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 10;

    /**
     * Create a new job instance.
     *
     * @param  Device  $device  The target device
     * @param  array<string, mixed>  $payload  The notification payload
     * @param  array<string, mixed>  $data  The original notification data
     */
    public function __construct(
        public Device $device,
        public array $payload,
        public array $data
    ) {
        $this->onQueue('push-notifications');
    }

    /**
     * Execute the job.
     */
    abstract public function handle(): void;

    /**
     * Get the push token for the device.
     */
    protected function getPushToken(): ?string
    {
        return $this->device->push_token;
    }

    /**
     * Log a successful notification send.
     */
    protected function logSuccess(string $provider, string $messageId = null): void
    {
        Log::info("Push notification sent via {$provider}", [
            'device_id' => $this->device->device_id,
            'platform' => $this->device->platform,
            'event_type' => $this->data['type'] ?? 'unknown',
            'message_id' => $messageId,
        ]);
    }

    /**
     * Log a failed notification attempt.
     */
    protected function logFailure(string $provider, string $reason): void
    {
        Log::error("Push notification failed via {$provider}", [
            'device_id' => $this->device->device_id,
            'platform' => $this->device->platform,
            'event_type' => $this->data['type'] ?? 'unknown',
            'reason' => $reason,
            'attempt' => $this->attempts(),
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Push notification job failed permanently', [
            'device_id' => $this->device->device_id ?? 'unknown',
            'platform' => $this->device->platform ?? 'unknown',
            'event_type' => $this->data['type'] ?? 'unknown',
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
