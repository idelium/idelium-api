<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationEndpoint extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const ADAPTER_WEBHOOK = 'webhook';

    public const ADAPTER_JIRA = 'jira';

    public const ADAPTER_SLACK = 'slack';

    public const ADAPTER_TEAMS = 'teams';

    protected $guarded = [];

    protected $hidden = [
        'secretEncrypted',
    ];

    protected $casts = [
        'events' => 'array',
        'metadata' => 'array',
    ];

    public function deliveries(): HasMany
    {
        return $this->hasMany(IntegrationDelivery::class, 'integrationEndpointId');
    }

    public function acceptsEvent(string $event): bool
    {
        $events = $this->events ?? ['*'];

        return in_array('*', $events, true) || in_array($event, $events, true);
    }
}
