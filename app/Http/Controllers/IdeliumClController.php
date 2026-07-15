<?php

namespace App\Http\Controllers;

use App\Models\Environment;
use App\Models\Plugin;
use App\Models\Step;
use App\Models\Test;
use App\Models\TestCycle;
use Illuminate\Http\Request;

class IdeliumClController extends Controller
{
    const INVALID_ID = 'Invalid id';

    public function getTestCycle(Request $request, $idTestCycle)
    {
        $customer = $this->ideliumCustomer($request);
        $query = TestCycle::where('id', $idTestCycle)
            ->where('idCostumer', $customer->id)
            ->get();
        if (count($query) != 1) {
            return response()->json([
                'message' => self::INVALID_ID,
            ], 404);
        }

        return $query[0];
    }

    public function getTest(Request $request, $idTest)
    {
        $customer = $this->ideliumCustomer($request);
        $query = Test::where('id', $idTest)
            ->where('idCostumer', $customer->id)
            ->get();
        if (count($query) != 1) {
            return response()->json([
                'message' => self::INVALID_ID,
            ], 404);
        }

        return $query[0];
    }

    public function getStep(Request $request, $idStep)
    {
        $customer = $this->ideliumCustomer($request);
        $query = Step::where('id', $idStep)
            ->where('idCostumer', $customer->id)
            ->get();
        if (count($query) != 1) {
            return response()->json([
                'message' => self::INVALID_ID,
            ], 404);

        }

        return $query[0];
    }

    public function getPlugins(Request $request, $idProject)
    {
        $customer = $this->ideliumCustomer($request);

        return Plugin::where('idProject', $idProject)
            ->where('idCostumer', $customer->id)
            ->get();
    }

    public function getPlugin(Request $request, $idPlugin)
    {
        $customer = $this->ideliumCustomer($request);
        $query = Plugin::where('id', $idPlugin)
            ->where('idCostumer', $customer->id)
            ->get();
        if (count($query) == 0) {
            return response()->json([
                'message' => self::INVALID_ID,
            ], 404);
        }

        return $query[0];
    }

    public function getEnvironments(Request $request, $idProject)
    {
        $customer = $this->ideliumCustomer($request);

        return Environment::where('idProject', $idProject)
            ->where('idCostumer', $customer->id)
            ->get();
    }

    public function getEnvironment(Request $request, $idEnvironment)
    {
        $customer = $this->ideliumCustomer($request);
        $query = Environment::where('id', $idEnvironment)
            ->where('idCostumer', $customer->id)
            ->get();
        if (count($query) != 1) {
            return response()->json([
                'message' => self::INVALID_ID,
            ], 404);
        }

        return $query[0];
    }
}
