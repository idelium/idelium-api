<?php

namespace App\Http\Controllers;

use App\Models\Os;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OsController extends Controller
{

    public function index(Request $request, $idType)
    {
        return Os::where('type', '=', $idType)->get();
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'name' => 'required',
            'type' => 'required',
        ]);

        if (Auth::user()->role != 1) {
            return response()->json('ok');
        }
        $os = new Os;
        $os->name = $request->input('name');
        $os->type = $request->input('type');
        $os->save();
        return $this->index($request, $request->input('type'));
    }

    public function update(Request $request)
    {
        $this->validate($request, [
            'name' => 'required',
            'id' => 'required',
        ]);

        if (Auth::user()->role != 1) {
            return response()->json('ok');
        }
        $os = Os::findorFail($request->input('id'));
        $os->name = $request->input('name');
        $os->save();
        return $this->index($request, $request->input('type'));
    }
}
