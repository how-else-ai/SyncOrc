<?php

namespace Database\Factories;

use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        return [
            'id' => Str::uuid(),
            'device_id' => Str::uuid(),
            'public_key' => base64_encode(fake()->text(200)),
            'api_token' => hash('sha256', Str::random(64)),
            'push_token' => fake()->optional()->text(100),
            'platform' => fake()->randomElement(['ios', 'android', 'web']),
            'last_seen_at' => fake()->optional()->dateTime(),
            'token_expires_at' => now()->addDays(7),
        ];
    }
}
