<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\GroupMember;
use App\Models\SyncGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class GroupMemberFactory extends Factory
{
    protected $model = GroupMember::class;

    public function definition(): array
    {
        return [
            'id' => Str::uuid(),
            'group_id' => SyncGroup::factory(),
            'device_id' => Device::factory(),
            'position' => fake()->optional()->numberBetween(0, 10),
            'joined_at' => now(),
        ];
    }
}
