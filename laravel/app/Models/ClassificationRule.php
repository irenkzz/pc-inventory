<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassificationRule extends Model
{
    public const TYPE_ASSET_PREFIX_DEPARTMENT = 'asset_prefix_department';
    public const TYPE_SITE_ALIAS = 'site_alias';

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    public static function ruleTypes(): array
    {
        return [
            self::TYPE_ASSET_PREFIX_DEPARTMENT => 'Asset prefix -> department',
            self::TYPE_SITE_ALIAS => 'Scanner site -> portal site',
        ];
    }
}
