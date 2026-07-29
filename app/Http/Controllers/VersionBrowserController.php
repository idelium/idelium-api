<?php

namespace App\Http\Controllers;

use App\Models\VersionBrowser;
use App\Support\EnterpriseGridResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VersionBrowserController extends Controller
{
    public function index(Request $request, $idBrowser)
    {

        if (Auth::user()->role != 1) {
            return response()->json('ok');
        }

        return app(EnterpriseGridResponse::class)->build(
            $request,
            VersionBrowser::where('idBrowser', '=', $idBrowser),
            ['id', 'version', 'created_at', 'updated_at'],
            'version',
            'asc',
            ['version'],
            ['id'],
        );
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'version' => 'required',
            'idBrowser' => 'required',
        ]);

        if (Auth::user()->role != 1) {
            return response()->json('ok');
        }
        $browserVersion = new VersionBrowser;
        $browserVersion->version = $request->input('version');
        $browserVersion->idBrowser = $request->input('idBrowser');
        $browserVersion->save();

        return $this->index($request, $request->input('idBrowser'));
    }

    public function update(Request $request)
    {
        $this->validate($request, [
            'version' => 'required',
            'idBrowser' => 'required',
            'id' => 'required',
        ]);

        if (Auth::user()->role != 1) {
            return response()->json('ok');
        }
        $browserVersion = VersionBrowser::findorFail($request->input('id'));

        $browserVersion->version = $request->input('version');
        $browserVersion->save();

        return $this->index($request, $request->input('idBrowser'));
    }
}
