<?php

namespace App\Http\Controllers;

use App\Models\PerformedStep;
use App\Models\PerformedTest;
use App\Models\PerformedTestCycle;
use App\Models\Step;
use App\Models\Test;
use App\Models\TestCycle;
use Illuminate\Http\Request;

class IdeliumInsertClController extends Controller
{
    const INVALID_DETAILS = 'Invalid details';

    public function createFolder(Request $request)
    {
        $customer = $this->ideliumCustomer($request);
        $this->validate($request, [
            'testCycleId' => 'required|integer',
        ]);

        $testCycleExists = TestCycle::where('id', $request->input('testCycleId'))
            ->where('idCostumer', $customer->id)
            ->exists();
        if (! $testCycleExists) {
            return response()->json(['message' => self::INVALID_DETAILS], 404);
        }

        date_default_timezone_set('Europe/Rome');
        $now = new \DateTime;
        $testCycle = new PerformedTestCycle;
        $testCycle->testCycleId = $request->input('testCycleId');
        $testCycle->date = $now;
        $testCycle->status = 0;
        $testCycle->idCostumer = $customer->id;
        $testCycle->save();

        return response()->json([
            'idCycle' => $testCycle->id,
        ], 200);
    }

    public function createTest(Request $request)
    {
        $customer = $this->ideliumCustomer($request);

        $this->validate($request, [
            'testCycleId' => 'required|integer',
            'testId' => 'required|integer',
            'name' => 'required|string',
        ]);

        $performedTestCycleExists = PerformedTestCycle::where(
            'id',
            $request->input('testCycleId')
        )->where('idCostumer', $customer->id)->exists();
        $testExists = Test::where('id', $request->input('testId'))
            ->where('idCostumer', $customer->id)
            ->exists();
        if (! $performedTestCycleExists || ! $testExists) {
            return response()->json(['message' => self::INVALID_DETAILS], 404);
        }

        $test = new PerformedTest;
        $test->testCycleDoneId = $request->input('testCycleId');
        $test->testId = $request->input('testId');
        $test->name = $request->input('name');
        $test->idCostumer = $customer->id;
        $test->status = 0;
        $test->save();

        return response()->json([
            'idTest' => $test->id,
        ], 200);
    }

    public function updateTest(Request $request)
    {
        $customer = $this->ideliumCustomer($request);
        $this->validate($request, [
            'testId' => 'required|integer',
            'status' => 'required|integer',
        ]);

        $test = PerformedTest::where('id', $request->input('testId'))
            ->where('idCostumer', $customer->id)
            ->first();
        if ($test === null) {
            return response()->json(['message' => self::INVALID_DETAILS], 404);
        }
        $test->status = $request->input('status');
        $test->save();

        return response()->json([
            'idTest' => $test->id,
        ], 200);
    }

    public function createStep(Request $request)
    {
        $customer = $this->ideliumCustomer($request);

        $this->validate($request, [
            'testCycleId' => 'required|integer',
            'testId' => 'required|integer',
            'stepId' => 'required|integer',
            'name' => 'required|string',
            'status' => 'required|integer',
            'screenshots' => 'required',
            'data' => 'required',
            'type' => 'required',
        ]);

        $performedTestCycleExists = PerformedTestCycle::where(
            'id',
            $request->input('testCycleId')
        )->where('idCostumer', $customer->id)->exists();
        $performedTestExists = PerformedTest::where('id', $request->input('testId'))
            ->where('testCycleDoneId', $request->input('testCycleId'))
            ->where('idCostumer', $customer->id)
            ->exists();
        $stepExists = Step::where('id', $request->input('stepId'))
            ->where('idCostumer', $customer->id)
            ->exists();
        if (! $performedTestCycleExists || ! $performedTestExists || ! $stepExists) {
            return response()->json(['message' => self::INVALID_DETAILS], 404);
        }

        $step = new PerformedStep;
        $step->testCycleDoneId = $request->input('testCycleId');
        $step->testDoneId = $request->input('testId');
        $step->stepId = $request->input('stepId');
        $step->name = $request->input('name');
        $step->status = $request->input('status');
        $step->screenshots = $request->input('screenshots');
        $step->data = $request->input('data');
        $step->type = $request->input('type');
        $step->idCostumer = $customer->id;
        $step->save();

        return response()->json([
            'idStep' => $step->id,
        ], 200);
    }

    public function updateStep(Request $request)
    {
        $customer = $this->ideliumCustomer($request);

        $this->validate($request, [
            'stepId' => 'required|integer',
            'screenshots' => 'required',
        ]);
        $step = PerformedStep::where('id', $request->input('stepId'))
            ->where('idCostumer', $customer->id)
            ->first();
        if ($step === null) {
            return response()->json(['message' => self::INVALID_DETAILS], 404);
        }
        $step->screenshots = $request->input('screenshots');
        $step->save();

        return response()->json([
            'idStep' => $step->id,
        ], 200);
    }
}
