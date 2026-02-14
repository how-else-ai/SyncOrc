<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeviceService;
use App\Services\NotificationService;
use App\Services\PairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PairingController extends Controller
{
    public function __construct(
        protected PairingService $pairingService,
        protected DeviceService $deviceService,
        protected NotificationService $notificationService
    ) {}

    /**
     * Initiate a pairing request.
     *
     * POST /api/v1/pairing/initiate
     */
    public function initiate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'device_id' => 'required|uuid',
            'public_key' => 'required|string|max:2048',
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

            $result = $this->pairingService->initiatePairing(
                $device->device_id,
                $request->input('public_key')
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'pairing_code' => $result['pairing_code'],
                    'qr_data' => $result['qr_data'],
                    'expires_in' => $result['expires_at']->diffInSeconds(now()),
                    'expires_at' => $result['expires_at']->toIso8601String(),
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while initiating pairing',
                ],
            ], 500);
        }
    }

    /**
     * Accept a pairing request and create a sync group.
     *
     * POST /api/v1/pairing/accept
     */
    public function accept(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'pairing_code' => 'required|string|max:10',
            'device_id' => 'required|uuid',
            'public_key' => 'required|string|max:2048',
            'group_type' => ['required', Rule::in(['pair', 'chain', 'group'])],
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
            $acceptorDevice = $this->deviceService->getDevice($request->input('device_id'));

            if (! $acceptorDevice) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'NOT_FOUND',
                        'message' => 'Device not found',
                    ],
                ], 404);
            }

            $result = $this->pairingService->acceptPairing(
                $request->input('pairing_code'),
                $acceptorDevice->device_id,
                $request->input('public_key'),
                $request->input('group_type')
            );

            // Notify the initiator about successful pairing
            $this->notificationService->notifyPairingAccepted(
                $result['group']->id,
                $acceptorDevice->device_id,
                $request->input('group_type')
            );

            // Build members list
            $members = $result['group']->members->map(function ($member) {
                return [
                    'device_id' => $member->device_id,
                    'position' => $member->pivot->position,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'group_id' => $result['group']->group_id,
                    'group_type' => $result['group']->group_type,
                    'members' => $members,
                    'initiator_public_key' => $result['initiator_public_key'],
                    'acceptor_public_key' => $request->input('public_key'),
                ],
            ], 201);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'PAIRING_INVALID',
                    'message' => 'Pairing code not found or expired',
                ],
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while accepting pairing',
                ],
            ], 500);
        }
    }
}
