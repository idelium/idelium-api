<?php

namespace App\Http\Controllers;

use App\Models\TestCycle;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class TestCycleController extends Controller
{
    const INVALID_DETAILS = 'Invalid Details';

    public function index(Request $request, $idProject)
    {

        return TestCycle::select('id', 'name', 'description')
            ->where('idCostumer', Auth::user()->idCostumer)
            ->where('idProject', $idProject)->get();
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'name' => 'required',
            'description' => 'required',
            'config' => 'required',
            'idProject' => 'required',
        ]);
        $testcycle = new TestCycle;
        $testcycle->name = $request->input('name');
        $testcycle->description = $request->input('description');
        $testcycle->config = $request->input('config');
        $testcycle->idProject = $request->input('idProject');
        $testcycle->idCostumer = Auth::user()->idCostumer;
        $testcycle->save();
        return $this->index($request, $request->input('idProject'));
    }

    public function show($idProject, $id)
    {
        return TestCycle::findorFail($id);
    }

    public function update(Request $request, $idProject, $id)
    {
        $this->validate($request, [
            'config' => 'required',
            'description' => 'required',
        ]);
        $testcycle = TestCycle::findorFail($id);
        if ($testcycle->idCostumer != Auth::user()->idCostumer) {
            return  response()->json(['message' => self::INVALID_DETAILS], 555);
        }
        $testcycle->config = $request->input('config');
        $testcycle->description = $request->input('description');
        return $this->index($request, $idProject);
    }
}
