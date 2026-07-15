<?php

namespace App\Http\Controllers;

use App\Models\Test;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class TestController extends Controller
{
    const INVALID_DETAILS = 'Invalid details';

    public function index(Request $request, $idProject)
    {
        return Test::select('id', 'name', 'description')
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
        $test = new Test;
        $test->name = $request->input('name');
        $test->description = $request->input('description');
        $test->config = $request->input('config');
        $test->idProject = $request->input('idProject');
        $test->idCostumer = Auth::user()->idCostumer;
        $test->save();

        return $this->index($request, $request->input('idProject'));
    }

    public function show(Request $request, $idProject, $id)
    {

        $row = Test::where('id', $id)
            ->where('idProject', $idProject)
            ->where('idCostumer', Auth::user()->idCostumer)
            ->get();
        if (count($row) == 1) {
            return $row[0];
        }

        return response()->json(['message' => self::INVALID_DETAILS], 555);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @param  int  $idProject
     * @return Response
     */
    public function update(Request $request, $idProject, $id)
    {
        $this->validate($request, [
            'config' => 'required',
        ]);

        $test = Test::findorFail($id);
        if ($test->idCostumer != Auth::user()->idCostumer) {
            return response()->json(['message' => self::INVALID_DETAILS], 555);
        }
        $test->config = $request->input('config');
        $test->save();

        return $this->index($request, $idProject);
    }
}
