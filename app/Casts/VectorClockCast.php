<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class VectorClockCast implements CastsAttributes
{
    /**
     * Cast the given value to a vector clock array.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, int>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (is_null($value)) {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (is_null($value)) {
            return null;
        }

        if (is_string($value)) {
            $value = json_decode($value, true) ?? [];
        }

        // Ensure all values are integers (version numbers)
        $vectorClock = [];
        foreach ($value as $deviceId => $version) {
            $vectorClock[$deviceId] = (int) $version;
        }

        return json_encode($vectorClock);
    }
}
