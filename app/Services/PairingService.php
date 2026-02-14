<?php

namespace App\Services;

use App\Models\Device;
use App\Models\GroupMember;
use App\Models\PairingRequest;
use App\Models\SyncGroup;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class PairingService
{
    /**
     * Generate a random pairing code.
     *
     * @param  int  $length  Length of the pairing code
     */
    public function generatePairingCode(int $length = 6): string
    {
        // Generate uppercase alphanumeric code
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Removed similar looking chars (I, O, 1, 0)
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }

        return $code;
    }

    /**
     * Initiate a pairing request.
     *
     * @param  string  $deviceId  The initiator device UUID
     * @param  string  $publicKey  Base64-encoded public key
     * @param  int  $expiresInSeconds  TTL for pairing request (default 5 minutes)
     * @return array{pairing_code: string, qr_data: string, expires_at: string}
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function initiatePairing(string $deviceId, string $publicKey, int $expiresInSeconds = 300): array
    {
        $device = Device::where('device_id', $deviceId)->firstOrFail();

        // Generate unique pairing code
        $pairingCode = $this->generatePairingCode();
        while (PairingRequest::where('pairing_code', $pairingCode)->valid()->exists()) {
            $pairingCode = $this->generatePairingCode();
        }

        // Prepare QR data payload
        $qrPayload = [
            'pairing_code' => $pairingCode,
            'initiator_device_id' => $deviceId,
            'initiator_public_key' => $publicKey,
            'created_at' => now()->toIso8601String(),
        ];

        $qrData = base64_encode(json_encode($qrPayload));

        // Create pairing request
        $pairingRequest = PairingRequest::create([
            'pairing_code' => $pairingCode,
            'initiator_device_id' => $device->id,
            'initiator_public_key' => $publicKey,
            'qr_data' => $qrData,
            'expires_at' => now()->addSeconds($expiresInSeconds),
        ]);

        return [
            'pairing_code' => $pairingRequest->pairing_code,
            'qr_data' => $qrData,
            'expires_at' => $pairingRequest->expires_at->toIso8601String(),
        ];
    }

    /**
     * Accept a pairing request and create a sync group.
     *
     * @param  string  $pairingCode  The pairing code
     * @param  string  $acceptorDeviceId  The acceptor device UUID
     * @param  string  $acceptorPublicKey  Base64-encoded public key of acceptor
     * @param  string  $groupType  Type of group (pair, chain, group)
     * @return array{group_id: string, group_type: string, members: array<int, array>, initiator_public_key: string, acceptor_public_key: string}
     *
     * @throws \Exception
     */
    public function acceptPairing(
        string $pairingCode,
        string $acceptorDeviceId,
        string $acceptorPublicKey,
        string $groupType = 'pair'
    ): array {
        return DB::transaction(function () use ($pairingCode, $acceptorDeviceId, $acceptorPublicKey, $groupType) {
            // Find valid pairing request
            $pairingRequest = PairingRequest::where('pairing_code', $pairingCode)
                ->valid()
                ->first();

            if (! $pairingRequest) {
                throw new \Exception('Pairing request not found or expired', 404);
            }

            // Find acceptor device
            $acceptorDevice = Device::where('device_id', $acceptorDeviceId)->first();

            if (! $acceptorDevice) {
                throw new \Exception('Acceptor device not found', 404);
            }

            // Prevent pairing with self
            if ($pairingRequest->initiator_device_id === $acceptorDevice->id) {
                throw new \Exception('Cannot pair with yourself', 400);
            }

            // Validate group type
            if (! in_array($groupType, SyncGroup::groupTypes())) {
                throw new \Exception('Invalid group type', 400);
            }

            // Create sync group
            $syncGroup = SyncGroup::create([
                'group_id' => Uuid::uuid4()->toString(),
                'group_type' => $groupType,
            ]);

            // Add initiator to group
            GroupMember::create([
                'group_id' => $syncGroup->id,
                'device_id' => $pairingRequest->initiator_device_id,
                'position' => 0,
                'joined_at' => now(),
            ]);

            // Add acceptor to group
            GroupMember::create([
                'group_id' => $syncGroup->id,
                'device_id' => $acceptorDevice->id,
                'position' => $groupType === 'chain' ? 1 : null,
                'joined_at' => now(),
            ]);

            // Get device objects for response
            $initiatorDevice = $pairingRequest->initiator;

            // Delete the pairing request as it's now consumed
            $pairingRequest->delete();

            return [
                'group_id' => $syncGroup->group_id,
                'group_type' => $syncGroup->group_type,
                'members' => [
                    [
                        'device_id' => $initiatorDevice->device_id,
                        'position' => 0,
                    ],
                    [
                        'device_id' => $acceptorDevice->device_id,
                        'position' => $groupType === 'chain' ? 1 : null,
                    ],
                ],
                'initiator_public_key' => $pairingRequest->initiator_public_key,
                'acceptor_public_key' => $acceptorPublicKey,
            ];
        });
    }

    /**
     * Validate and decode QR data.
     *
     * @param  string  $qrData  Base64-encoded QR data
     * @return array{pairing_code: string, initiator_device_id: string, initiator_public_key: string, created_at: string}|null
     */
    public function decodeQrData(string $qrData): ?array
    {
        try {
            $decoded = base64_decode($qrData, true);

            if ($decoded === false) {
                return null;
            }

            $data = json_decode($decoded, true);

            if (! is_array($data)) {
                return null;
            }

            // Validate required fields
            if (! isset($data['pairing_code']) || ! isset($data['initiator_device_id']) || ! isset($data['initiator_public_key'])) {
                return null;
            }

            return $data;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Clean up expired pairing requests.
     *
     * @return int Number of deleted requests
     */
    public function cleanupExpiredRequests(): int
    {
        return PairingRequest::where('expires_at', '<=', now())->delete();
    }

    /**
     * Get active pairing requests for a device.
     *
     * @param  string  $deviceId  The device UUID
     * @return \Illuminate\Database\Eloquent\Collection<int, PairingRequest>
     */
    public function getActiveRequests(string $deviceId)
    {
        $device = Device::where('device_id', $deviceId)->first();

        if (! $device) {
            return collect();
        }

        return $device->pairingRequests()->valid()->get();
    }
}
