<?php

namespace App\Http\Controllers;

use App\Services\CapabilityService;
use Illuminate\Http\Request;

class CapabilityController extends Controller
{
    public function __construct(private readonly CapabilityService $capabilities) {}

    public function me(Request $request)
    {
        return response()->json([
            'version' => $this->capabilities->version(),
            'capabilities' => $this->capabilities->forUser($request->user()),
        ]);
    }
}
