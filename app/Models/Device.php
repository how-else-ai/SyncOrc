<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    protected $hidden = [
        'api_token',
    ];

    /**
     * Boot the model and add global event handlers.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($device) {
            if (empty($device->api_token)) {
                $device->api_token = self::generateApiToken();
            }
            if (empty($device->token_expires_at)) {
                $device->token_expires_at = now()->addYear();
            }
        });
    }

    /**
     * Generate a secure API token.
     */
    public static function generateApiToken(): string
    {
        return 'sync_'.bin2hex(random_bytes(32));
    }

    /**
     * Get the groups this device belongs to.
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(SyncGroup::class, 'group_members', 'device_id', 'group_id')
            ->withPivot('position', 'joined_at')
            ->withTimestamps();
    }

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

    /**
     * Scope to get active (non-expired) devices.
     */
    public function scopeActive($query)
    {
        return $query->where('token_expires_at', '>', now());
    }

    /**
     * Scope to get devices by platform.
     */
    public function scopeByPlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }

    /**
     * Scope to get recently seen devices.
     */
    public function scopeRecentlySeen($query, ?int $minutes = 60)
    {
        return $query->where('last_seen_at', '>', now()->subMinutes($minutes));
    }

    /**
     * Scope to get devices that haven't been seen recently.
     */
    public function scopeStale($query, ?int $days = 30)
    {
        return $query->where(function ($q) use ($days) {
            $q->whereNull('last_seen_at')
                ->orWhere('last_seen_at', '<', now()->subDays($days));
        });
    }

    /**
     * Mark the device as currently active.
     */
    public function markActive(): self
    {
        $this->last_seen_at = now();

        return $this;
    }

    /**
     * Check if the API token is valid.
     */
    public function isTokenValid(): bool
    {
        return $this->token_expires_at === null || $this->token_expires_at->isFuture();
    }

    /**
     * Refresh the API token.
     */
    public function refreshToken(): self
    {
        $this->api_token = self::generateApiToken();
        $this->token_expires_at = now()->addYear();

        return $this;
    }

    /**
     * Check if the device can receive push notifications.
     */
    public function canReceivePush(): bool
    {
        return ! empty($this->push_token);
    }
}
