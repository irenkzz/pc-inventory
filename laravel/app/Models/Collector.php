<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Collector extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'queue_depth_csv' => 'integer',
        'queue_depth_heartbeat' => 'integer',
        'raw_status_json' => 'array',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(CollectorSite::class, 'site_id', 'site_id');
    }
}
