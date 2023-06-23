<?php

namespace App\Http\Controllers;

use App\Library\TestLauncher;
use Illuminate\Support\Facades\Auth;
use App\Models\Costumer;
use App\Models\Platform;
use App\Models\Browser;

use Illuminate\Http\Request;

class TestLauncherController extends Controller
{
    public function launchTest(Request $request)
    {
        $this->validate($request, [
            'idTestCycle' => 'required',
            'idProject' => 'required',
            'environment' => 'required',
            'idPlatform' => 'required',
        ]);
        $costumers = Costumer::select('apiKey')
            ->where('id', Auth::user()->idCostumer)
            ->get();
        $platform = Platform::findorFail($request->input('idPlatform'));
        $browser = Browser::findorFail($platform->browser);
        if (count($costumers) == 1) {
            $apiKey = $costumers[0];
            $launcher = new TestLauncher;
            return $launcher->launch(
                $platform->hostname,
                $browser->name,
                $request->input('idTestCycle'),
                $request->input('idProject'),
                $request->input('environment'),
                $apiKey->apiKey
            );
        }
        return response()->json('ko');
    }
}
