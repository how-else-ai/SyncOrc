<?php

namespace App\Models;

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
        'vector_clock' => 'array',
        'is_acknowledged' => 'boolean',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(SyncGroup::class, 'group_id', 'id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'id');
    }
}
