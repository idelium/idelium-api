<?php

namespace App\Http\Controllers;

use App\Models\PerformedTestCycle;
use App\Support\PaginatedResultResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PerformedTestCycleController extends Controller
{
    public function index(Request $request, $id)
    {
        $query = PerformedTestCycle::select([
            'id',
            'testCycleId',
            'date',
            'status',
            'updated_at',
            'created_at',
        ])->where('testCycleId', $id)
            ->where('idCostumer', Auth::user()->idCostumer);

        return app(PaginatedResultResponse::class)->build($request, $query, [
            'id',
            'date',
            'status',
            'created_at',
            'updated_at',
        ], 'date', 'desc');
    }
}
