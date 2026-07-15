<?php

namespace App\Http\Controllers;

use App\Models\PerformedStep;
use Illuminate\Support\Facades\Auth;

class PerformedStepController extends Controller
{
    public function index($id)
    {
        return PerformedStep::select([
            'id',
            'testCycleDoneId',
            'testDoneId',
            'name',
            'status',
            'screenshots',
            'data',
            'type',
            'updated_at',
            'created_at',
        ])
            ->where('testDoneId', $id)
            ->where('idCostumer', Auth::user()->idCostumer)
            ->orderBy('id', 'asc')
            ->get();
    }
}
