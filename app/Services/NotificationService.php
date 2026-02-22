<?php

namespace App\Services;

use App\Models\Device;
use App\Models\SyncGroup;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    protected DeviceService $deviceService;

    public function __construct(DeviceService $deviceService)
    {
        $this->deviceService = $deviceService;
    }

    /**
     * Notify a device via WebSocket if online, via push if offline.
     *
     * @param  string  $deviceId  The target device UUID
     * @param  string  $channel  The channel name (e.g., "device.{$deviceId}")
     * @param  string  $eventType  The event type
     * @param  array<string, mixed>  $data  The event payload
     * @return array{online: bool, method: 'websocket'|'push'|'none', delivered: bool}
     */
    public function notifyDevice(string $deviceId, string $channel, string $eventType, array $data): array
    {
        $device = $this->deviceService->getDevice($deviceId);

        if (! $device) {
            return [
                'online' => false,
                'method' => 'none',
                'delivered' => false,
            ];
        }

        $isOnline = $this->deviceService->isOnline($deviceId);

        // Prepare notification data
        $payload = array_merge([
            'type' => $eventType,
            'timestamp' => now()->toIso8601String(),
        ], $data);

        if ($isOnline) {
            // Send via WebSocket
            $this->broadcast($channel, $eventType, $payload);

            return [
                'online' => true,
                'method' => 'websocket',
                'delivered' => true,
            ];
        } elseif ($device->canReceivePush()) {
            // Queue push notification for offline device
            $this->queuePushNotification($device, $payload);

            return [
                'online' => false,
                'method' => 'push',
                'delivered' => true,
            ];
        }

        // Device offline and cannot receive push
        return [
            'online' => false,
            'method' => 'none',
            'delivered' => false,
        ];
    }

    /**
     * Send sync required notification to target devices in a group.
     *
     * @param  string  $sourceDeviceId  The device that triggered the sync
     * @param  string  $groupId  The group UUID
     * @param  string  $stateVersion  The new state version
     * @param  array<string>  $targetDeviceIds  Target device IDs
     * @return array{notified_devices: array<string, array>, online_count: int, push_count: int}
     */
    public function notifySyncRequired(
        string $sourceDeviceId,
        string $groupId,
        string $stateVersion,
        array $targetDeviceIds
    ): array {
        $results = [
            'notified_devices' => [],
            'online_count' => 0,
            'push_count' => 0,
        ];

        foreach ($targetDeviceIds as $targetDeviceId) {
            $channel = "device.{$targetDeviceId}";
            $payload = [
                'group_id' => $groupId,
                'source_device_id' => $sourceDeviceId,
                'state_version' => $stateVersion,
            ];

            $result = $this->notifyDevice($targetDeviceId, $channel, 'sync_required', $payload);

            $results['notified_devices'][$targetDeviceId] = $result;

            if ($result['online']) {
                $results['online_count']++;
            } elseif ($result['method'] === 'push') {
                $results['push_count']++;
            }
        }

        return $results;
    }

    /**
     * Notify a group that a device has joined.
     *
     * @param  string  $groupId  The group UUID
     * @param  string  $deviceId  The device UUID that joined
     * @param  int|null  $position  Position in chain (if applicable)
     * @return array{notified_count: int}
     */
    public function notifyDeviceJoined(string $groupId, string $deviceId, ?int $position = null): array
    {
        $group = SyncGroup::where('group_id', $groupId)->first();

        if (! $group) {
            return ['notified_count' => 0];
        }

        $channel = "group.{$groupId}";
        $payload = [
            'group_id' => $groupId,
            'device_id' => $deviceId,
            'position' => $position,
        ];

        $this->broadcast($channel, 'device_joined', $payload);

        // Also send to device channel for the new member
        $deviceChannel = "device.{$deviceId}";
        $this->broadcast($deviceChannel, 'pairing_accepted', [
            'group_id' => $groupId,
            'device_id' => $deviceId,
            'group_type' => $group->group_type,
        ]);

        return ['notified_count' => 1];
    }

    /**
     * Notify a group that a device has left.
     *
     * @param  string  $groupId  The group UUID
     * @param  string  $deviceId  The device UUID that left
     * @return array{notified_count: int}
     */
    public function notifyDeviceLeft(string $groupId, string $deviceId): array
    {
        $channel = "group.{$groupId}";
        $payload = [
            'group_id' => $groupId,
            'device_id' => $deviceId,
        ];

        $this->broadcast($channel, 'device_left', $payload);

        return ['notified_count' => 1];
    }

    /**
     * Send a signaling offer to a device.
     *
     * @param  string  $offerId  The offer UUID
     * @param  string  $fromDeviceId  The source device UUID
     * @param  string  $toDeviceId  The target device UUID
     * @param  string  $offerData  The encrypted offer data
     * @return array{online: bool, method: 'websocket'|'push'|'none', delivered: bool}
     */
    public function notifySignalingOffer(
        string $offerId,
        string $fromDeviceId,
        string $toDeviceId,
        string $offerData
    ): array {
        $channel = "signaling.{$toDeviceId}";
        $payload = [
            'offer_id' => $offerId,
            'from_device_id' => $fromDeviceId,
            'offer_data' => $offerData,
        ];

        return $this->notifyDevice($toDeviceId, $channel, 'signaling_offer', $payload);
    }

    /**
     * Send a signaling answer to a device.
     *
     * @param  string  $offerId  The offer UUID
     * @param  string  $fromDeviceId  The answering device UUID
     * @param  string  $toDeviceId  The target device UUID
     * @param  string  $answerData  The encrypted answer data
     * @return array{online: bool, method: 'websocket'|'push'|'none', delivered: bool}
     */
    public function notifySignalingAnswer(
        string $offerId,
        string $fromDeviceId,
        string $toDeviceId,
        string $answerData
    ): array {
        $channel = "signaling.{$toDeviceId}";
        $payload = [
            'offer_id' => $offerId,
            'from_device_id' => $fromDeviceId,
            'answer_data' => $answerData,
        ];

        return $this->notifyDevice($toDeviceId, $channel, 'signaling_answer', $payload);
    }

    /**
     * Notify that a sync has been acknowledged.
     *
     * @param  string  $groupId  The group UUID
     * @param  string  $deviceId  The device UUID that acknowledged
     * @param  string  $stateVersion  The state version that was acknowledged
     * @return array{notified_count: int}
     */
    public function notifySyncAcknowledged(string $groupId, string $deviceId, string $stateVersion): array
    {
        $channel = "group.{$groupId}";
        $payload = [
            'group_id' => $groupId,
            'device_id' => $deviceId,
            'state_version' => $stateVersion,
        ];

        $this->broadcast($channel, 'sync_acknowledged', $payload);

        return ['notified_count' => 1];
    }

    /**
     * Broadcast a message to a channel.
     *
     * @param  string  $channel  The channel name
     * @param  string  $event  The event name
     * @param  array<string, mixed>  $data  The data to broadcast
     */
    protected function broadcast(string $channel, string $event, array $data): void
    {
        try {
            $broadcastChannel = new Channel($channel);
            broadcast($broadcastChannel)->with($data);
        } catch (\Exception $e) {
            Log::error('Failed to broadcast message', [
                'channel' => $channel,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Queue a push notification for a device.
     *
     * @param  Device  $device  The target device
     * @param  array<string, mixed>  $payload  The notification payload
     */
    protected function queuePushNotification(Device $device, array $payload): void
    {
        // Determine platform-specific push payload
        $pushPayload = $this->preparePushPayload($device, $payload);

        // Queue the push notification job
        // This would typically dispatch to a PushNotificationJob
        // For now, we'll use Laravel's queue directly
        dispatch(function () use ($device, $pushPayload, $payload) {
            try {
                $this->sendPushNotification($device, $pushPayload, $payload);
            } catch (\Exception $e) {
                Log::error('Failed to send push notification', [
                    'device_id' => $device->device_id,
                    'error' => $e->getMessage(),
                ]);
            }
        })->onQueue('push-notifications');
    }

    /**
     * Prepare platform-specific push payload.
     *
     * @param  Device  $device  The target device
     * @param  array<string, mixed>  $data  The notification data
     * @return array<string, mixed>
     */
    protected function preparePushPayload(Device $device, array $data): array
    {
        $eventType = $data['type'] ?? 'notification';
        $title = $this->getNotificationTitle($eventType);
        $body = $this->getNotificationBody($eventType, $data);

        $payload = [
            'title' => $title,
            'body' => $body,
            'sound' => 'default',
            'data' => $data,
        ];

        // Add platform-specific fields
        switch ($device->platform) {
            case 'ios':
                $payload['badge'] = 1;
                $payload['content-available'] = 1;
                break;

            case 'android':
                $payload['priority'] = 'high';
                break;

            case 'web':
                $payload['icon'] = '/icon.png';
                $payload['badge'] = '/badge.png';
                break;
        }

        return $payload;
    }

    /**
     * Get notification title based on event type.
     *
     * @param  string  $eventType  The event type
     */
    protected function getNotificationTitle(string $eventType): string
    {
        return match ($eventType) {
            'sync_required' => 'Sync Required',
            'signaling_offer' => 'Connection Request',
            default => 'SyncOrc',
        };
    }

    /**
     * Get notification body based on event type and data.
     *
     * @param  string  $eventType  The event type
     * @param  array<string, mixed>  $data  The event data
     */
    protected function getNotificationBody(string $eventType, array $data): string
    {
        return match ($eventType) {
            'sync_required' => 'New changes available in your SyncOrc group',
            'signaling_offer' => 'A peer is trying to connect to your device',
            default => 'You have a new SyncOrc notification',
        };
    }

    /**
     * Actually send the push notification.
     *
     * @param  Device  $device  The target device
     * @param  array<string, mixed>  $payload  The prepared push payload
     * @param  array<string, mixed>  $data  The original notification data
     */
    protected function sendPushNotification(Device $device, array $payload, array $data): void
    {
        try {
            $success = match ($device->platform) {
                'android' => $this->sendFcmNotification($device, $payload),
                'ios' => $this->sendApnsNotification($device, $payload),
                'web' => $this->sendWebPushNotification($device, $payload),
                default => false,
            };

            if ($success) {
                Log::info('Push notification sent successfully', [
                    'device_id' => $device->device_id,
                    'platform' => $device->platform,
                    'event_type' => $data['type'] ?? 'unknown',
                ]);
            } else {
                Log::warning('Push notification failed to send', [
                    'device_id' => $device->device_id,
                    'platform' => $device->platform,
                    'event_type' => $data['type'] ?? 'unknown',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send push notification', [
                'device_id' => $device->device_id,
                'platform' => $device->platform,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send push notification via Firebase Cloud Messaging (FCM) for Android.
     *
     * @param  Device  $device  The target device
     * @param  array<string, mixed>  $payload  The prepared push payload
     */
    protected function sendFcmNotification(Device $device, array $payload): bool
    {
        $fcmServerKey = config('services.fcm.server_key');

        if (empty($fcmServerKey) || empty($device->push_token)) {
            return false;
        }

        $message = [
            'to' => $device->push_token,
            'notification' => [
                'title' => $payload['title'],
                'body' => $payload['body'],
                'sound' => $payload['sound'] ?? 'default',
            ],
            'data' => $payload['data'] ?? [],
            'priority' => $payload['priority'] ?? 'high',
        ];

        $headers = [
            'Authorization: key='.$fcmServerKey,
            'Content-Type: application/json',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/fcm/send');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $response = json_decode($result, true);

            return isset($response['success']) && $response['success'] === 1;
        }

        return false;
    }

    /**
     * Send push notification via Apple Push Notification Service (APNs) for iOS.
     *
     * @param  Device  $device  The target device
     * @param  array<string, mixed>  $payload  The prepared push payload
     */
    protected function sendApnsNotification(Device $device, array $payload): bool
    {
        $apnsKeyId = config('services.apns.key_id');
        $apnsTeamId = config('services.apns.team_id');
        $apnsBundleId = config('services.apns.bundle_id');
        $apnsPrivateKey = config('services.apns.private_key');

        if (empty($apnsKeyId) || empty($apnsTeamId) || empty($apnsBundleId) ||
            empty($apnsPrivateKey) || empty($device->push_token)) {
            return false;
        }

        $url = 'https://api.push.apple.com/3/device/'.$device->push_token;

        $notification = [
            'aps' => [
                'alert' => [
                    'title' => $payload['title'],
                    'body' => $payload['body'],
                ],
                'sound' => $payload['sound'] ?? 'default',
                'badge' => $payload['badge'] ?? 1,
                'content-available' => $payload['content-available'] ?? 1,
            ],
            'data' => $payload['data'] ?? [],
        ];

        $headers = [
            'apns-topic: '.$apnsBundleId,
            'apns-push-type: alert',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($notification));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        // Note: In production, you'd need to implement JWT token generation for APNs auth
        // using the private key, key_id, and team_id

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    /**
     * Send push notification via Web Push for web browsers.
     *
     * @param  Device  $device  The target device
     * @param  array<string, mixed>  $payload  The prepared push payload
     */
    protected function sendWebPushNotification(Device $device, array $payload): bool
    {
        $vapidPublicKey = config('services.webpush.vapid_public_key');
        $vapidPrivateKey = config('services.webpush.vapid_private_key');
        $vapidSubject = config('services.webpush.vapid_subject');

        if (empty($vapidPublicKey) || empty($vapidPrivateKey) ||
            empty($vapidSubject) || empty($device->push_token)) {
            return false;
        }

        // Web Push requires the push_token to be a subscription object
        // containing endpoint, keys (p256dh, auth)
        $subscription = json_decode($device->push_token, true);

        if (! is_array($subscription) || empty($subscription['endpoint'])) {
            return false;
        }

        $notification = [
            'notification' => [
                'title' => $payload['title'],
                'body' => $payload['body'],
                'icon' => $payload['icon'] ?? '/icon.png',
                'badge' => $payload['badge'] ?? '/badge.png',
                'data' => $payload['data'] ?? [],
            ],
        ];

        // Note: In production, you'd use a Web Push library like minishlink/web-push
        // to properly encrypt and send the notification
        // This is a simplified implementation

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $subscription['endpoint']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($notification));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 201 Created is success for Web Push
        return $httpCode === 201 || $httpCode === 200;
    }
}
