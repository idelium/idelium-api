<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Environment;
use App\Models\Plugin;
use App\Models\Step;
use App\Models\Test;
use App\Models\TestCycle;
use App\Models\PerformedStep;
use App\Models\PerformedTest;
use App\Models\PerformedTestCycle;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

use Illuminate\Http\Request;

class ProjectController extends Controller
{
    const INVALID_DETAILS = 'Invalid Details';

    public function index(Request $request)
    {
        return Project::where('idCostumer', Auth::user()->idCostumer)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'name' => 'required',
            'description' => 'required',
        ]);
        $project = new Project;
        $project->name = strtoupper($request->input('name'));
        $project->description = $request->input('description');
        $project->idCostumer = Auth::user()->idCostumer;
        $project->save();
        return $this->index($request);
    }

    public function show(Request $request, $id)
    {
        $row = Project::where('id', $id)
            ->where('idCostumer', Auth::user()->idCostumer)
            ->get();
        if (count($row) == 1) {
            return  $row[0];
        }
        return response()->json(['message' => self::INVALID_DETAILS], 555);
    }

    public function update(Request $request, $id)
    {
        $this->validate($request, [
            'name' => 'required',
            'description' => 'required',
        ]);


        $project = Project::findorFail($id);
        if ($project->idCostumer != Auth::user()->idCostumer) {
            return  response()->json(['message' => self::INVALID_DETAILS], 555);
        }
        $project->name = $request->input('name');
        $project->description = $request->input('description');
        $project->save();
        return $this->index($request);
    }

    public function destroy(Request $request, $id)
    {
        DB::transaction(function () use ($id) {
            $project = Project::whereKey($id)
                ->where('idCostumer', Auth::user()->idCostumer)
                ->lockForUpdate()
                ->firstOrFail();

            $testCycleIds = TestCycle::where('idCostumer', Auth::user()->idCostumer)
                ->where('idProject', $id)
                ->pluck('id');
            $performedCycleIds = PerformedTestCycle::whereIn('testCycleId', $testCycleIds)
                ->where('idCostumer', Auth::user()->idCostumer)
                ->pluck('id');

            PerformedStep::whereIn('testCycleDoneId', $performedCycleIds)->delete();
            PerformedTest::whereIn('testCycleDoneId', $performedCycleIds)->delete();
            PerformedTestCycle::whereIn('id', $performedCycleIds)->delete();
            TestCycle::whereIn('id', $testCycleIds)->delete();
            Environment::where('idProject', $id)->delete();
            Plugin::where('idProject', $id)->delete();
            Step::where('idProject', $id)->delete();
            Test::where('idProject', $id)->delete();
            $project->delete();
        });

        return $this->index($request);
    }
}
