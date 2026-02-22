<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SignalingOffer;
use App\Services\DeviceService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;

class SignalingController extends Controller
{
    public function __construct(
        protected DeviceService $deviceService,
        protected NotificationService $notificationService
    ) {}

    /**
     * Create a signaling offer for P2P connection.
     *
     * POST /api/v1/signaling/offer
     */
    public function offer(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from_device_id' => 'required|uuid',
            'to_device_id' => 'required|uuid',
            'offer_data' => 'required|string|max:10240',
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

            if ($fromDevice->id === $toDevice->id) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'Cannot create signaling offer to same device',
                    ],
                ], 422);
            }

            $offer = SignalingOffer::create([
                'offer_id' => Uuid::uuid4()->toString(),
                'from_device_id' => $fromDevice->id,
                'to_device_id' => $toDevice->id,
                'offer_data' => $request->input('offer_data'),
                'expires_at' => now()->addMinutes(1),
            ]);

            // Notify the target device about the signaling offer
            $this->notificationService->notifySignalingOffer(
                $offer->offer_id,
                $fromDevice->device_id,
                $toDevice->device_id,
                $request->input('offer_data')
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'offer_id' => $offer->offer_id,
                    'expires_at' => $offer->expires_at->toIso8601String(),
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while creating signaling offer',
                ],
            ], 500);
        }
    }

    /**
     * Get pending signaling offers for a device.
     *
     * GET /api/v1/signaling/offers
     */
    public function offers(Request $request): JsonResponse
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

            $offers = SignalingOffer::where('to_device_id', $device->id)
                ->where('expires_at', '>', now())
                ->orderBy('created_at', 'desc')
                ->get();

            $data = $offers->map(function ($offer) {
                return [
                    'offer_id' => $offer->offer_id,
                    'from_device_id' => $offer->fromDevice->device_id,
                    'offer_data' => $offer->offer_data,
                    'created_at' => $offer->created_at->toIso8601String(),
                    'expires_at' => $offer->expires_at->toIso8601String(),
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
                    'message' => 'An error occurred while retrieving signaling offers',
                ],
            ], 500);
        }
    }

    /**
     * Respond to a signaling offer with an answer.
     *
     * POST /api/v1/signaling/answer
     */
    public function answer(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'offer_id' => 'required|string',
            'device_id' => 'required|uuid',
            'answer_data' => 'required|string|max:10240',
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

            $offer = SignalingOffer::where('offer_id', $request->input('offer_id'))
                ->where('to_device_id', $device->id)
                ->where('expires_at', '>', now())
                ->first();

            if (! $offer) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'NOT_FOUND',
                        'message' => 'Signaling offer not found or expired',
                    ],
                ], 404);
            }

            // Notify the original offer creator about the answer
            $this->notificationService->notifySignalingAnswer(
                $offer->offer_id,
                $device->device_id,
                $offer->fromDevice->device_id,
                $request->input('answer_data')
            );

            return response()->json([
                'success' => true,
                'message' => 'Answer sent',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while sending signaling answer',
                ],
            ], 500);
        }
    }
}
