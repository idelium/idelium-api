<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RunToken extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = [
        'tokenHash',
    ];

    protected $casts = [
        'expiresAt' => 'datetime',
        'usedAt' => 'datetime',
        'revokedAt' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expiresAt->isPast();
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }
}
