<?php

namespace App\Http\Controllers;

use App\Models\VersionOs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VersionOsController extends Controller
{
    public function index(Request $request, $idOs)
    {
        if (Auth::user()->role != 1) {
            return response()->json('ok');
        }
        return VersionOs::where('idOs', '=', $idOs)
        ->orderBy('version', 'asc')->get();
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'version' => 'required',
            'idOs' => 'required',
        ]);

        if (Auth::user()->role != 1) {
            return response()->json('ok');
        }
        $osVersion = new VersionOs;
        $osVersion->version = $request->input('version');
        $osVersion->idOs = $request->input('idOs');
        $osVersion->save();
        return $this->index($request, $request->input('idOs'));
    }

    public function update(Request $request)
    {
        $this->validate($request, [
            'version' => 'required',
            'idOs' => 'required',
            'id' => 'required',
        ]);

        if (Auth::user()->role != 1) {
            return response()->json('ok');
        }
        $os = VersionOs::findorFail($request->input('id'));
        $os->version = $request->input('version');
        $os->save();
        return $this->index($request, $request->input('idOs'));
    }
}
