<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdentityProvider extends Model
{
    public const TYPE_OIDC = 'oidc';

    public const TYPE_SAML = 'saml';

    public const TYPE_SCIM = 'scim';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected $guarded = [];

    protected $casts = [
        'redirectUris' => 'array',
        'groupRoleMap' => 'array',
        'metadata' => 'array',
    ];
}
