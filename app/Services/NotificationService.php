<?php

namespace App\Services;

use App\Models\Device;
use App\Models\SyncGroup;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
     * @return void
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
     * @return void
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
     * @return string
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
     * @return string
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
     * Actually send the push notification (placeholder implementation).
     *
     * This method would integrate with FCM, APNs, or Web Push providers.
     * For now, it's a stub that can be implemented later.
     *
     * @param  Device  $device  The target device
     * @param  array<string, mixed>  $payload  The prepared push payload
     * @param  array<string, mixed>  $data  The original notification data
     * @return void
     */
    protected function sendPushNotification(Device $device, array $payload, array $data): void
    {
        // TODO: Implement actual push notification sending
        // This would use:
        // - FCM for Android devices
        // - APNs (via Pushok or similar) for iOS devices
        // - Web Push for web devices

        Log::info('Push notification queued', [
            'device_id' => $device->device_id,
            'platform' => $device->platform,
            'event_type' => $data['type'] ?? 'unknown',
        ]);
    }
}
