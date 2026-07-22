<?php

namespace App\Http\Controllers;

use App\Models\PerformedStep;
use App\Services\TestToolResultPayloadPolicy;
use Illuminate\Support\Facades\Auth;

class PerformedStepController extends Controller
{
    public function index($id)
    {
        $redactionPolicy = app(TestToolResultPayloadPolicy::class);

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
            ->get()
            ->map(function (PerformedStep $step) use ($redactionPolicy) {
                $step->data = json_encode($redactionPolicy->redactJsonValue($step->data));
                $step->screenshots = json_encode(
                    $redactionPolicy->redactJsonValue($step->screenshots)
                );

                return $step;
            });
    }
}
