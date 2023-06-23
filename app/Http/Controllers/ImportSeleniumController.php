<?php

namespace App\Http\Controllers;

use App\Models\Step;
use App\Models\Test;
use Illuminate\Support\Facades\Auth;

use Illuminate\Http\Request;

class ImportSeleniumController extends Controller
{
    public function store(Request $request)
    {
        $this->validate($request, [
            'name' => 'required',
            'description' => 'required',
            'import' => 'required',
            'idProject' => 'required',
        ]);
        $import=json_decode($request->input('import'));
        $importTest=array();
        foreach ($import as $stepImported) {
            $step = new Step;
            $step->name = str_replace(' ','_',$stepImported->name);
            $step->description = $stepImported->name;
            $step->config = json_encode($stepImported);
            $step->idProject = $request->input('idProject');
            $step->idCostumer = Auth::user()->idCostumer;
            $step->order = 9999999;
            $step->save();
            $importTest[]=array(
                'id' => $step->id,
                'name' => $step->name,
                'description' => $step->description,
            );
        }
        $test = new Test;
        $test->name = $request->input('name');
        $test->description = $request->input('description');
        $test->config = json_encode($importTest);
        $test->idProject = $request->input('idProject');
        $test->idCostumer = Auth::user()->idCostumer;
        $test->save();

        return response()->json(array(
            'status' => 'ok',
        ));
    }
}
