<?php

namespace App\Http\Controllers;

use App\Models\Step;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class StepController extends Controller
{
    const INVALID_DETAILS = 'Invalid details';
    public function index(Request $request, $idProject)
    {
        return Step::select('id', 'name', 'description')
            ->orderBy('order', 'asc')
            ->where('idProject', $idProject)
            ->where('idCostumer', Auth::user()->idCostumer)
            ->get();
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'name' => 'required',
            'description' => 'required',
            'config' => 'required',
            'idProject' => 'required',
        ]);

        $step = new Step;
        $step->name = $request->input('name');
        $step->description = $request->input('description');
        $step->config = $request->input('config');
        $step->idProject = $request->input('idProject');
        $step->idCostumer = Auth::user()->idCostumer;
        $step->order = 9999999;
        $step->save();
        return $this->index($request, $request->input('idProject'));
    }

    public function show(Request $request, $idProject, $id)
    {

        $row = Step::where('id', $id)
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
            'description' => 'required',
            'name' => 'required',
            'config' => 'required',
        ]);

        $step = Step::findorFail($id);
        if ($step->idCostumer != Auth::user()->idCostumer) {
            return  response()->json(['message' => self::INVALID_DETAILS], 555);
        }
        $step->name = $request->input('name');
        $step->description = $request->input('description');
        $step->config = $request->input('config');
        $step->save();
        return $this->index($request, $idProject);
    }

    public function updateorder(Request $request, $idProject)
    {

        $this->validate($request, [
            'order' => 'required',
        ]);
        $count = 0;

        foreach ($request->input('order') as $stepObject) {
            $step = Step::findorFail($stepObject['id']);
            if ($step->idCostumer != Auth::user()->idCostumer) {
                return  response()->json(['message' => self::INVALID_DETAILS], 555);
            }
            $step->order = $count;
            $step->save();
            $count = $count + 1;
        }
        return $this->index($request, $idProject);
    }

    public function destroy(Request $request, $idProject, $id)
    {

        $step = Step::findorFail($id);
        if ($step->idCostumer != Auth::user()->idCostumer) {
            return  response()->json(['message' => self::INVALID_DETAILS], 555);
        }
        if ($step->delete()) {
            return $this->index($request, $idProject);
        }
    }
}