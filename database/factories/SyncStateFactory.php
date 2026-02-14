<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\SyncGroup;
use App\Models\SyncState;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SyncStateFactory extends Factory
{
    protected $model = SyncState::class;

    public function definition(): array
    {
        return [
            'id' => Str::uuid(),
            'group_id' => SyncGroup::factory(),
            'device_id' => Device::factory(),
            'state_version' => 'v'.fake()->numberBetween(1, 1000),
            'ack_token_hash' => hash('sha256', Str::random(32)),
            'vector_clock' => null,
            'is_acknowledged' => fake()->boolean(),
        ];
    }
}
