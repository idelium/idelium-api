<?php

namespace App\Http\Controllers;

use App\Models\ModelDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ModelDeviceController extends Controller
{

    public function index(Request $request, $idBrand)
    {

        if (Auth::user()->role != 1) {
            return response()->json('ok');
        }
        return ModelDevice::where('idBrand', '=', $idBrand)->orderBy('model', 'asc')->get();
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'model' => 'required',
            'idBrand' => 'required',
        ]);

        if (Auth::user()->role != 1)
        {
            return response()->json('ok');
        }
        $modelDevice = new ModelDevice;
        $modelDevice->model = $request->input('model');
        $modelDevice->idBrand = $request->input('idBrand');
        $modelDevice->save();
        return $this->index($request, $request->input('idBrand'));
    }

    public function update(Request $request)
    {
        $this->validate($request, [
            'model' => 'required',
            'idBrand' => 'required',
            'id' => 'required',
        ]);

        if (Auth::user()->role != 1)
        {
            return response()->json('ok');
        }
        $os = ModelDevice::findorFail($request->input('id'));
        $os->model = $request->input('model');
        $os->save();
        return $this->index($request, $request->input('idBrand'));
    }
}
