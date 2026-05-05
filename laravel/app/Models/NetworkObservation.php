<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkObservation extends Model
{
    protected $guarded = [];

    protected $casts = [
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
