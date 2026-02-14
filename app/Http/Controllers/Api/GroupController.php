<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncGroup;
use App\Services\DeviceService;
use App\Services\SyncCoordinatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GroupController extends Controller
{
    public function __construct(
        protected DeviceService $deviceService,
        protected SyncCoordinatorService $syncCoordinatorService
    ) {}

    /**
     * Get members of a group.
     *
     * GET /api/v1/groups/{group_id}/members
     */
    public function members(Request $request, string $groupId): JsonResponse
    {
        try {
            $group = SyncGroup::where('group_id', $groupId)->firstOrFail();

            $members = $group->members->map(function ($member) {
                return [
                    'device_id' => $member->device_id,
                    'position' => $member->pivot->position,
                    'is_online' => $this->deviceService->isOnline($member->device_id),
                    'last_seen' => $member->last_seen_at ? $member->last_seen_at->toIso8601String() : null,
                ];
            })->toArray();

            return response()->json([
                'success' => true,
                'data' => [
                    'group_id' => $group->group_id,
                    'group_type' => $group->group_type,
                    'members' => $members,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Group not found',
                ],
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving group members',
                ],
            ], 500);
        }
    }

    /**
     * Leave a group.
     *
     * POST /api/v1/groups/{group_id}/leave
     */
    public function leave(Request $request, string $groupId): JsonResponse
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
            $group = SyncGroup::where('group_id', $groupId)->firstOrFail();

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

            $isMember = $group->members()->where('devices.id', $device->id)->exists();

            if (! $isMember) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'NOT_FOUND',
                        'message' => 'Device is not a member of this group',
                    ],
                ], 404);
            }

            $group->members()->detach($device->id);

            return response()->json([
                'success' => true,
                'message' => 'Left group successfully',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Group not found',
                ],
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while leaving the group',
                ],
            ], 500);
        }
    }
}
