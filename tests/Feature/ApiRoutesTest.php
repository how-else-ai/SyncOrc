<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiRoutesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test health check endpoint.
     */
    public function test_health_check_endpoint(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'timestamp',
                'services' => [
                    'database',
                    'redis',
                    'websocket',
                    'queue',
                ],
                'version',
            ]);
    }

    /**
     * Test device registration endpoint.
     */
    public function test_device_registration(): void
    {
        $response = $this->postJson('/api/v1/devices/register', [
            'public_key' => base64_encode(random_bytes(32)),
            'platform' => 'ios',
            'push_token' => 'test-push-token',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'device_id',
                    'api_token',
                    'expires_at',
                ],
            ]);
    }

    /**
     * Test device registration validation fails with invalid platform.
     */
    public function test_device_registration_validation_fails(): void
    {
        $response = $this->postJson('/api/v1/devices/register', [
            'public_key' => base64_encode(random_bytes(32)),
            'platform' => 'invalid',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                ],
            ]);
    }

    /**
     * Test authenticated endpoint returns 401 without token.
     */
    public function test_authenticated_endpoint_requires_token(): void
    {
        $response = $this->postJson('/api/v1/devices/refresh-token', [
            'device_id' => 'test-device-id',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'AUTHENTICATION_FAILED',
                ],
            ]);
    }

    /**
     * Test undefined routes return 404.
     */
    public function test_undefined_route_returns_404(): void
    {
        $response = $this->getJson('/api/v1/undefined-endpoint');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Endpoint not found',
                ],
            ]);
    }

    /**
     * Test device refresh token validation with invalid UUID.
     */
    public function test_refresh_token_validation_fails_with_invalid_uuid(): void
    {
        $response = $this->postJson('/api/v1/devices/refresh-token', [
            'device_id' => 'not-a-uuid',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                ],
            ]);
    }

    /**
     * Test sync status validation with missing device_id.
     */
    public function test_sync_status_validation_fails_without_device_id(): void
    {
        $response = $this->getJson('/api/v1/sync/status?group_id=test-group');

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                ],
            ]);
    }

    /**
     * Test pairing accept validation with invalid group_type.
     */
    public function test_pairing_accept_validation_fails_with_invalid_group_type(): void
    {
        $response = $this->postJson('/api/v1/pairing/accept', [
            'pairing_code' => 'ABC123',
            'device_id' => 'test-device-id',
            'public_key' => base64_encode(random_bytes(32)),
            'group_type' => 'invalid',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                ],
            ]);
    }

    /**
     * Test cache store validation with missing required fields.
     */
    public function test_cache_store_validation_fails_with_missing_fields(): void
    {
        $response = $this->postJson('/api/v1/cache/store', [
            'from_device_id' => 'test-device-id',
            // Missing: to_device_id, group_id, encrypted_payload, state_version
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                ],
            ]);
    }

    /**
     * Test signaling offer validation with invalid device_id format.
     */
    public function test_signaling_offer_validation_fails_with_invalid_device_id(): void
    {
        $response = $this->postJson('/api/v1/signaling/offer', [
            'from_device_id' => 'not-a-uuid',
            'to_device_id' => 'test-device-id',
            'offer_data' => base64_encode('test-offer'),
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                ],
            ]);
    }

    /**
     * Test ack_token_hash validation requires exactly 64 characters.
     */
    public function test_sync_state_changed_validation_fails_with_invalid_hash(): void
    {
        $response = $this->postJson('/api/v1/sync/state-changed', [
            'device_id' => 'test-device-id',
            'group_id' => 'test-group-id',
            'state_version' => 'v1',
            'ack_token_hash' => 'not-64-chars', // Should be exactly 64 hex chars
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                ],
            ]);
    }
}
