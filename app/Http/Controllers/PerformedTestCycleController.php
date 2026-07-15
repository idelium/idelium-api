<?php

namespace App\Http\Controllers;

use App\Models\PerformedTestCycle;
use Illuminate\Support\Facades\Auth;

class PerformedTestCycleController extends Controller
{
    public function index($id)
    {
        return PerformedTestCycle::select([
            'id',
            'testCycleId',
            'date',
            'status',
            'updated_at',
            'created_at',
        ])->where('testCycleId', $id)
            ->where('idCostumer', Auth::user()->idCostumer)
            ->orderBy('date', 'DESC')
            ->get();
    }
}
