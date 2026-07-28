<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    public const RESULT_SUCCESS = 'success';

    public const RESULT_FAILURE = 'failure';

    protected $guarded = [];

    protected $casts = [
        'beforeValues' => 'array',
        'afterValues' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Audit events are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('Audit events are append-only and cannot be deleted.');
        });
    }
}
