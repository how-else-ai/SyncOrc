<?php

namespace App\Models;

use App\Casts\VectorClockCast;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncState extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'group_id',
        'device_id',
        'state_version',
        'ack_token_hash',
        'vector_clock',
        'is_acknowledged',
    ];

    protected $casts = [
        'vector_clock' => VectorClockCast::class,
        'is_acknowledged' => 'boolean',
    ];

    /**
     * Boot the model and add global scopes.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function ($model) {
            // Ensure ack_token_hash is always lowercase hex
            if ($model->ack_token_hash) {
                $model->ack_token_hash = strtolower($model->ack_token_hash);
            }
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(SyncGroup::class, 'group_id', 'id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'id');
    }

    /**
     * Scope to get unacknowledged states.
     */
    public function scopeUnacknowledged($query)
    {
        return $query->where('is_acknowledged', false);
    }

    /**
     * Scope to get acknowledged states.
     */
    public function scopeAcknowledged($query)
    {
        return $query->where('is_acknowledged', true);
    }

    /**
     * Scope to filter by group.
     */
    public function scopeForGroup($query, string $groupId)
    {
        return $query->where('group_id', $groupId);
    }

    /**
     * Scope to filter by device.
     */
    public function scopeForDevice($query, string $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    /**
     * Increment the vector clock for a specific device.
     */
    public function incrementClock(string $deviceId): self
    {
        $clock = $this->vector_clock ?? [];
        $clock[$deviceId] = ($clock[$deviceId] ?? 0) + 1;
        $this->vector_clock = $clock;

        return $this;
    }

    /**
     * Merge another vector clock into this one, taking max values.
     */
    public function mergeClock(array $otherClock): self
    {
        $clock = $this->vector_clock ?? [];

        foreach ($otherClock as $deviceId => $version) {
            $clock[$deviceId] = max($clock[$deviceId] ?? 0, (int) $version);
        }

        $this->vector_clock = $clock;

        return $this;
    }

    /**
     * Check if this state happened before another state (causality check).
     */
    public function happenedBefore(self $other): bool
    {
        $thisClock = $this->vector_clock ?? [];
        $otherClock = $other->vector_clock ?? [];

        $allKeys = array_unique(array_merge(array_keys($thisClock), array_keys($otherClock)));

        $allLessOrEqual = true;
        $anyLess = false;

        foreach ($allKeys as $key) {
            $thisVal = $thisClock[$key] ?? 0;
            $otherVal = $otherClock[$key] ?? 0;

            if ($thisVal > $otherVal) {
                $allLessOrEqual = false;
                break;
            }

            if ($thisVal < $otherVal) {
                $anyLess = true;
            }
        }

        return $allLessOrEqual && $anyLess;
    }

    /**
     * Check if this state is concurrent with another (neither happened before the other).
     */
    public function isConcurrentWith(self $other): bool
    {
        return ! $this->happenedBefore($other) && ! $other->happenedBefore($this);
    }
}
