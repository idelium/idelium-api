<?php

namespace App\Http\Controllers;

use App\Models\Os;
use App\Support\EnterpriseGridResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OsController extends Controller
{
    public function index(Request $request, $idType)
    {
        return app(EnterpriseGridResponse::class)->build(
            $request,
            Os::where('type', '=', $idType),
            ['id', 'name', 'created_at', 'updated_at'],
            'id',
            'asc',
            ['name'],
            ['id'],
        );
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
