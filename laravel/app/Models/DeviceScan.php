<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DeviceScan extends Model
{
    protected $guarded = [];

    protected $casts = [
        'scan_time' => 'datetime',
        'ingested_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function snapshot(): HasOne
    {
        return $this->hasOne(HardwareSnapshot::class);
    }

    public function rawFile(): HasOne
    {
        return $this->hasOne(RawFile::class, 'raw_hash', 'raw_hash');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(ChangeLog::class);
    }

    public function storageHealthObservations(): HasMany
    {
        return $this->hasMany(StorageHealthObservation::class);
    }
}
