<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceAssignment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function startScan(): BelongsTo
    {
        return $this->belongsTo(DeviceScan::class, 'start_scan_id');
    }

    public function endScan(): BelongsTo
    {
        return $this->belongsTo(DeviceScan::class, 'end_scan_id');
    }
}
