<?php

namespace Tests\Unit;

use App\Jobs\PushNotificationJob;
use App\Jobs\SendApnsNotificationJob;
use App\Jobs\SendFcmNotificationJob;
use App\Jobs\SendWebPushNotificationJob;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Push Notification Jobs Test
 * Verifies all push notification jobs are correctly implemented.
 */
class PushNotificationJobsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that all push notification job classes exist and extend the base class.
     */
    public function test_push_notification_job_classes_exist(): void
    {
        $this->assertTrue(class_exists(PushNotificationJob::class));
        $this->assertTrue(class_exists(SendFcmNotificationJob::class));
        $this->assertTrue(class_exists(SendApnsNotificationJob::class));
        $this->assertTrue(class_exists(SendWebPushNotificationJob::class));
    }

    /**
     * Test that platform-specific jobs extend the base job class.
     */
    public function test_jobs_extend_base_class(): void
    {
        $reflectionFcm = new \ReflectionClass(SendFcmNotificationJob::class);
        $reflectionApns = new \ReflectionClass(SendApnsNotificationJob::class);
        $reflectionWebPush = new \ReflectionClass(SendWebPushNotificationJob::class);

        $this->assertTrue($reflectionFcm->isSubclassOf(PushNotificationJob::class));
        $this->assertTrue($reflectionApns->isSubclassOf(PushNotificationJob::class));
        $this->assertTrue($reflectionWebPush->isSubclassOf(PushNotificationJob::class));
    }

    /**
     * Test that all jobs implement the handle method.
     */
    public function test_jobs_implement_handle_method(): void
    {
        $this->assertTrue(method_exists(SendFcmNotificationJob::class, 'handle'));
        $this->assertTrue(method_exists(SendApnsNotificationJob::class, 'handle'));
        $this->assertTrue(method_exists(SendWebPushNotificationJob::class, 'handle'));
    }

    /**
     * Test that jobs are dispatched on the correct queue.
     */
    public function test_jobs_use_correct_queue(): void
    {
        $device = new Device([
            'device_id' => 'test-device-123',
            'public_key' => 'test-key',
            'platform' => 'android',
            'push_token' => 'test-token',
        ]);

        $payload = ['title' => 'Test', 'body' => 'Test body'];
        $data = ['type' => 'test'];

        $fcmJob = new SendFcmNotificationJob($device, $payload, $data);
        $apnsJob = new SendApnsNotificationJob($device, $payload, $data);
        $webPushJob = new SendWebPushNotificationJob($device, $payload, $data);

        $this->assertEquals('push-notifications', $fcmJob->queue);
        $this->assertEquals('push-notifications', $apnsJob->queue);
        $this->assertEquals('push-notifications', $webPushJob->queue);
    }

    /**
     * Test that jobs have correct retry configuration.
     */
    public function test_jobs_have_retry_configuration(): void
    {
        $reflectionFcm = new \ReflectionClass(SendFcmNotificationJob::class);
        $reflectionApns = new \ReflectionClass(SendApnsNotificationJob::class);
        $reflectionWebPush = new \ReflectionClass(SendWebPushNotificationJob::class);

        // Check tries property exists in base class
        $reflectionBase = new \ReflectionClass(PushNotificationJob::class);
        $this->assertTrue($reflectionBase->hasProperty('tries'));

        // Check backoff method exists in child classes
        $this->assertTrue($reflectionFcm->hasMethod('backoff'));
        $this->assertTrue($reflectionApns->hasMethod('backoff'));
        $this->assertTrue($reflectionWebPush->hasMethod('backoff'));
    }

    /**
     * Test backoff strategy returns correct intervals.
     */
    public function test_backoff_strategy(): void
    {
        $device = new Device([
            'device_id' => 'test-device',
            'public_key' => 'test-key',
            'platform' => 'android',
            'push_token' => 'test-token',
        ]);

        $payload = ['title' => 'Test'];
        $data = ['type' => 'sync_required'];

        $fcmJob = new SendFcmNotificationJob($device, $payload, $data);
        $apnsJob = new SendApnsNotificationJob($device, $payload, $data);
        $webPushJob = new SendWebPushNotificationJob($device, $payload, $data);

        $this->assertEquals([10, 30, 60], $fcmJob->backoff());
        $this->assertEquals([10, 30, 60], $apnsJob->backoff());
        $this->assertEquals([10, 30, 60], $webPushJob->backoff());
    }

    /**
     * Test that jobs can be instantiated with device and payload.
     */
    public function test_jobs_can_be_instantiated(): void
    {
        $device = new Device([
            'device_id' => 'test-device-456',
            'public_key' => 'test-key',
            'platform' => 'ios',
            'push_token' => 'apns-test-token',
        ]);

        $payload = [
            'title' => 'Sync Required',
            'body' => 'Test notification',
            'sound' => 'default',
        ];

        $data = [
            'type' => 'sync_required',
            'group_id' => 'group-123',
            'timestamp' => now()->toIso8601String(),
        ];

        $fcmJob = new SendFcmNotificationJob($device, $payload, $data);
        $apnsJob = new SendApnsNotificationJob($device, $payload, $data);
        $webPushJob = new SendWebPushNotificationJob($device, $payload, $data);

        $this->assertInstanceOf(SendFcmNotificationJob::class, $fcmJob);
        $this->assertInstanceOf(SendApnsNotificationJob::class, $apnsJob);
        $this->assertInstanceOf(SendWebPushNotificationJob::class, $webPushJob);
    }

    /**
     * Test that jobs store device and payload properties correctly.
     */
    public function test_jobs_store_properties(): void
    {
        $device = new Device([
            'device_id' => 'test-device-789',
            'public_key' => 'test-key',
            'platform' => 'web',
            'push_token' => '{"endpoint":"https://test.com/push"}',
        ]);

        $payload = ['title' => 'Test Title', 'body' => 'Test Body'];
        $data = ['type' => 'signaling_offer', 'offer_id' => 'offer-123'];

        $job = new SendWebPushNotificationJob($device, $payload, $data);

        $this->assertEquals($device, $job->device);
        $this->assertEquals($payload, $job->payload);
        $this->assertEquals($data, $job->data);
    }

    /**
     * Test that FCM job has proper error handling methods.
     */
    public function test_fcm_job_has_error_handling_methods(): void
    {
        $reflection = new \ReflectionClass(SendFcmNotificationJob::class);

        $this->assertTrue($reflection->hasMethod('sendFcmNotification'));
        $this->assertTrue($reflection->hasMethod('extractFcmError'));
        $this->assertTrue($reflection->hasMethod('isPermanentError'));
    }

    /**
     * Test that APNs job has proper authentication and error handling methods.
     */
    public function test_apns_job_has_required_methods(): void
    {
        $reflection = new \ReflectionClass(SendApnsNotificationJob::class);

        $this->assertTrue($reflection->hasMethod('sendApnsNotification'));
        $this->assertTrue($reflection->hasMethod('generateApnsJwt'));
        $this->assertTrue($reflection->hasMethod('extractApnsError'));
        $this->assertTrue($reflection->hasMethod('simulateNotification'));
    }

    /**
     * Test that Web Push job has required encryption and authentication methods.
     */
    public function test_web_push_job_has_required_methods(): void
    {
        $reflection = new \ReflectionClass(SendWebPushNotificationJob::class);

        $this->assertTrue($reflection->hasMethod('sendWebPushNotification'));
        $this->assertTrue($reflection->hasMethod('generateVapidHeaders'));
        $this->assertTrue($reflection->hasMethod('encryptPayload'));
        $this->assertTrue($reflection->hasMethod('simulateNotification'));
    }

    /**
     * Test that base job has logging methods.
     */
    public function test_base_job_has_logging_methods(): void
    {
        $reflection = new \ReflectionClass(PushNotificationJob::class);

        $this->assertTrue($reflection->hasMethod('logSuccess'));
        $this->assertTrue($reflection->hasMethod('logFailure'));
        $this->assertTrue($reflection->hasMethod('failed'));
    }

    /**
     * Test that jobs implement ShouldQueue interface.
     */
    public function test_jobs_implement_should_queue(): void
    {
        $device = new Device([
            'device_id' => 'test',
            'public_key' => 'key',
            'platform' => 'android',
            'push_token' => 'token',
        ]);

        $payload = ['title' => 'Test'];
        $data = ['type' => 'test'];

        $fcmJob = new SendFcmNotificationJob($device, $payload, $data);
        $apnsJob = new SendApnsNotificationJob($device, $payload, $data);
        $webPushJob = new SendWebPushNotificationJob($device, $payload, $data);

        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $fcmJob);
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $apnsJob);
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $webPushJob);
    }
}
