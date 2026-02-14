<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CacheService;
use App\Services\DeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CacheController extends Controller
{
    public function __construct(
        protected CacheService $cacheService,
        protected DeviceService $deviceService
    ) {}

    /**
     * Store a cached payload for an offline device.
     *
     * POST /api/v1/cache/store
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from_device_id' => 'required|uuid',
            'to_device_id' => 'required|uuid',
            'group_id' => 'required|uuid',
            'encrypted_payload' => 'required|string',
            'state_version' => 'required|string|max:255',
            'ttl' => 'nullable|integer|min:1|max:604800',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Validation failed',
                    'details' => $validator->errors(),
                ],
            ], 422);
        }

        try {
            $fromDevice = $this->deviceService->getDevice($request->input('from_device_id'));
            $toDevice = $this->deviceService->getDevice($request->input('to_device_id'));

            if (! $fromDevice || ! $toDevice) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'NOT_FOUND',
                        'message' => 'Device not found',
                    ],
                ], 404);
            }

            $ttl = $request->input('ttl', CacheService::DEFAULT_TTL);

            $result = $this->cacheService->storePayload(
                $fromDevice->device_id,
                $toDevice->device_id,
                $request->input('group_id'),
                $request->input('encrypted_payload'),
                $request->input('state_version'),
                $ttl
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'cache_id' => $result['cache_id'],
                    'expires_at' => $result['expires_at']->toIso8601String(),
                    'size_bytes' => $result['size_bytes'],
                ],
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CACHE_LIMIT_EXCEEDED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while storing cached payload',
                ],
            ], 500);
        }
    }

    /**
     * Retrieve cached payloads for a device.
     *
     * GET /api/v1/cache/retrieve
     */
    public function retrieve(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'device_id' => 'required|uuid',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Validation failed',
                    'details' => $validator->errors(),
                ],
            ], 422);
        }

        try {
            $device = $this->deviceService->getDevice($request->input('device_id'));

            if (! $device) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'NOT_FOUND',
                        'message' => 'Device not found',
                    ],
                ], 404);
            }

            $payloads = $this->cacheService->retrievePayloads($device->device_id);

            $data = collect($payloads)->map(function ($payload) {
                return [
                    'cache_id' => $payload['cache_id'],
                    'from_device_id' => $payload['from_device_id'],
                    'group_id' => $payload['group_id'],
                    'encrypted_payload' => $payload['encrypted_payload'],
                    'state_version' => $payload['state_version'],
                    'timestamp' => $payload['timestamp'],
                    'size_bytes' => $payload['size_bytes'],
                ];
            })->toArray();

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving cached payloads',
                ],
            ], 500);
        }
    }

    /**
     * Delete a specific cached payload.
     *
     * DELETE /api/v1/cache/{cache_id}
     */
    public function delete(Request $request, string $cacheId): JsonResponse
    {
        try {
            $deleted = $this->cacheService->deletePayload($cacheId);

            if (! $deleted) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'NOT_FOUND',
                        'message' => 'Cached payload not found',
                    ],
                ], 404);
            }

            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while deleting cached payload',
                ],
            ], 500);
        }
    }
}
