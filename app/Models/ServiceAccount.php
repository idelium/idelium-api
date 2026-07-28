<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceAccount extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = [
        'secretHash',
    ];

    protected $casts = [
        'scopes' => 'array',
        'expiresAt' => 'datetime',
        'revokedAt' => 'datetime',
        'lastUsedAt' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }
}
