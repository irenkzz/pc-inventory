<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Runner extends Model
{
    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'runner_id';
    }

    protected $casts = [
        'last_seen_at' => 'datetime',
        'last_direct_heartbeat_at' => 'datetime',
        'last_direct_upload_at' => 'datetime',
        'last_direct_poll_at' => 'datetime',
        'last_successful_inventory_at' => 'datetime',
        'last_command_seen_at' => 'datetime',
        'raw_state_json' => 'array',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(CollectorSite::class, 'site_id', 'site_id');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(RunnerCommand::class, 'runner_id', 'runner_id');
    }

    public function activeCommands(): HasMany
    {
        return $this->hasMany(RunnerCommand::class, 'runner_id', 'runner_id')
            ->whereIn('status', ['pending', 'dispatched'])
            ->orderByDesc('requested_at')
            ->orderByDesc('id');
    }
}
