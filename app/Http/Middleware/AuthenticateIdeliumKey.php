<?php

namespace App\Http\Middleware;

use App\Models\Costumer;
use App\Services\ServiceAccountService;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIdeliumKey
{
    public const CUSTOMER_ATTRIBUTE = 'ideliumCustomer';

    public const SERVICE_ACCOUNT_ATTRIBUTE = 'ideliumServiceAccount';

    public const TENANT_CONTEXT_ATTRIBUTE = 'tenantContext';

    public function __construct(private readonly ServiceAccountService $serviceAccounts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('Idelium-Key');
        $serviceAccount = $this->serviceAccounts->authenticate($apiKey);
        $customer = $serviceAccount !== null
            ? Costumer::find($serviceAccount->idCostumer)
            : null;

        if ($customer !== null && $serviceAccount !== null) {
            $request->attributes->set(self::SERVICE_ACCOUNT_ATTRIBUTE, $serviceAccount);
        }

        if ($customer === null) {
            $customer = is_string($apiKey) && $apiKey !== ''
                ? Costumer::where('apiKey', $apiKey)->first()
                : null;
        }

        if ($customer === null) {
            return response()->json(['message' => 'Invalid key'], 401);
        }

        $request->attributes->set(self::CUSTOMER_ATTRIBUTE, $customer);
        $request->attributes->set(
            self::TENANT_CONTEXT_ATTRIBUTE,
            TenantContext::forCustomerKey($customer)
        );

        return $next($request);
    }
}
