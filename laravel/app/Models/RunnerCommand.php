<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RunnerCommand extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload_json' => 'array',
        'requested_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'delivered_to_runner_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function runner(): BelongsTo
    {
        return $this->belongsTo(Runner::class, 'runner_id', 'runner_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(CollectorSite::class, 'site_id', 'site_id');
    }
}
