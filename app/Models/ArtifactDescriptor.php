<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArtifactDescriptor extends Model
{
    use HasFactory;

    public const TYPE_JSON = 'json';

    public const TYPE_JUNIT = 'junit';

    public const TYPE_MARKDOWN = 'markdown';

    public const TYPE_HTML = 'html';

    public const TYPE_SCREENSHOT = 'screenshot';

    public const TYPE_LOG = 'log';

    public const STATE_AVAILABLE = 'available';

    public const STATE_EXPIRED = 'expired';

    public const STATE_QUARANTINED = 'quarantined';

    public const STATE_UNAVAILABLE = 'unavailable';

    public const STATE_ARCHIVED = 'archived';

    public const STATE_DELETED = 'deleted';

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'retentionUntil' => 'datetime',
    ];
}
