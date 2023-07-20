<?php

namespace App\Http\Controllers;

use App\Models\TestCycle;
use App\Models\Test;
use App\Models\Step;
use App\Models\Plugin;
use App\Models\Environment;
use App\Models\Costumer;
use Illuminate\Http\Request;

class IdeliumClController extends Controller
{
    const INVALID_KEY='Invalid key';
    const INVALID_ID='Invalid id';

    private function checkApiKey($key)
    {
        return  Costumer::where('apiKey', $key)->get();
    }

    public function getTestCycle(Request $request, $idTestCycle)
    {
        $key =  $request->header('Idelium-Key');
        $costumer = $this->checkApiKey($key);
        if (count($costumer) !=1 ) {
            return response()->json(['message' => self::INVALID_KEY], 401);
        }
        $query = TestCycle::where('id', $idTestCycle)->get();
        if (count($query)!=1) {
            return response()->json([
                'message'=> self::INVALID_ID
            ], 502);
        }
        return $query[0];
    }

    public function getTest(Request $request, $idTest)
    {
        $key =  $request->header('Idelium-Key');
        $costumer = $this->checkApiKey($key);
        if (count($costumer)  !=  1) {
            return response()->json(['message' => self::INVALID_KEY], 401);
        }
        $query = Test::where('id', $idTest)
            ->where('idCostumer', $costumer[0]->id)
            ->get();
        if (count($query) != 1 ) {
            return response()->json([
                'message'=> self::INVALID_ID
            ], 502);
        }
        return $query[0];
    }

    public function getStep(Request $request, $idStep)
    {
        $key =  $request->header('Idelium-Key');
        $costumer = $this->checkApiKey($key);
        if (count($costumer)  !=  1) {
            return response()->json(['message' => self::INVALID_KEY], 401);
        }
        $query = Step::where('id', $idStep)
            ->where('idCostumer', $costumer[0]->id)
            ->get();
        if (count($query) != 1 ) {
            return response()->json([
                'message'=> self::INVALID_ID
            ], 502);
 
        }
        return $query[0];
    }

    public function getPlugins(Request $request, $idProject)
    {
        $key =  $request->header('Idelium-Key');
        $costumer = $this->checkApiKey($key);
        if (count($costumer)  !=  1)
        {
            return response()->json(['message' => self::INVALID_KEY], 401);
        }
        return Plugin::where('idProject', $idProject)
            ->where('idCostumer', $costumer[0]->id)
            ->get();
    }

    public function getPlugin(Request $request, $idStep)
    {
        $key =  $request->header('Idelium-Key');
        $costumer = $this->checkApiKey($key);
        if (count($costumer) == 0) {
            return response()->json(['message' => self::INVALID_KEY], 401);
        }
        $query = Plugin::where('id', $idStep)->get();
        if (!empty($query) > 0) {
            return response()->json([
                'message'=> self::INVALID_ID
            ], 502);
        }
        return $query[0];
    }

    public function getEnvironments(Request $request, $idProject)
    {
        $key =  $request->header('Idelium-Key');
        $costumer = $this->checkApiKey($key);
        if (count($costumer)  !=  1) {
            return response()->json(['message' => self::INVALID_KEY], 401);
        }
        return Environment::where('idProject', $idProject)
            ->where('idCostumer', $costumer[0]->id)
            ->get();
    }

    public function getEnvironment(Request $request, $idEnvironment)
    {
        $key =  $request->header('Idelium-Key');
        $costumer = $this->checkApiKey($key);
        if (count($costumer)  !=  1) {
            return response()->json(['message' => self::INVALID_KEY], 401);
        }
        $query = Environment::where('id', $idEnvironment)
            ->where('idCostumer', $costumer[0]->id)
            ->get();
        if ( count($query) != 1 ) {
            return response()->json([
                'message'=> self::INVALID_ID
            ], 502);
        }
        return $query[0];
    }
}
