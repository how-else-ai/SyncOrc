<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'device_id',
        'public_key',
        'api_token',
        'push_token',
        'platform',
        'last_seen_at',
        'token_expires_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'token_expires_at' => 'datetime',
    ];

    public function groupMembers(): HasMany
    {
        return $this->hasMany(GroupMember::class, 'device_id', 'id');
    }

    public function syncStates(): HasMany
    {
        return $this->hasMany(SyncState::class, 'device_id', 'id');
    }

    public function pairingRequests(): HasMany
    {
        return $this->hasMany(PairingRequest::class, 'initiator_device_id', 'id');
    }

    public function signalingOffersFrom(): HasMany
    {
        return $this->hasMany(SignalingOffer::class, 'from_device_id', 'id');
    }

    public function signalingOffersTo(): HasMany
    {
        return $this->hasMany(SignalingOffer::class, 'to_device_id', 'id');
    }

    public function cachedPayloadsFrom(): HasMany
    {
        return $this->hasMany(CachedPayload::class, 'from_device_id', 'id');
    }

    public function cachedPayloadsTo(): HasMany
    {
        return $this->hasMany(CachedPayload::class, 'to_device_id', 'id');
    }
}
