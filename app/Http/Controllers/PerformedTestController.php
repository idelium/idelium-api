<?php

namespace App\Http\Controllers;

use App\Models\PerformedTest;
use App\Support\PaginatedResultResponse;
use App\Services\TestToolResultPayloadPolicy;
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
            'postmanData',
            'name',
            'updated_at',
            'created_at',
        ])->where('testCycleDoneId', $id)
            ->where('idCostumer', Auth::user()->idCostumer);

        $redactionPolicy = app(TestToolResultPayloadPolicy::class);

        return app(PaginatedResultResponse::class)->build($request, $query, [
            'id',
            'name',
            'status',
            'created_at',
            'updated_at',
        ], 'id', 'asc', function (PerformedTest $test) use ($redactionPolicy) {
            $test->postmanData = $test->postmanData === null
                ? null
                : json_encode($redactionPolicy->redactJsonValue($test->postmanData));

            return $test;
        });
    }
}
