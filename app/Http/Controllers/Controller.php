<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateIdeliumKey;
use App\Models\Costumer;
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
}
