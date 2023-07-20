<?php

namespace App\Http\Controllers;

use App\Models\PerformedStep;
use App\Models\PerformedTest;
use App\Models\PerformedTestCycle;
use App\Models\Costumer;
use Illuminate\Http\Request;

use function PHPUnit\Framework\isEmpty;

class IdeliumInsertClController extends Controller
{
    const INVALID_DETAILS = 'Invalid details';

    private function checkApiKey($key)
    {
        return Costumer::where('apiKey', $key)->get();
    }

    public function createFolder(Request $request)
    {
        $key =  $request->header('Idelium-Key');
        $costumer = $this->checkApiKey($key);
        if (count($costumer)  !=  1) {
            return response()->json(['message' => self::INVALID_DETAILS], 401);
        }
        $this->validate($request, [
            'testCycleId' => 'required',
        ]);

        date_default_timezone_set("Europe/Rome");
        $now = new \DateTime();
        $testCycle = new PerformedTestCycle;
        $testCycle->testCycleId = $request->input('testCycleId');
        $testCycle->date = $now;
        $testCycle->status = 0;
        $testCycle->idCostumer = $costumer[0]->id;
        $testCycle->save();
        return response()->json([
            'idCycle' => $testCycle->id
        ], 200);
    }

    public function createTest(Request $request)
    {
        $key =  $request->header('Idelium-Key');
        $costumer = $this->checkApiKey($key);
        if (count($costumer)  !=  1) {
            return response()->json(['message' => self::INVALID_DETAILS], 401);
        }

        $this->validate($request, [
            'testCycleId' => 'required',
            'testId' => 'required',
            'name' => 'required',
        ]);

        $test = new PerformedTest;
        $test->testCycleDoneId = $request->input('testCycleId');
        $test->testId = $request->input('testId');
        $test->name = $request->input('name');
        $test->idCostumer = $costumer[0]->id;
        $test->status = 0;
        $test->save();
        return response()->json([
            'idTest' => $test->id
        ], 200);
    }

    public function updateTest(Request $request)
    {
        $key =  $request->header('Idelium-Key');
        $costumer = $this->checkApiKey($key);
        if (count($costumer)  !=  1) {
            return response()->json(['message' => self::INVALID_DETAILS], 401);
        }
        $this->validate($request, [
            'testId' => 'required',
            'status' => 'required',
        ]);

        $test = PerformedTest::findorFail($request->input('testId'));
        $test->status = $request->input('status');
        $test->save();
        return response()->json([
            'idTest' => $test->id
        ], 200);
    }

    public function createStep(Request $request)
    {
        $key =  $request->header('Idelium-Key');
        $costumer = $this->checkApiKey($key);
        if (count($costumer)  !=  1) {
            return response()->json(['message' => self::INVALID_DETAILS], 401);
        }

        $this->validate($request, [
            'testCycleId' => 'required',
            'testId' => 'required',
            'stepId' => 'required',
            'name' => 'required',
            'status' => 'required',
            'screenshots' => 'required',
            'data' => 'required',
            'type' => 'required',
        ]);

        $step = new PerformedStep;
        $step->testCycleDoneId = $request->input('testCycleId');
        $step->testDoneId = $request->input('testId');
        $step->stepId = $request->input('stepId');
        $step->name = $request->input('name');
        $step->status = $request->input('status');
        $step->screenshots = $request->input('screenshots');
        $step->data = $request->input('data');
        $step->type = $request->input('type');
        $step->idCostumer = $costumer[0]->id;
        $step->save();
        return response()->json([
            'idStep' => $step->id
        ], 200);
    }

    public function updateStep(Request $request)
    {
        $key =  $request->header('Idelium-Key');
        $costumer = $this->checkApiKey($key);
        if (count($costumer) == 0) {
            return response()->json(['message' => self::INVALID_DETAILS], 401);
        }

        $this->validate($request, [
            'stepId' => 'required',
            'screenshots' => 'required',
        ]);
        $step = PerformedStep::findorFail($request->input('stepId'));
        $step->screenshots = $request->input('screenshots');
        $step->save();
        return response()->json([
            'idStep' => $step->id
        ], 200);
    }
}
