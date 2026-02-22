<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Models\SyncGroup;
use App\Services\DeviceService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_notify_device_handles_online_and_offline_modes(): void
    {
        $device = Device::factory()->create(['push_token' => 'token', 'platform' => 'ios']);

        $deviceService = new class($device) extends DeviceService {
            public ?Device $device;
            public bool $online = false;

            public function __construct(?Device $device)
            {
                $this->device = $device;
            }

            public function getDevice(string $deviceId): ?Device
            {
                return $this->device;
            }

            public function isOnline(string $deviceId): bool
            {
                return $this->online;
            }
        };

        $service = new class($deviceService) extends NotificationService {
            public array $broadcasts = [];
            public array $queued = [];

            protected function broadcast(string $channel, string $event, array $data): void
            {
                $this->broadcasts[] = compact('channel', 'event', 'data');
            }

            protected function queuePushNotification(Device $device, array $payload): void
            {
                $this->queued[] = ['device' => $device, 'payload' => $payload];
            }

            public function publicPreparePushPayload(Device $device, array $data): array
            {
                return $this->preparePushPayload($device, $data);
            }

            public function publicSendFcmNotification(Device $device, array $payload): bool
            {
                return $this->sendFcmNotification($device, $payload);
            }

            public function publicSendApnsNotification(Device $device, array $payload): bool
            {
                return $this->sendApnsNotification($device, $payload);
            }

            public function publicSendWebPushNotification(Device $device, array $payload): bool
            {
                return $this->sendWebPushNotification($device, $payload);
            }
        };

        $deviceService->online = true;
        $result = $service->notifyDevice($device->device_id, 'device.'.$device->device_id, 'sync_required', []);

        $this->assertTrue($result['online']);
        $this->assertSame('websocket', $result['method']);
        $this->assertCount(1, $service->broadcasts);

        $deviceService->online = false;
        $result = $service->notifyDevice($device->device_id, 'device.'.$device->device_id, 'sync_required', []);

        $this->assertSame('push', $result['method']);
        $this->assertCount(1, $service->queued);

        $device->update(['push_token' => null]);
        $result = $service->notifyDevice($device->device_id, 'device.'.$device->device_id, 'sync_required', []);

        $this->assertSame('none', $result['method']);

        $deviceService->device = null;
        $result = $service->notifyDevice('missing', 'device.missing', 'sync_required', []);
        $this->assertFalse($result['delivered']);
    }

    public function test_notify_group_events_and_push_payloads(): void
    {
        $deviceService = new class extends DeviceService {
            public function getDevice(string $deviceId): ?Device
            {
                return null;
            }
        };

        $service = new class($deviceService) extends NotificationService {
            public array $broadcasts = [];

            protected function broadcast(string $channel, string $event, array $data): void
            {
                $this->broadcasts[] = compact('channel', 'event', 'data');
            }

            public function publicPreparePushPayload(Device $device, array $data): array
            {
                return $this->preparePushPayload($device, $data);
            }
        };

        $group = SyncGroup::factory()->create();
        $device = Device::factory()->create(['platform' => 'android', 'push_token' => 'token']);

        $this->assertSame(['notified_count' => 0], $service->notifyDeviceJoined('missing-group', $device->device_id));
        $this->assertSame(['notified_count' => 1], $service->notifyDeviceJoined($group->group_id, $device->device_id));
        $this->assertSame(['notified_count' => 1], $service->notifyDeviceLeft($group->group_id, $device->device_id));
        $this->assertSame(['notified_count' => 1], $service->notifySyncAcknowledged($group->group_id, $device->device_id, 'v1'));
        $this->assertCount(4, $service->broadcasts);

        $payload = $service->publicPreparePushPayload($device, ['type' => 'sync_required']);
        $this->assertSame('high', $payload['priority']);
    }

    public function test_push_payload_platform_fields_and_send_methods(): void
    {
        $deviceService = new class extends DeviceService {};

        $service = new class($deviceService) extends NotificationService {
            public function publicPreparePushPayload(Device $device, array $data): array
            {
                return $this->preparePushPayload($device, $data);
            }

            public function publicSendFcmNotification(Device $device, array $payload): bool
            {
                return $this->sendFcmNotification($device, $payload);
            }

            public function publicSendApnsNotification(Device $device, array $payload): bool
            {
                return $this->sendApnsNotification($device, $payload);
            }

            public function publicSendWebPushNotification(Device $device, array $payload): bool
            {
                return $this->sendWebPushNotification($device, $payload);
            }
        };

        $iosDevice = Device::factory()->create(['platform' => 'ios', 'push_token' => 'token']);
        $webDevice = Device::factory()->create([
            'platform' => 'web',
            'push_token' => json_encode(['endpoint' => 'https://example.com', 'keys' => ['p256dh' => 'key', 'auth' => 'auth']]),
        ]);

        $iosPayload = $service->publicPreparePushPayload($iosDevice, ['type' => 'signaling_offer']);
        $this->assertSame(1, $iosPayload['badge']);

        $webPayload = $service->publicPreparePushPayload($webDevice, ['type' => 'default']);
        $this->assertSame('/icon.png', $webPayload['icon']);

        $this->assertFalse($service->publicSendFcmNotification($iosDevice, $iosPayload));
        $this->assertFalse($service->publicSendApnsNotification($iosDevice, $iosPayload));
        $this->assertFalse($service->publicSendWebPushNotification($webDevice, $webPayload));
    }
}
