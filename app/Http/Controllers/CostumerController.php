<?php

namespace App\Http\Controllers;

use App\Models\Costumer;
use App\Library\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;


class CostumerController extends Controller
{

    public function index(Request $request)
    {
        if (Auth::user()->role != 1) {
            return response()->json('ok');
        }
        return Costumer::orderBy('created_at', 'asc')->get();
    }
    public function store(Request $request)
    {
        if (Auth::user()->role != 1) {
            return response()->json('ok');}
        $this->validate($request, [
            'costumer' => 'required',
        ]);
        $startDate = time();
        $apiKey= new ApiKey;
        $costumer = new Costumer;
        $costumer->costumer = strtoupper($request->input('costumer'));
        $costumer->description = strtoupper($request->input('description'));
        $costumer->licenseExpiration = date('Y-m-d H:i:s', strtotime('+365 day', $startDate));
        $costumer->apiKey = $apiKey->generateApiSignature();
        $costumer->logo = "[]";
        $costumer->save();
        return $this->index($request);
    }

    public function show(Request $request, $id)
    {
        if (Auth::user()->role != 1) {
            return response()->json('ok');}
        return Costumer::findorFail($id);
    }

    public function getKey(Request $request)
    {
        if (Auth::user()->role > 2) {
            return response()->json('ok');
        }
        $costumers = Costumer::select('apiKey')
            ->where('id', Auth::user()->idCostumer)
            ->get();
        if (count($costumers) == 1) {
            return $costumers[0];
        }
        return Auth::user()->idCostumer;
    }

    public function updateKey(Request $request)
    {
        if (Auth::user()->role > 2) {
            return response()->json('ok');
        }
        $apiKey=new apiKey;
        $costumer = Costumer::findorFail(Auth::user()->idCostumer);
        $costumer->apiKey = $apiKey->generateApiSignature();
        $costumer->save();
        return array('apiKey' => $costumer->apiKey);
    }

    public function update(Request $request, $id)
    {
        if (Auth::user()->role != 1) {
            return response()->json('ok');
        }
        $this->validate($request, [
            'costumer' => 'required',
            'description' => 'required'
        ]);

        $costumer = Costumer::findorFail($id);
        $costumer->costumer = strtoupper($request->input('costumer'));
        $costumer->description = strtoupper($request->input('description'));
        $costumer->save();
        return $this->index($request);
    }

    public function destroy(Request $request, $id)
    {
        if (Auth::user()->role != 1) {
            return response()->json('ok');
        }
        $costumer = Costumer::findorFail($id);
        if ($costumer->delete()) {
            return Costumer::orderBy('created_at', 'asc')->get();
        }
    }
}
