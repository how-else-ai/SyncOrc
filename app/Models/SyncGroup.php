<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncGroup extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'sync_groups';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'group_id',
        'group_type',
    ];

    protected $casts = [
        'group_type' => 'string',
    ];

    /**
     * Group type constants.
     */
    public const TYPE_PAIR = 'pair';
    public const TYPE_CHAIN = 'chain';
    public const TYPE_GROUP = 'group';

    /**
     * Get all valid group types.
     *
     * @return array<string>
     */
    public static function groupTypes(): array
    {
        return [self::TYPE_PAIR, self::TYPE_CHAIN, self::TYPE_GROUP];
    }

    /**
     * Get the devices in this group.
     */
    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class, 'group_members', 'group_id', 'device_id')
            ->withPivot('position', 'joined_at')
            ->orderByPivot('position')
            ->withTimestamps();
    }

    public function groupMembers(): HasMany
    {
        return $this->hasMany(GroupMember::class, 'group_id', 'id');
    }

    public function syncStates(): HasMany
    {
        return $this->hasMany(SyncState::class, 'group_id', 'id');
    }

    public function cachedPayloads(): HasMany
    {
        return $this->hasMany(CachedPayload::class, 'group_id', 'id');
    }

    /**
     * Scope to filter by group type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('group_type', $type);
    }

    /**
     * Scope to get pair groups.
     */
    public function scopePairs($query)
    {
        return $query->where('group_type', self::TYPE_PAIR);
    }

    /**
     * Scope to get chain groups.
     */
    public function scopeChains($query)
    {
        return $query->where('group_type', self::TYPE_CHAIN);
    }

    /**
     * Scope to get multi-device groups.
     */
    public function scopeGroups($query)
    {
        return $query->where('group_type', self::TYPE_GROUP);
    }

    /**
     * Check if this is a pair group (2 devices).
     */
    public function isPair(): bool
    {
        return $this->group_type === self::TYPE_PAIR;
    }

    /**
     * Check if this is a chain group.
     */
    public function isChain(): bool
    {
        return $this->group_type === self::TYPE_CHAIN;
    }

    /**
     * Check if this is a multi-device group.
     */
    public function isGroup(): bool
    {
        return $this->group_type === self::TYPE_GROUP;
    }

    /**
     * Get the member count for this group.
     */
    public function memberCount(): int
    {
        return $this->groupMembers()->count();
    }

    /**
     * Get devices ordered by chain position (for chain topology).
     *
     * @return Collection<int, Device>
     */
    public function devicesInChainOrder(): Collection
    {
        return $this->devices()->orderByPivot('position', 'asc')->get();
    }

    /**
     * Get the next device in the chain.
     */
    public function nextDeviceInChain(string $deviceId): ?Device
    {
        $currentPosition = $this->groupMembers()
            ->where('device_id', $deviceId)
            ->value('position');

        if ($currentPosition === null) {
            return null;
        }

        $nextMember = $this->groupMembers()
            ->where('position', '>', $currentPosition)
            ->orderBy('position')
            ->first();

        return $nextMember?->device;
    }

    /**
     * Get the previous device in the chain.
     */
    public function previousDeviceInChain(string $deviceId): ?Device
    {
        $currentPosition = $this->groupMembers()
            ->where('device_id', $deviceId)
            ->value('position');

        if ($currentPosition === null) {
            return null;
        }

        $prevMember = $this->groupMembers()
            ->where('position', '<', $currentPosition)
            ->orderByDesc('position')
            ->first();

        return $prevMember?->device;
    }

    /**
     * Check if the group has a specific device as member.
     */
    public function hasDevice(string $deviceId): bool
    {
        return $this->groupMembers()->where('device_id', $deviceId)->exists();
    }
}
