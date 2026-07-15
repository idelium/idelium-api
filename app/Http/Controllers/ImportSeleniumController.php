<?php

namespace App\Http\Controllers;

use App\Models\Step;
use App\Models\Test;
use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use Illuminate\Http\Request;

class ImportSeleniumController extends Controller
{
    public function store(Request $request)
    {
        $this->validate($request, [
            'name' => 'required',
            'description' => 'required',
            'import' => 'required|json',
            'idProject' => 'required|integer',
        ]);

        $project = Project::whereKey($request->input('idProject'))
            ->where('idCostumer', Auth::user()->idCostumer)
            ->firstOrFail();
        $import = json_decode($request->input('import'));
        if (! is_array($import)) {
            throw ValidationException::withMessages([
                'import' => 'The import field must contain a JSON array.',
            ]);
        }
        foreach ($import as $stepImported) {
            if (! isset($stepImported->name) || ! is_string($stepImported->name)
                || trim($stepImported->name) === '') {
                throw ValidationException::withMessages([
                    'import' => 'Every imported step must have a non-empty name.',
                ]);
            }
        }

        DB::transaction(function () use ($request, $project, $import) {
            $importTest = [];
            foreach ($import as $stepImported) {
                $step = new Step;
                $step->name = str_replace(' ', '_', $stepImported->name);
                $step->description = $stepImported->name;
                $step->config = json_encode($stepImported);
                $step->idProject = $project->id;
                $step->idCostumer = Auth::user()->idCostumer;
                $step->order = 9999999;
                $step->save();
                $importTest[] = [
                    'id' => $step->id,
                    'name' => $step->name,
                    'description' => $step->description,
                ];
            }

            $test = new Test;
            $test->name = $request->input('name');
            $test->description = $request->input('description');
            $test->config = json_encode($importTest);
            $test->idProject = $project->id;
            $test->idCostumer = Auth::user()->idCostumer;
            $test->save();
        });

        return response()->json([
            'status' => 'ok',
        ]);
    }
}
