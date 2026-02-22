<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Models\GroupMember;
use App\Models\PairingRequest;
use App\Models\SyncGroup;
use App\Services\PairingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PairingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_initiate_pairing_creates_request(): void
    {
        $device = Device::factory()->create();
        $service = new PairingService;

        $result = $service->initiatePairing($device->device_id, base64_encode('public-key'));

        $this->assertSame(6, strlen($result['pairing_code']));
        $this->assertNotEmpty($result['qr_data']);
        $this->assertDatabaseHas('pairing_requests', [
            'pairing_code' => $result['pairing_code'],
            'initiator_device_id' => $device->id,
        ]);
    }

    public function test_accept_pairing_creates_group_and_members(): void
    {
        $initiator = Device::factory()->create();
        $acceptor = Device::factory()->create();
        $pairingCode = 'ABC123';

        PairingRequest::factory()->create([
            'pairing_code' => $pairingCode,
            'initiator_device_id' => $initiator->id,
            'expires_at' => now()->addMinute(),
        ]);

        $service = new PairingService;

        $result = $service->acceptPairing($pairingCode, $acceptor->device_id, base64_encode('key'), 'pair');

        $this->assertSame('pair', $result['group_type']);
        $this->assertCount(2, $result['members']);
        $this->assertDatabaseCount('sync_groups', 1);
        $this->assertDatabaseCount('group_members', 2);
        $this->assertDatabaseMissing('pairing_requests', ['pairing_code' => $pairingCode]);
    }

    public function test_accept_pairing_rejects_self_pairing(): void
    {
        $device = Device::factory()->create();
        $pairingCode = 'SELF01';

        PairingRequest::factory()->create([
            'pairing_code' => $pairingCode,
            'initiator_device_id' => $device->id,
            'expires_at' => now()->addMinute(),
        ]);

        $service = new PairingService;

        $this->expectException(\Exception::class);
        $service->acceptPairing($pairingCode, $device->device_id, base64_encode('key'), 'pair');
    }

    public function test_accept_pairing_rejects_invalid_group_type(): void
    {
        $device = Device::factory()->create();

        PairingRequest::factory()->create([
            'pairing_code' => 'BADTYPE',
            'initiator_device_id' => $device->id,
            'expires_at' => now()->addMinute(),
        ]);

        $service = new PairingService;

        $this->expectException(\Exception::class);
        $service->acceptPairing('BADTYPE', Device::factory()->create()->device_id, base64_encode('key'), 'invalid');
    }

    public function test_decode_qr_data_validates_payload(): void
    {
        $service = new PairingService;
        $payload = [
            'pairing_code' => 'ABC123',
            'initiator_device_id' => Str::uuid()->toString(),
            'initiator_public_key' => 'public',
            'created_at' => now()->toIso8601String(),
        ];

        $encoded = base64_encode(json_encode($payload));

        $this->assertSame($payload['pairing_code'], $service->decodeQrData($encoded)['pairing_code']);
        $this->assertNull($service->decodeQrData('not-base64'));
        $this->assertNull($service->decodeQrData(base64_encode(json_encode(['pairing_code' => 'missing']))));
    }

    public function test_cleanup_expired_requests_and_get_active_requests(): void
    {
        $device = Device::factory()->create();
        $service = new PairingService;

        PairingRequest::factory()->create([
            'initiator_device_id' => $device->id,
            'expires_at' => now()->subMinute(),
        ]);

        PairingRequest::factory()->create([
            'initiator_device_id' => $device->id,
            'expires_at' => now()->addMinute(),
        ]);

        $this->assertSame(1, $service->cleanupExpiredRequests());
        $activeRequests = $service->getActiveRequests($device->device_id);
        $this->assertCount(1, $activeRequests);
    }

    public function test_get_active_requests_returns_empty_for_unknown_device(): void
    {
        $service = new PairingService;

        $this->assertCount(0, $service->getActiveRequests('missing-device'));
    }
}
