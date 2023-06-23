<?php

namespace App\Http\Controllers;

use App\Models\Plugin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class PluginController extends Controller
{
    const INVALID_DETAILS='Invalid details';

    public function index(Request $request, $idProject)
    {

        return Plugin::select('id', 'name', 'description')->where(
            'idProject',
            $idProject
        )->where(
            'idCostumer',
            Auth::user()->idCostumer
        )->get();
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'name' => 'required',
            'code' => 'required',
            'description' => 'required',
            'idProject' => 'required',
        ]);


        $plugin = new Plugin;
        $plugin->name = $request->input('name');
        $plugin->code =  json_encode($request->input('code'));
        $plugin->description = $request->input('description');
        $plugin->idProject = $request->input('idProject');
        $plugin->idCostumer = Auth::user()->idCostumer;

        $plugin->save();
        return $this->index($request, $request->input('idProject'));
    }

    public function show(Request $request, $idProject, $id)
    {

        $row = Plugin::where('id', $id)
            ->where('idProject', $idProject)
            ->where('idCostumer', Auth::user()->idCostumer)
            ->get();
        if (count($row) == 1) {
            return  $row[0];
        }
        return response()->json(['message' => self::INVALID_DETAILS], 555);
    }

    public function update(Request $request, $idProject, $id)
    {
        $this->validate($request, [
            'code' => 'required',
        ]);


        $plugin = Plugin::findorFail($id);
        if ($plugin->idCostumer != Auth::user()->idCostumer) {
            return  response()->json(['message' => self::INVALID_DETAILS], 555);
        }
        $plugin->code = $request->input('code');
        $plugin->save();
        return $this->index($request, $idProject);
    }

    public function destroy(Request $request, $idProject, $id)
    {

        $plugin = Plugin::findorFail($id);
        if ($plugin->idCostumer != Auth::user()->idCostumer) {
            return  response()->json(['message' => self::INVALID_DETAILS], 555);
        }
        if ($plugin->delete()) {
            return $this->index($request, $idProject);
        }
    }
}
