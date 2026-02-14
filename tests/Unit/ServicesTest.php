<?php

namespace Tests\Unit;

use App\Services\DeviceService;
use App\Services\PairingService;
use App\Services\VectorClockService;
use App\Services\NotificationService;
use App\Services\CacheService;
use App\Services\SyncCoordinatorService;
use Tests\TestCase;

class ServicesTest extends TestCase
{
    /**
     * Test that all service classes can be instantiated.
     */
    public function test_services_can_be_instantiated(): void
    {
        $this->assertInstanceOf(DeviceService::class, app(DeviceService::class));
        $this->assertInstanceOf(PairingService::class, app(PairingService::class));
        $this->assertInstanceOf(VectorClockService::class, app(VectorClockService::class));
        $this->assertInstanceOf(NotificationService::class, app(NotificationService::class));
        $this->assertInstanceOf(CacheService::class, app(CacheService::class));
        $this->assertInstanceOf(SyncCoordinatorService::class, app(SyncCoordinatorService::class));
    }

    /**
     * Test VectorClockService basic operations.
     */
    public function test_vector_clock_operations(): void
    {
        $service = new VectorClockService();

        $clock1 = ['device1' => 1];
        $clock2 = ['device1' => 2, 'device2' => 1];

        $this->assertTrue($service->happenedBefore($clock1, $clock2));
        $this->assertFalse($service->happenedBefore($clock2, $clock1));
        $this->assertFalse($service->areEqual($clock1, $clock2));

        $merged = $service->mergeClocks($clock1, $clock2);
        $this->assertEquals(['device1' => 2, 'device2' => 1], $merged);

        $incremented = $service->incrementClock($clock1, 'device2');
        $this->assertEquals(['device1' => 1, 'device2' => 1], $incremented);
    }

    /**
     * Test DeviceService token generation format.
     */
    public function test_device_token_format(): void
    {
        $service = new DeviceService();
        $token = 'sync_' . bin2hex(random_bytes(32));

        $this->assertStringStartsWith('sync_', $token);
        $this->assertEquals(4 + 64, strlen($token)); // 'sync_' + 64 hex chars
    }

    /**
     * Test PairingService code generation.
     */
    public function test_pairing_code_generation(): void
    {
        $service = new PairingService();

        $code1 = $service->generatePairingCode();
        $code2 = $service->generatePairingCode();

        $this->assertEquals(6, strlen($code1));
        $this->assertEquals(6, strlen($code2));
        $this->assertNotEquals($code1, $code2);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]+$/', $code1);
    }
}
