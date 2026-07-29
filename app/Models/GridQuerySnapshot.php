<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GridQuerySnapshot extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'query' => 'array',
        'entityIds' => 'array',
        'expiresAt' => 'datetime',
    ];
}
