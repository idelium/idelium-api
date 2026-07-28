<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ParallelRunSchedule extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_CANCELLING = 'cancelling';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_LOST = 'lost';

    public const WORKER_RUNNING = 'running';

    public const WORKER_COMPLETED = 'completed';

    public const WORKER_FAILED = 'failed';

    public const WORKER_CANCELLED = 'cancelled';

    public const WORKER_LOST = 'lost';

    public const RESULT_PASSED = 1;

    public const RESULT_FAILED = 2;

    public const RESULT_CANCELLED = 3;

    public const RESULT_LOST = 4;

    protected $casts = [
        'metadata' => 'array',
        'workerStates' => 'array',
        'resultSummary' => 'array',
        'scheduledAt' => 'datetime',
        'startedAt' => 'datetime',
        'completedAt' => 'datetime',
        'cancelledAt' => 'datetime',
    ];

    protected $guarded = [];
}
