<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DeviceController extends Controller
{
    public function __construct(
        protected DeviceService $deviceService
    ) {}

    /**
     * Register a new device.
     *
     * POST /api/v1/devices/register
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'public_key' => 'required|string|max:2048',
            'platform' => ['required', Rule::in(['ios', 'android', 'web'])],
            'push_token' => 'nullable|string|max:512',
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
            $result = $this->deviceService->registerDevice(
                $request->input('public_key'),
                $request->input('platform'),
                $request->input('push_token')
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'device_id' => $result['device']->device_id,
                    'api_token' => $result['api_token'],
                    'expires_at' => $result['device']->token_expires_at->toIso8601String(),
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while registering the device',
                ],
            ], 500);
        }
    }

    /**
     * Refresh the API token for a device.
     *
     * POST /api/v1/devices/refresh-token
     */
    public function refreshToken(Request $request): JsonResponse
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
            $result = $this->deviceService->refreshToken($request->input('device_id'));

            return response()->json([
                'success' => true,
                'data' => [
                    'api_token' => $result['api_token'],
                    'expires_at' => $result['expires_at'],
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Device not found',
                ],
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while refreshing the token',
                ],
            ], 500);
        }
    }

    /**
     * Update the push token for a device.
     *
     * PATCH /api/v1/devices/push-token
     */
    public function updatePushToken(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'device_id' => 'required|uuid',
            'push_token' => 'required|string|max:512',
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
            $this->deviceService->updatePushToken(
                $request->input('device_id'),
                $request->input('push_token')
            );

            return response()->json([
                'success' => true,
                'message' => 'Push token updated',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Device not found',
                ],
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while updating the push token',
                ],
            ], 500);
        }
    }
}
