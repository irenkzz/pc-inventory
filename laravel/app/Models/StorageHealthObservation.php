<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorageHealthObservation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'smart_available' => 'boolean',
        'capacity_gb' => 'decimal:2',
        'tbw_gb' => 'decimal:2',
        'risk_reasons' => 'array',
        'observed_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(DeviceScan::class, 'device_scan_id');
    }
}
