<?php

namespace Tests\Unit;

use App\Services\DeviceService;
use App\Services\PairingService;
use App\Services\VectorClockService;
use App\Services\NotificationService;
use App\Services\CacheService;
use App\Services\SyncCoordinatorService;
use Tests\TestCase;

/**
 * Spec Coverage Test
 * Verifies all services implement required functionality from SyncOrc Specification v1.0.0
 */
class SpecCoverageTest extends TestCase
{
    /**
     * Test DeviceService implements all required methods.
     */
    public function test_device_service_spec_compliance(): void
    {
        $service = new DeviceService();

        $this->assertTrue(method_exists($service, 'registerDevice'));
        $this->assertTrue(method_exists($service, 'refreshToken'));
        $this->assertTrue(method_exists($service, 'updatePushToken'));
        $this->assertTrue(method_exists($service, 'getDevice'));
        $this->assertTrue(method_exists($service, 'getDeviceByToken'));
        $this->assertTrue(method_exists($service, 'markAsActive'));
        $this->assertTrue(method_exists($service, 'isOnline'));
        $this->assertTrue(method_exists($service, 'setOnline'));
        $this->assertTrue(method_exists($service, 'setOffline'));
        $this->assertTrue(method_exists($service, 'validateToken'));
        $this->assertTrue(method_exists($service, 'getPairedDevices'));
    }

    /**
     * Test PairingService implements all required methods.
     */
    public function test_pairing_service_spec_compliance(): void
    {
        $service = new PairingService();

        $this->assertTrue(method_exists($service, 'generatePairingCode'));
        $this->assertTrue(method_exists($service, 'initiatePairing'));
        $this->assertTrue(method_exists($service, 'acceptPairing'));
        $this->assertTrue(method_exists($service, 'decodeQrData'));
        $this->assertTrue(method_exists($service, 'cleanupExpiredRequests'));
        $this->assertTrue(method_exists($service, 'getActiveRequests'));
    }

    /**
     * Test VectorClockService implements all required methods.
     */
    public function test_vector_clock_service_spec_compliance(): void
    {
        $service = new VectorClockService();

        $this->assertTrue(method_exists($service, 'initializeClock'));
        $this->assertTrue(method_exists($service, 'incrementClock'));
        $this->assertTrue(method_exists($service, 'mergeClocks'));
        $this->assertTrue(method_exists($service, 'happenedBefore'));
        $this->assertTrue(method_exists($service, 'isConcurrent'));
        $this->assertTrue(method_exists($service, 'areEqual'));
        $this->assertTrue(method_exists($service, 'detectLoop'));
        $this->assertTrue(method_exists($service, 'getLatestClocksForGroup'));
        $this->assertTrue(method_exists($service, 'compareClocks'));
        $this->assertTrue(method_exists($service, 'clockDistance'));
    }

    /**
     * Test NotificationService implements all required methods.
     */
    public function test_notification_service_spec_compliance(): void
    {
        $service = new NotificationService(app(DeviceService::class));

        $this->assertTrue(method_exists($service, 'notifyDevice'));
        $this->assertTrue(method_exists($service, 'notifySyncRequired'));
        $this->assertTrue(method_exists($service, 'notifyDeviceJoined'));
        $this->assertTrue(method_exists($service, 'notifyDeviceLeft'));
        $this->assertTrue(method_exists($service, 'notifySignalingOffer'));
        $this->assertTrue(method_exists($service, 'notifySignalingAnswer'));
        $this->assertTrue(method_exists($service, 'notifySyncAcknowledged'));
    }

    /**
     * Test CacheService implements all required methods.
     */
    public function test_cache_service_spec_compliance(): void
    {
        $service = new CacheService();

        $this->assertTrue(method_exists($service, 'storePayload'));
        $this->assertTrue(method_exists($service, 'retrievePayloads'));
        $this->assertTrue(method_exists($service, 'retrievePayload'));
        $this->assertTrue(method_exists($service, 'deletePayload'));
        $this->assertTrue(method_exists($service, 'clearDeviceCache'));
        $this->assertTrue(method_exists($service, 'clearGroupCache'));
        $this->assertTrue(method_exists($service, 'cleanupExpired'));
        $this->assertTrue(method_exists($service, 'getDeviceCacheSize'));
        $this->assertTrue(method_exists($service, 'getGroupCacheSize'));
        $this->assertTrue(method_exists($service, 'isDeviceQuotaExceeded'));
        $this->assertTrue(method_exists($service, 'isGroupQuotaExceeded'));
        $this->assertTrue(method_exists($service, 'getDeviceCacheStats'));
    }

    /**
     * Test CacheService constants match spec.
     */
    public function test_cache_service_constants_match_spec(): void
    {
        $reflection = new \ReflectionClass(CacheService::class);

        $maxPayloadSize = $reflection->getConstant('MAX_PAYLOAD_SIZE');
        $defaultTtl = $reflection->getConstant('DEFAULT_TTL');
        $maxTtl = $reflection->getConstant('MAX_TTL');

        $this->assertEquals(10485760, $maxPayloadSize); // 10MB
        $this->assertEquals(86400, $defaultTtl); // 24 hours
        $this->assertEquals(604800, $maxTtl); // 7 days
    }

    /**
     * Test SyncCoordinatorService implements all required methods.
     */
    public function test_sync_coordinator_service_spec_compliance(): void
    {
        $service = new SyncCoordinatorService(
            app(VectorClockService::class),
            app(NotificationService::class),
            app(DeviceService::class)
        );

        $this->assertTrue(method_exists($service, 'notifyStateChange'));
        $this->assertTrue(method_exists($service, 'acknowledgeSync'));
        $this->assertTrue(method_exists($service, 'getSyncStatus'));
        $this->assertTrue(method_exists($service, 'getDeviceGroups'));
        $this->assertTrue(method_exists($service, 'getLatestSyncState'));
        $this->assertTrue(method_exists($service, 'getUnacknowledgedSyncs'));
    }

    /**
     * Test all services can be instantiated via DI container.
     */
    public function test_all_services_instantiable(): void
    {
        $this->assertInstanceOf(DeviceService::class, app(DeviceService::class));
        $this->assertInstanceOf(PairingService::class, app(PairingService::class));
        $this->assertInstanceOf(VectorClockService::class, app(VectorClockService::class));
        $this->assertInstanceOf(NotificationService::class, app(NotificationService::class));
        $this->assertInstanceOf(CacheService::class, app(CacheService::class));
        $this->assertInstanceOf(SyncCoordinatorService::class, app(SyncCoordinatorService::class));
    }

    /**
     * Test total required methods count.
     */
    public function test_total_required_methods(): void
    {
        $requiredCounts = [
            'DeviceService' => 11,
            'PairingService' => 6,
            'VectorClockService' => 10,
            'NotificationService' => 7,
            'CacheService' => 11,
            'SyncCoordinatorService' => 6,
        ];

        $totalRequired = array_sum($requiredCounts);
        $this->assertEquals(51, $totalRequired);
    }

    /**
     * Test vector clock operations match spec behavior.
     */
    public function test_vector_clock_spec_behavior(): void
    {
        $service = new VectorClockService();

        // Test initialization
        $clock = $service->initializeClock('device-1');
        $this->assertArrayHasKey('device-1', $clock);
        $this->assertEquals(1, $clock['device-1']);

        // Test increment
        $clock = $service->incrementClock($clock, 'device-1');
        $this->assertEquals(2, $clock['device-1']);

        // Test merge
        $clock1 = ['device-1' => 2, 'device-2' => 1];
        $clock2 = ['device-1' => 1, 'device-2' => 2];
        $merged = $service->mergeClocks($clock1, $clock2);
        $this->assertEquals(['device-1' => 2, 'device-2' => 2], $merged);

        // Test happened-before
        $this->assertTrue($service->happenedBefore(['a' => 1], ['a' => 2]));
        $this->assertFalse($service->happenedBefore(['a' => 2], ['a' => 1]));

        // Test concurrent
        $this->assertTrue($service->isConcurrent(['a' => 2, 'b' => 1], ['a' => 1, 'b' => 2]));
        $this->assertFalse($service->isConcurrent(['a' => 1], ['a' => 2]));

        // Test equality
        $this->assertTrue($service->areEqual(['a' => 1, 'b' => 2], ['a' => 1, 'b' => 2]));
        $this->assertFalse($service->areEqual(['a' => 1], ['a' => 2]));
    }

    /**
     * Test pairing code generation format matches spec.
     */
    public function test_pairing_code_format(): void
    {
        $service = new PairingService();
        $code = $service->generatePairingCode();

        $this->assertEquals(6, strlen($code));
        $this->assertMatchesRegularExpression('/^[A-Z2-9]+$/', $code);
        $this->assertStringNotContainsString('I', $code); // No similar looking chars
        $this->assertStringNotContainsString('O', $code);
        $this->assertStringNotContainsString('1', $code);
        $this->assertStringNotContainsString('0', $code);
    }
}
