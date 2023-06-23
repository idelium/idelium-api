<?php

namespace App\Http\Controllers;

use App\Models\Environment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnvironmentController extends Controller
{
    const INVALID_DETAILS = 'Invalid details';

    public function index(Request $request,$idProject)
    {
        return Environment::select('id', 'code', 'description')
        ->where('idProject', $idProject)
        ->where('idCostumer', Auth::user()->idCostumer)
        ->get();
    }
    public function store(Request $request)
    {
      $this->validate($request, [
          'code' => 'required',
          'description' => 'required',
          'config' => 'required',
          'idProject' => 'required',
      ]);
      $environment = new Environment;
      $environment->code = $request->input('code');
      $environment->description = $request->input('description');
      $environment->config = $request->input('config');
      $environment->idProject = $request->input('idProject');
      $environment->idCostumer=Auth::user()->idCostumer;
      $environment->save();
      return $this->index($request,$request->input('idProject'));
    }

    public function show(Request $request,$idProject,$id)
    {
        $row=Environment::where('id', $id)
                ->where('idProject', $idProject)
                ->where('idCostumer', Auth::user()->idCostumer)
                ->get();
        if (count($row) == 1)
        {
            return  $row[0];
        }
        return response()->json(['message' => self::INVALID_DETAILS], 555);
    }

    public function update(Request $request, $idProject,$id)
    {

        $this->validate($request, [
          'config' => 'required',
        ]);
        $environment = Environment::findorFail($id);
        if ($environment->idCostumer != Auth::user()->idCostumer)
        {
            return  response()->json(['message' => self::INVALID_DETAILS], 555);
        }
        $environment->config = $request->input('config');
        $environment->save();
        return $this->index($request,$idProject);
    }

    public function destroy(Request $request,$idProject,$id)
    {
        $environment = Environment::findorFail($id);
        if ($environment->idCostumer != Auth::user()->idCostumer)
        {
            return  response()->json(['message' => self::INVALID_DETAILS], 555);
        }
        if ($environment->delete())
        {
            return $this->index($request, $idProject);
        }
    }
}
