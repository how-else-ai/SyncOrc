<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendApnsNotificationJob extends PushNotificationJob
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
     * Sends push notification via Apple Push Notification service (APNs) HTTP/2 API
     */
    public function handle(): void
    {
        $pushToken = $this->getPushToken();

        if (empty($pushToken)) {
            $this->logFailure('APNs', 'No push token available');

            return;
        }

        $bundleId = config('services.apns.bundle_id');
        $keyId = config('services.apns.key_id');
        $teamId = config('services.apns.team_id');
        $privateKey = config('services.apns.private_key');
        $production = config('services.apns.production', false);

        if (empty($bundleId) || empty($keyId) || empty($teamId) || empty($privateKey)) {
            // Fallback to simulated mode for development
            $this->simulateNotification($pushToken);

            return;
        }

        try {
            $response = $this->sendApnsNotification(
                $pushToken,
                $bundleId,
                $keyId,
                $teamId,
                $privateKey,
                $production
            );

            if ($response->successful()) {
                $apnsId = $response->header('apns-id');
                $this->logSuccess('APNs', $apnsId);
            } else {
                $statusCode = $response->status();
                $errorBody = $response->json();
                $errorMessage = $this->extractApnsError($errorBody, $statusCode);

                // Don't retry for invalid token errors (4xx errors except 429)
                if ($statusCode >= 400 && $statusCode < 500 && $statusCode !== 429) {
                    $this->logFailure('APNs', "Permanent error: {$errorMessage}");
                    $this->delete(); // Remove from queue

                    return;
                }

                throw new \Exception("APNs request failed ({$statusCode}): {$errorMessage}");
            }
        } catch (\Exception $e) {
            $this->logFailure('APNs', $e->getMessage());
            throw $e; // Re-throw to trigger retry
        }
    }

    /**
     * Send APNs notification via HTTP/2 API.
     *
     * @param  string  $token  The device APNs token
     * @param  string  $bundleId  The app bundle ID
     * @param  string  $keyId  The APNs key ID
     * @param  string  $teamId  The Apple Team ID
     * @param  string  $privateKey  The APNs private key
     * @param  bool  $production  Whether to use production environment
     * @return \Illuminate\Http\Client\Response
     */
    protected function sendApnsNotification(
        string $token,
        string $bundleId,
        string $keyId,
        string $teamId,
        string $privateKey,
        bool $production
    ) {
        $baseUrl = $production
            ? 'https://api.push.apple.com'
            : 'https://api.sandbox.push.apple.com';

        $url = "{$baseUrl}/3/device/{$token}";

        // Generate JWT for authentication
        $jwt = $this->generateApnsJwt($keyId, $teamId, $privateKey);

        $payload = [
            'aps' => [
                'alert' => [
                    'title' => $this->payload['title'] ?? 'SyncOrc',
                    'body' => $this->payload['body'] ?? 'You have a new notification',
                ],
                'sound' => $this->payload['sound'] ?? 'default',
                'badge' => $this->payload['badge'] ?? 1,
                'content-available' => 1,
            ],
            'type' => $this->data['type'] ?? 'notification',
            'timestamp' => $this->data['timestamp'] ?? now()->toIso8601String(),
        ];

        // Add custom data fields based on event type
        if (isset($this->data['group_id'])) {
            $payload['group_id'] = $this->data['group_id'];
        }
        if (isset($this->data['source_device_id'])) {
            $payload['source_device_id'] = $this->data['source_device_id'];
        }
        if (isset($this->data['state_version'])) {
            $payload['state_version'] = $this->data['state_version'];
        }
        if (isset($this->data['offer_id'])) {
            $payload['offer_id'] = $this->data['offer_id'];
        }

        return Http::withHeaders([
            'Authorization' => "bearer {$jwt}",
            'apns-topic' => $bundleId,
            'apns-push-type' => 'alert',
            'apns-priority' => '10',
            'Content-Type' => 'application/json',
        ])->timeout(30)->post($url, $payload);
    }

    /**
     * Generate JWT token for APNs authentication.
     *
     * @param  string  $keyId  The APNs key ID
     * @param  string  $teamId  The Apple Team ID
     * @param  string  $privateKey  The APNs private key
     */
    protected function generateApnsJwt(string $keyId, string $teamId, string $privateKey): string
    {
        $header = json_encode([
            'alg' => 'ES256',
            'kid' => $keyId,
        ]);

        $time = time();
        $claims = json_encode([
            'iss' => $teamId,
            'iat' => $time,
            'exp' => $time + 3600, // 1 hour expiration
        ]);

        $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64Claims = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($claims));

        $signaturePayload = "{$base64Header}.{$base64Claims}";

        // Sign with private key
        $key = "-----BEGIN EC PRIVATE KEY-----\n".
               chunk_split($privateKey, 64, "\n").
               "-----END EC PRIVATE KEY-----";

        openssl_sign($signaturePayload, $signature, $key, 'SHA256');
        $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        return "{$signaturePayload}.{$base64Signature}";
    }

    /**
     * Extract error message from APNs response.
     *
     * @param  array<string, mixed>|null  $errorBody
     * @param  int  $statusCode  The HTTP status code
     */
    protected function extractApnsError(?array $errorBody, int $statusCode): string
    {
        if (empty($errorBody)) {
            return "HTTP {$statusCode}";
        }

        if (isset($errorBody['reason'])) {
            return $errorBody['reason'];
        }

        return json_encode($errorBody);
    }

    /**
     * Simulate notification in development mode.
     *
     * @param  string  $token  The device token
     */
    protected function simulateNotification(string $token): void
    {
        Log::info('[APNs Simulation] Notification would be sent', [
            'device_id' => $this->device->device_id,
            'platform' => $this->device->platform,
            'token' => substr($token, 0, 10).'...',
            'title' => $this->payload['title'] ?? 'SyncOrc',
            'body' => $this->payload['body'] ?? 'You have a new notification',
            'event_type' => $this->data['type'] ?? 'unknown',
        ]);
    }
}
