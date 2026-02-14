<?php

namespace Database\Factories;

use App\Models\SyncGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SyncGroupFactory extends Factory
{
    protected $model = SyncGroup::class;

    public function definition(): array
    {
        return [
            'id' => Str::uuid(),
            'group_id' => Str::uuid(),
            'group_type' => fake()->randomElement(['pair', 'chain', 'group']),
        ];
    }
}
