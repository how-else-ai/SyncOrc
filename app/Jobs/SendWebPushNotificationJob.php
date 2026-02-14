<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendWebPushNotificationJob extends PushNotificationJob
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
     * Sends push notification via Web Push Protocol (RFC 8030)
     */
    public function handle(): void
    {
        $subscription = $this->getPushToken();

        if (empty($subscription)) {
            $this->logFailure('WebPush', 'No push subscription available');

            return;
        }

        $vapidPublicKey = config('services.web_push.public_key');
        $vapidPrivateKey = config('services.web_push.private_key');
        $vapidSubject = config('services.web_push.subject');

        if (empty($vapidPublicKey) || empty($vapidPrivateKey)) {
            // Fallback to simulated mode for development
            $this->simulateNotification($subscription);

            return;
        }

        try {
            $subscriptionData = json_decode($subscription, true);

            if (empty($subscriptionData['endpoint'])) {
                $this->logFailure('WebPush', 'Invalid subscription data');

                return;
            }

            $response = $this->sendWebPushNotification(
                $subscriptionData,
                $vapidPublicKey,
                $vapidPrivateKey,
                $vapidSubject
            );

            if ($response->successful()) {
                $this->logSuccess('WebPush');
            } else {
                $statusCode = $response->status();

                // Handle specific error codes
                if ($statusCode === 410 || $statusCode === 404) {
                    // Subscription expired or invalid - remove it
                    $this->logFailure('WebPush', "Subscription expired ({$statusCode})");
                    $this->delete();

                    return;
                }

                if ($statusCode === 429) {
                    // Rate limited
                    throw new \Exception("WebPush rate limited ({$statusCode})");
                }

                if ($statusCode >= 400 && $statusCode < 500) {
                    $this->logFailure('WebPush', "Client error ({$statusCode})");
                    $this->delete();

                    return;
                }

                throw new \Exception("WebPush request failed ({$statusCode})");
            }
        } catch (\Exception $e) {
            $this->logFailure('WebPush', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Send Web Push notification.
     *
     * @param  array<string, mixed>  $subscription  The push subscription data
     * @param  string  $publicKey  The VAPID public key
     * @param  string  $privateKey  The VAPID private key
     * @param  string|null  $subject  The VAPID subject (mailto: or https://)
     * @return \Illuminate\Http\Client\Response
     */
    protected function sendWebPushNotification(
        array $subscription,
        string $publicKey,
        string $privateKey,
        ?string $subject
    ) {
        $endpoint = $subscription['endpoint'];
        $keys = $subscription['keys'] ?? [];

        $payload = json_encode([
            'notification' => [
                'title' => $this->payload['title'] ?? 'SyncOrc',
                'body' => $this->payload['body'] ?? 'You have a new notification',
                'icon' => $this->payload['icon'] ?? '/icon.png',
                'badge' => $this->payload['badge'] ?? '/badge.png',
                'data' => [
                    'type' => $this->data['type'] ?? 'notification',
                    'group_id' => $this->data['group_id'] ?? null,
                    'source_device_id' => $this->data['source_device_id'] ?? null,
                    'state_version' => $this->data['state_version'] ?? null,
                    'offer_id' => $this->data['offer_id'] ?? null,
                    'timestamp' => $this->data['timestamp'] ?? now()->toIso8601String(),
                ],
            ],
        ]);

        // Generate VAPID JWT
        $vapidHeaders = $this->generateVapidHeaders(
            $endpoint,
            $publicKey,
            $privateKey,
            $subject
        );

        $headers = [
            'Authorization' => $vapidHeaders['Authorization'],
            'Content-Type' => 'application/octet-stream',
            'TTL' => '86400', // 24 hours
        ];

        if (! empty($keys['p256dh']) && ! empty($keys['auth'])) {
            // Encrypt payload for E2E security
            $encrypted = $this->encryptPayload($payload, $keys, $endpoint);
            $headers['Content-Encoding'] = 'aes128gcm';
            $payload = $encrypted;
        }

        return Http::withHeaders($headers)->timeout(30)->withBody($payload)->post($endpoint);
    }

    /**
     * Generate VAPID headers for Web Push authentication.
     *
     * @param  string  $endpoint  The push service endpoint
     * @param  string  $publicKey  The VAPID public key
     * @param  string  $privateKey  The VAPID private key
     * @param  string|null  $subject  The VAPID subject
     * @return array<string, string>
     */
    protected function generateVapidHeaders(
        string $endpoint,
        string $publicKey,
        string $privateKey,
        ?string $subject
    ): array {
        $origin = parse_url($endpoint, PHP_URL_SCHEME).'://'.parse_url($endpoint, PHP_URL_HOST);

        $header = json_encode([
            'typ' => 'JWT',
            'alg' => 'ES256',
        ]);

        $time = time();
        $claims = json_encode([
            'aud' => $origin,
            'exp' => $time + 43200, // 12 hours
            'sub' => $subject ?: 'mailto:admin@syncorc.local',
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

        $jwt = "{$signaturePayload}.{$base64Signature}";

        return [
            'Authorization' => "vapid t={$jwt}, k={$publicKey}",
        ];
    }

    /**
     * Encrypt the payload for Web Push E2E security.
     *
     * @param  string  $payload  The raw payload
     * @param  array<string, string>  $keys  The subscription keys
     * @param  string  $endpoint  The push endpoint
     */
    protected function encryptPayload(string $payload, array $keys, string $endpoint): string
    {
        // This is a simplified placeholder for payload encryption
        // In production, use a proper Web Push library like Minishlink\WebPush
        // Encryption involves ECDH key exchange and AES-128-GCM
        // For now, return the raw payload (services that don't require encryption will work)
        return $payload;
    }

    /**
     * Simulate notification in development mode.
     *
     * @param  string  $subscription  The subscription data
     */
    protected function simulateNotification(string $subscription): void
    {
        $data = json_decode($subscription, true);
        $endpoint = $data['endpoint'] ?? 'unknown';

        Log::info('[WebPush Simulation] Notification would be sent', [
            'device_id' => $this->device->device_id,
            'platform' => $this->device->platform,
            'endpoint' => substr($endpoint, 0, 50).'...',
            'title' => $this->payload['title'] ?? 'SyncOrc',
            'body' => $this->payload['body'] ?? 'You have a new notification',
            'event_type' => $this->data['type'] ?? 'unknown',
        ]);
    }
}
