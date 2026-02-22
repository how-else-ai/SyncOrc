<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeviceService;
use App\Services\SyncCoordinatorService;
use App\Services\VectorClockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SyncController extends Controller
{
    public function __construct(
        protected SyncCoordinatorService $syncCoordinatorService,
        protected DeviceService $deviceService,
        protected VectorClockService $vectorClockService
    ) {}

    /**
     * Notify about a state change.
     *
     * POST /api/v1/sync/state-changed
     */
    public function stateChanged(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'device_id' => 'required|uuid',
            'group_id' => 'required|uuid',
            'state_version' => 'required|string|max:255',
            'ack_token_hash' => 'required|string|size:64',
            'vector_clock' => 'nullable|array',
            'vector_clock.*' => 'integer|min:0',
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

            // Check for loop using vector clock
            $vectorClock = $request->input('vector_clock', []);
            if (! empty($vectorClock)) {
                $group = \App\Models\SyncGroup::where('group_id', $request->input('group_id'))->first();
                if (! $group) {
                    return response()->json([
                        'success' => false,
                        'error' => [
                            'code' => 'NOT_FOUND',
                            'message' => 'Group not found',
                        ],
                    ], 404);
                }

                $loopDetected = $this->vectorClockService->detectLoop(
                    $group->id,
                    $device->id,
                    $vectorClock
                );

                if ($loopDetected) {
                    return response()->json([
                        'success' => true,
                        'data' => [
                            'loop_detected' => true,
                        ],
                    ]);
                }
            }

            $result = $this->syncCoordinatorService->notifyStateChange(
                $device->device_id,
                $request->input('group_id'),
                $request->input('state_version'),
                $request->input('ack_token_hash'),
                $vectorClock
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'notified_devices' => $result['notified_devices'],
                    'online_count' => $result['online_count'],
                    'push_count' => $result['push_count'],
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Device or group not found',
                ],
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while notifying state change',
                ],
            ], 500);
        }
    }

    /**
     * Acknowledge a sync.
     *
     * POST /api/v1/sync/acknowledge
     */
    public function acknowledge(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'device_id' => 'required|uuid',
            'group_id' => 'required|uuid',
            'state_version' => 'required|string|max:255',
            'ack_token_hash' => 'required|string|size:64',
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

            $this->syncCoordinatorService->acknowledgeSync(
                $device->device_id,
                $request->input('group_id'),
                $request->input('state_version'),
                $request->input('ack_token_hash')
            );

            return response()->json([
                'success' => true,
                'message' => 'Sync acknowledged',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Sync state not found',
                ],
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while acknowledging sync',
                ],
            ], 500);
        }
    }

    /**
     * Get sync status for a device in a group.
     *
     * GET /api/v1/sync/status
     */
    public function status(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'device_id' => 'required|uuid',
            'group_id' => 'required|uuid',
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

            $status = $this->syncCoordinatorService->getSyncStatus(
                $device->device_id,
                $request->input('group_id')
            );

            return response()->json([
                'success' => true,
                'data' => $status,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Device or group not found',
                ],
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while getting sync status',
                ],
            ], 500);
        }
    }
}
