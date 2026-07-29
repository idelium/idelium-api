<?php

namespace App\Http\Controllers;

use App\Models\Browser;
use App\Support\EnterpriseGridResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BrowserController extends Controller
{
    public function index(Request $request, $idOs)
    {
        return app(EnterpriseGridResponse::class)->build(
            $request,
            Browser::where('idOs', '=', $idOs),
            ['id', 'name', 'created_at', 'updated_at'],
            'name',
            'asc',
            ['name'],
            ['id'],
        );
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'name' => 'required',
            'idOs' => 'required',
        ]);
        if (Auth::user()->role != 1) {
            return response()->json('ok');
        }
        $osVersion = new Browser;
        $osVersion->name = $request->input('name');
        $osVersion->idOs = $request->input('idOs');
        $osVersion->save();

        return $this->index($request, $request->input('idOs'));
    }

    public function update(Request $request)
    {

        $this->validate($request, [
            'name' => 'required',
            'idOs' => 'required',
            'id' => 'required',
        ]);
        if (Auth::user()->role != 1) {
            return response()->json('ok');
        }
        $os = Browser::findorFail($request->input('id'));

        $os->name = $request->input('name');
        $os->save();

        return $this->index($request, $request->input('idOs'));
    }
}
