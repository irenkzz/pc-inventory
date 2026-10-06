<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'audit_log';

    protected $guarded = [];

    protected $casts = ['details' => 'array', 'created_at' => 'datetime'];

    /** Never pass tokens/secrets in $details. */
    public static function record(string $action, ?string $subject = null, ?array $details = null, ?string $actor = null): self
    {
        return static::query()->create([
            'actor' => $actor ?? (auth()->user()?->email ?? 'system'),
            'action' => $action,
            'subject' => $subject,
            'details' => $details,
            'created_at' => now(),
        ]);
    }
}
