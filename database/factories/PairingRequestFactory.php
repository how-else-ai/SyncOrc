<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\PairingRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PairingRequestFactory extends Factory
{
    protected $model = PairingRequest::class;

    public function definition(): array
    {
        return [
            'id' => Str::uuid(),
            'pairing_code' => strtoupper(Str::random(6)),
            'initiator_device_id' => Device::factory(),
            'initiator_public_key' => base64_encode(fake()->text(200)),
            'qr_data' => base64_encode(json_encode(['code' => strtoupper(Str::random(6))])),
            'expires_at' => now()->addMinutes(5),
        ];
    }
}
