<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResultExport extends Model
{
    use HasFactory;

    protected $casts = [
        'expiresAt' => 'datetime',
    ];
}
