<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CollectorSite extends Model
{
    protected $primaryKey = 'site_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function collectors(): HasMany
    {
        return $this->hasMany(Collector::class, 'site_id', 'site_id');
    }

    public function runners(): HasMany
    {
        return $this->hasMany(Runner::class, 'site_id', 'site_id');
    }
}
