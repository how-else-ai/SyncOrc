<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ChangeEntry extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'device_id',
        'entity_type',
        'entity_id',
        'change_type',
        'content_hash',
        'version',
        'vector_clock',
        'payload_location',
    ];

    protected $casts = [
        'vector_clock' => 'array',
        'version' => 'integer',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public static function boot(): void
    {
        parent::boot();

        static::creating(function (ChangeEntry $entry): void {
            if (blank($entry->id)) {
                $entry->id = (string) Str::uuid();
            }
        });
    }
}
