<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Device extends Model
{
    protected $guarded = [];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'manual_assignment_updated_at' => 'datetime',
    ];

    public function hasManualAssignmentOverride(): bool
    {
        foreach (['manual_department', 'manual_site', 'manual_location', 'manual_room'] as $field) {
            if (trim((string) $this->{$field}) !== '') {
                return true;
            }
        }

        return false;
    }

    public function identities(): HasMany
    {
        return $this->hasMany(DeviceIdentity::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(DeviceScan::class);
    }

    public function latestScan(): HasOne
    {
        return $this->hasOne(DeviceScan::class)->latestOfMany('scan_time');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(ChangeLog::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DeviceAssignment::class);
    }

    public function networkObservations(): HasMany
    {
        return $this->hasMany(NetworkObservation::class);
    }

    public function latestNetworkObservation(): HasOne
    {
        return $this->hasOne(NetworkObservation::class)->latestOfMany('observed_at');
    }

    public function peripherals(): HasMany
    {
        return $this->hasMany(Peripheral::class);
    }

    public function storageHealthObservations(): HasMany
    {
        return $this->hasMany(StorageHealthObservation::class);
    }
}
