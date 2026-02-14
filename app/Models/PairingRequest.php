<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PairingRequest extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'pairing_requests';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'pairing_code',
        'initiator_device_id',
        'initiator_public_key',
        'qr_data',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'initiator_device_id', 'id');
    }

    public function scopeValid($query)
    {
        return $query->where('expires_at', '>', now());
    }
}
