<?php

namespace Tests\Unit;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceModelTest extends TestCase
{
    use RefreshDatabase;
    /** @test */
    public function test_device_generates_uuid_when_id_is_blank(): void
    {
        $device = Device::create(['name' => 'test']);

        $this->assertNotNull($device->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $device->id
        );
    }

    /** @test */
    public function test_device_generates_api_token_when_blank(): void
    {
        $device = Device::create(['name' => 'test']);

        $this->assertNotNull($device->api_token);
        $this->assertIsString($device->api_token);
        $this->assertGreaterThan(0, strlen($device->api_token));
    }

    /** @test */
    public function test_device_uses_string_key_type_and_non_incrementing(): void
    {
        $device = new Device();

        $this->assertSame('string', $device->getKeyType());
        $this->assertFalse($device->getIncrementing());
    }

    /** @test */
    public function test_device_hides_api_token(): void
    {
        $device = Device::create(['name' => 'test', 'api_token' => 'secret-token']);

        $array = $device->toArray();

        $this->assertArrayNotHasKey('api_token', $array);
    }

    /** @test */
    public function test_device_preserves_explicit_id(): void
    {
        $explicitId = '11111111-1111-1111-1111-111111111111';
        $device = new Device();
        $device->id = $explicitId;
        $device->name = 'test';
        $device->save();

        $this->assertSame($explicitId, $device->id);
    }
}
