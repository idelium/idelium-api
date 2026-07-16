<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTestCycleRequest;
use App\Http\Requests\UpdateTestCycleRequest;
use App\Models\Project;
use App\Models\TestCycle;
use Illuminate\Http\Request;

class TestCycleController extends Controller
{
    public function index(Request $request, $idProject)
    {
        $this->ownedProject($request, $idProject);

        return TestCycle::select('id', 'name', 'description')
            ->where('idCostumer', $request->user()->idCostumer)
            ->where('idProject', $idProject)->get();
    }

    public function store(StoreTestCycleRequest $request)
    {
        $this->ownedProject($request, $request->integer('idProject'));

        $testcycle = new TestCycle;
        $testcycle->name = $request->input('name');
        $testcycle->description = $request->input('description');
        $testcycle->config = $request->input('config');
        $testcycle->idProject = $request->integer('idProject');
        $testcycle->idCostumer = $request->user()->idCostumer;
        $testcycle->save();

        return $this->index($request, $request->integer('idProject'));
    }

    public function show(Request $request, $idProject, $id)
    {
        return $this->ownedTestCycle($request, $idProject, $id)
            ->only(['id', 'name', 'description', 'config', 'idProject']);
    }

    public function update(UpdateTestCycleRequest $request, $idProject, $id)
    {
        $testcycle = $this->ownedTestCycle($request, $idProject, $id);
        $testcycle->config = $request->input('config');
        $testcycle->description = $request->input('description');
        $testcycle->save();

        return $this->index($request, $idProject);
    }

    private function ownedProject(Request $request, int $idProject): Project
    {
        return Project::whereKey($idProject)
            ->where('idCostumer', $request->user()->idCostumer)
            ->firstOrFail();
    }

    private function ownedTestCycle(
        Request $request,
        int $idProject,
        int $idTestCycle
    ): TestCycle {
        $this->ownedProject($request, $idProject);

        return TestCycle::whereKey($idTestCycle)
            ->where('idProject', $idProject)
            ->where('idCostumer', $request->user()->idCostumer)
            ->firstOrFail();
    }
}
