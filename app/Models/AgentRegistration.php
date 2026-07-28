<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentRegistration extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_MAINTENANCE = 'maintenance';

    public const STATUS_DRAINING = 'draining';

    public const STATUS_DISABLED = 'disabled';

    public const HEALTH_UNKNOWN = 'unknown';

    public const HEALTH_HEALTHY = 'healthy';

    public const HEALTH_UNHEALTHY = 'unhealthy';

    protected $guarded = [];

    protected $casts = [
        'runtimes' => 'array',
        'capabilities' => 'array',
        'identityProof' => 'array',
        'lastSeenAt' => 'datetime',
    ];
}
