<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Device extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'api_token',
        'public_key',
        'capabilities',
        'last_sync_at',
    ];

    protected $hidden = [
        'api_token',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'last_sync_at' => 'datetime',
    ];

    public function changeEntries(): HasMany
    {
        return $this->hasMany(ChangeEntry::class, 'device_id');
    }

    public function cursor(): HasOne
    {
        return $this->hasOne(SyncCursor::class, 'device_id');
    }

    public static function boot(): void
    {
        parent::boot();

        static::creating(function (Device $device): void {
            if (blank($device->id)) {
                $device->id = (string) Str::uuid();
            }
            if (blank($device->api_token)) {
                $device->api_token = Str::random(48);
            }
        });
    }
}
