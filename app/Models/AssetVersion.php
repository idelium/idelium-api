<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AssetVersion extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Asset versions are immutable and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('Asset versions are immutable and cannot be deleted.');
        });
    }
}
