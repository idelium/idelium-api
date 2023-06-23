<?php

namespace App\Http\Controllers;

use App\Models\PerformedStep;


class PerformedStepController extends Controller
{
    public function index($id)
    {
        return PerformedStep::select(
            'id',
            'testcycleDoneId',
            'testDoneId',
            'name',
            'status',
            'screenshots',
            'data',
            'type',
            'updated_at',
            'created_at'
        )
            ->where('testDoneId', $id)
            ->orderBy('id', 'asc')
            ->get();
    }
}
