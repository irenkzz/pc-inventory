<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RawFile extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metadata_json' => 'array',
        'received_at' => 'datetime',
    ];

    public function scan(): HasOne
    {
        return $this->hasOne(DeviceScan::class, 'raw_hash', 'raw_hash');
    }
}
