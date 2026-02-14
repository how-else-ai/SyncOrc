<?php

namespace Database\Factories;

use App\Models\CachedPayload;
use App\Models\Device;
use App\Models\SyncGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CachedPayloadFactory extends Factory
{
    protected $model = CachedPayload::class;

    public function definition(): array
    {
        $data = fake()->text(100);

        return [
            'id' => Str::uuid(),
            'cache_id' => Str::uuid(),
            'from_device_id' => Device::factory(),
            'to_device_id' => Device::factory(),
            'group_id' => SyncGroup::factory(),
            'encrypted_data' => $data,
            'state_version' => 'v'.fake()->numberBetween(1, 1000),
            'size_bytes' => strlen($data),
            'expires_at' => now()->addDays(7),
        ];
    }
}
