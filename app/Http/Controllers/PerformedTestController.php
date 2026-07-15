<?php

namespace App\Http\Controllers;

use App\Models\PerformedTest;
use Illuminate\Support\Facades\Auth;

class PerformedTestController extends Controller
{
    public function index($id)
    {
        return PerformedTest::select([
            'id',
            'testCycleDoneId',
            'testId',
            'status',
            'name',
            'updated_at',
            'created_at',
        ])->where('testId', $id)
            ->where('idCostumer', Auth::user()->idCostumer)
            ->get();
    }
}
