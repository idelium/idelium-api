<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AssetVersionReviewEvent extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DEPRECATED = 'deprecated';

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Asset review events are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('Asset review events are append-only and cannot be deleted.');
        });
    }
}
