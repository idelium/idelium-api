<?php

namespace App\Http\Controllers;

use App\Models\PerformedTestCycle;


class PerformedTestCycleController extends Controller
{
    public function index($id)
    {
        return PerformedTestCycle::where('testCycleId', $id)
            ->orderBy('date', 'DESC')
            ->get();
    }

}
