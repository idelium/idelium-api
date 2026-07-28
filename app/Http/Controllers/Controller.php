<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateIdeliumKey;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\Costumer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use LogicException;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected function ideliumCustomer(Request $request): Costumer
    {
        $customer = $request->attributes->get(
            AuthenticateIdeliumKey::CUSTOMER_ATTRIBUTE
        );

        if (! $customer instanceof Costumer) {
            throw new LogicException('Idelium customer context is not available.');
        }

        return $customer;
    }

    protected function tenantContext(Request $request): TenantContext
    {
        $context = $request->attributes->get(ResolveTenantContext::ATTRIBUTE)
            ?? $request->attributes->get(AuthenticateIdeliumKey::TENANT_CONTEXT_ATTRIBUTE);

        if (! $context instanceof TenantContext) {
            throw new LogicException('Tenant context is not available.');
        }

        return $context;
    }
}
