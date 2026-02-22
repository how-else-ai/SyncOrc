<?php

namespace App\Observers;

use App\Models\Device;

class DeviceObserver
{
    /**
     * Handle the Device "deleting" event.
     * Clean up related records before device deletion.
     */
    public function deleting(Device $device): void
    {
        // Clean up pairing requests
        $device->pairingRequests()->delete();

        // Clean up signaling offers
        $device->signalingOffersFrom()->delete();
        $device->signalingOffersTo()->delete();

        // Clean up cached payloads
        $device->cachedPayloadsFrom()->delete();
        $device->cachedPayloadsTo()->delete();

        // Remove from all groups
        $device->groupMembers()->delete();

        // Clean up sync states
        $device->syncStates()->delete();
    }

    /**
     * Handle the Device "updated" event.
     * Validate push token format when updated.
     */
    public function updated(Device $device): void
    {
        // Validate push token format when updated
        if ($device->wasChanged('push_token') && $device->push_token) {
            $this->validatePushToken($device);
        }
    }

    /**
     * Validate push token format based on device platform.
     */
    protected function validatePushToken(Device $device): void
    {
        $token = $device->push_token;
        $platform = $device->platform;
        $isValid = true;
        $errorMessage = null;

        switch ($platform) {
            case 'android':
                // FCM tokens are typically 152-163 characters
                // They contain alphanumeric characters, underscores, and colons
                if (strlen($token) < 100 || strlen($token) > 300) {
                    $isValid = false;
                    $errorMessage = 'FCM token length is invalid';
                } elseif (! preg_match('/^[a-zA-Z0-9:_-]+$/', $token)) {
                    $isValid = false;
                    $errorMessage = 'FCM token contains invalid characters';
                }
                break;

            case 'ios':
                // APNs device tokens are 64 hex characters (32 bytes)
                // Or they can be the newer format which is longer
                if (! preg_match('/^[a-f0-9]{64,128}$/i', $token)) {
                    // Allow for newer token formats that might be base64 encoded
                    if (strlen($token) < 32) {
                        $isValid = false;
                        $errorMessage = 'APNs token is too short';
                    }
                }
                break;

            case 'web':
                // Web Push tokens are JSON objects containing endpoint and keys
                $subscription = json_decode($token, true);
                if (! is_array($subscription)) {
                    $isValid = false;
                    $errorMessage = 'Web Push token is not valid JSON';
                } elseif (empty($subscription['endpoint'])) {
                    $isValid = false;
                    $errorMessage = 'Web Push token missing endpoint';
                } elseif (! filter_var($subscription['endpoint'], FILTER_VALIDATE_URL)) {
                    $isValid = false;
                    $errorMessage = 'Web Push token endpoint is not a valid URL';
                } elseif (empty($subscription['keys']['p256dh']) || empty($subscription['keys']['auth'])) {
                    $isValid = false;
                    $errorMessage = 'Web Push token missing encryption keys';
                }
                break;

            default:
                $isValid = false;
                $errorMessage = 'Unknown platform: '.$platform;
                break;
        }

        if (! $isValid) {
            // Log the validation failure but don't throw an exception
            // The token might still work, we're just warning about potential issues
            \Illuminate\Support\Facades\Log::warning('Push token validation warning', [
                'device_id' => $device->device_id,
                'platform' => $platform,
                'token_length' => strlen($token),
                'error' => $errorMessage,
            ]);
        }
    }
}
