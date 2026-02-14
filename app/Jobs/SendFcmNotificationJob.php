<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendFcmNotificationJob extends PushNotificationJob
{
    /**
     * The number of seconds to wait before retrying the job.
     * Uses exponential backoff: 10s, 30s, 60s
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * Execute the job.
     *
     * Sends push notification via Firebase Cloud Messaging (FCM) HTTP v1 API
     */
    public function handle(): void
    {
        $pushToken = $this->getPushToken();

        if (empty($pushToken)) {
            $this->logFailure('FCM', 'No push token available');

            return;
        }

        $fcmServerKey = config('services.fcm.server_key');
        $fcmProjectId = config('services.fcm.project_id');

        if (empty($fcmServerKey)) {
            $this->logFailure('FCM', 'FCM server key not configured');

            return;
        }

        try {
            $response = $this->sendFcmNotification($pushToken, $fcmServerKey, $fcmProjectId);

            if ($response->successful()) {
                $responseData = $response->json();
                $messageId = $responseData['name'] ?? null;
                $this->logSuccess('FCM', $messageId);
            } else {
                $errorBody = $response->json();
                $errorMessage = $this->extractFcmError($errorBody);

                // Don't retry for invalid token errors
                if ($this->isPermanentError($errorBody)) {
                    $this->logFailure('FCM', "Permanent error: {$errorMessage}");
                    $this->delete(); // Remove from queue

                    return;
                }

                throw new \Exception("FCM request failed: {$errorMessage}");
            }
        } catch (\Exception $e) {
            $this->logFailure('FCM', $e->getMessage());
            throw $e; // Re-throw to trigger retry
        }
    }

    /**
     * Send FCM notification via HTTP v1 API.
     *
     * @param  string  $token  The device FCM token
     * @param  string  $serverKey  The FCM server key
     * @param  string|null  $projectId  The FCM project ID
     * @return \Illuminate\Http\Client\Response
     */
    protected function sendFcmNotification(string $token, string $serverKey, ?string $projectId)
    {
        // Use legacy FCM API for simplicity (can be upgraded to HTTP v1)
        $url = 'https://fcm.googleapis.com/fcm/send';

        $notification = [
            'title' => $this->payload['title'] ?? 'SyncOrc',
            'body' => $this->payload['body'] ?? 'You have a new notification',
            'sound' => $this->payload['sound'] ?? 'default',
        ];

        $data = [
            'type' => $this->data['type'] ?? 'notification',
            'timestamp' => $this->data['timestamp'] ?? now()->toIso8601String(),
        ];

        // Add custom data fields based on event type
        if (isset($this->data['group_id'])) {
            $data['group_id'] = $this->data['group_id'];
        }
        if (isset($this->data['source_device_id'])) {
            $data['source_device_id'] = $this->data['source_device_id'];
        }
        if (isset($this->data['state_version'])) {
            $data['state_version'] = $this->data['state_version'];
        }
        if (isset($this->data['offer_id'])) {
            $data['offer_id'] = $this->data['offer_id'];
        }

        $payload = [
            'to' => $token,
            'priority' => $this->payload['priority'] ?? 'high',
            'notification' => $notification,
            'data' => $data,
        ];

        // Add Android-specific configuration
        $payload['android'] = [
            'priority' => 'high',
            'notification' => [
                'channel_id' => 'syncorc_notifications',
                'sound' => 'default',
            ],
        ];

        return Http::withHeaders([
            'Authorization' => "key={$serverKey}",
            'Content-Type' => 'application/json',
        ])->timeout(30)->post($url, $payload);
    }

    /**
     * Extract error message from FCM response.
     *
     * @param  array<string, mixed>|null  $errorBody
     */
    protected function extractFcmError(?array $errorBody): string
    {
        if (empty($errorBody)) {
            return 'Unknown error';
        }

        if (isset($errorBody['error']['message'])) {
            return $errorBody['error']['message'];
        }

        if (isset($errorBody['results'][0]['error'])) {
            return $errorBody['results'][0]['error'];
        }

        return json_encode($errorBody);
    }

    /**
     * Check if the error is permanent (should not retry).
     *
     * @param  array<string, mixed>|null  $errorBody
     */
    protected function isPermanentError(?array $errorBody): bool
    {
        if (empty($errorBody)) {
            return false;
        }

        $permanentErrors = [
            'InvalidRegistration',
            'NotRegistered',
            'MismatchSenderId',
            'InvalidApnsCredential',
        ];

        $errorMessage = $this->extractFcmError($errorBody);

        foreach ($permanentErrors as $permanentError) {
            if (str_contains($errorMessage, $permanentError)) {
                return true;
            }
        }

        return false;
    }
}
