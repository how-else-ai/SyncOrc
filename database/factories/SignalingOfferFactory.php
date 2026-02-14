<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\SignalingOffer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SignalingOfferFactory extends Factory
{
    protected $model = SignalingOffer::class;

    public function definition(): array
    {
        return [
            'id' => Str::uuid(),
            'offer_id' => Str::uuid(),
            'from_device_id' => Device::factory(),
            'to_device_id' => Device::factory(),
            'offer_data' => base64_encode(fake()->text(500)),
            'expires_at' => now()->addMinutes(1),
        ];
    }
}
