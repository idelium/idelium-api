<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Step;
use App\Models\Test;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportTestController extends Controller
{
    public function store(Request $request)
    {
        $this->validate($request, [
            'name' => 'required|string|max:255',
            'description' => 'required|string|max:1024',
            'import' => 'required|json',
            'idProject' => 'required|integer',
        ]);

        $project = Project::whereKey($request->input('idProject'))
            ->where('idCostumer', Auth::user()->idCostumer)
            ->firstOrFail();
        $import = json_decode($request->input('import'));
        if (! is_array($import) || count($import) === 0) {
            throw ValidationException::withMessages([
                'import' => 'The import field must contain a non-empty JSON array of Idelium steps.',
            ]);
        }

        foreach ($import as $stepImported) {
            $this->validateImportedStep($stepImported);
        }

        DB::transaction(function () use ($request, $project, $import) {
            $importTest = [];
            foreach ($import as $stepImported) {
                $step = new Step;
                $step->name = str_replace(' ', '_', trim($stepImported->name));
                $step->description = trim($stepImported->name);
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

    private function validateImportedStep(mixed $stepImported): void
    {
        if (! is_object($stepImported)) {
            throw ValidationException::withMessages([
                'import' => 'Every imported step must be a JSON object.',
            ]);
        }

        if (! isset($stepImported->name) || ! is_string($stepImported->name)
            || trim($stepImported->name) === '') {
            throw ValidationException::withMessages([
                'import' => 'Every imported step must have a non-empty name.',
            ]);
        }

        if (! isset($stepImported->steps) || ! is_array($stepImported->steps)
            || count($stepImported->steps) === 0) {
            throw ValidationException::withMessages([
                'import' => 'Every imported Idelium step must include at least one executable action.',
            ]);
        }

        foreach ($stepImported->steps as $action) {
            if (! isset($action->stepType) || ! is_string($action->stepType)
                || trim($action->stepType) === '') {
                throw ValidationException::withMessages([
                    'import' => 'Every imported action must include a stepType.',
                ]);
            }
        }
    }
}
