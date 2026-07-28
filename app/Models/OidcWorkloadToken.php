<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OidcWorkloadToken extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = [
        'tokenHash',
    ];

    protected $casts = [
        'scopes' => 'array',
        'expiresAt' => 'datetime',
        'revokedAt' => 'datetime',
        'lastUsedAt' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expiresAt->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }
}
