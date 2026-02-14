<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignalingOffer extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'signaling_offers';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'offer_id',
        'from_device_id',
        'to_device_id',
        'offer_data',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function fromDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'from_device_id', 'id');
    }

    public function toDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'to_device_id', 'id');
    }

    public function scopeValid($query)
    {
        return $query->where('expires_at', '>', now());
    }

    public function scopeForDevice($query, string $deviceId)
    {
        return $query->where('to_device_id', $deviceId);
    }
}
