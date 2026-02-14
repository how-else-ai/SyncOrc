<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
}
