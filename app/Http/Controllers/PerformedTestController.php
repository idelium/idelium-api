<?php

namespace App\Http\Controllers;

use App\Models\PerformedTest;
use App\Support\PaginatedResultResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PerformedTestController extends Controller
{
    public function index(Request $request, $id)
    {
        $query = PerformedTest::select([
            'id',
            'testCycleDoneId',
            'testId',
            'status',
            'name',
            'updated_at',
            'created_at',
        ])->where('testCycleDoneId', $id)
            ->where('idCostumer', Auth::user()->idCostumer);

        return app(PaginatedResultResponse::class)->build($request, $query, [
            'id',
            'name',
            'status',
            'created_at',
            'updated_at',
        ], 'id', 'asc');
    }
}
