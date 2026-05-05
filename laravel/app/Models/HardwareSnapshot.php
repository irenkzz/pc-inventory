<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HardwareSnapshot extends Model
{
    protected $guarded = [];

    protected $casts = [
        'snapshot_json' => 'array',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(DeviceScan::class, 'device_scan_id');
    }
}
