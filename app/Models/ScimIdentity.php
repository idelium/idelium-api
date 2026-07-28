<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScimIdentity extends Model
{
    protected $guarded = [];

    protected $casts = [
        'groups' => 'array',
        'active' => 'boolean',
        'lastSyncedAt' => 'datetime',
    ];
}
