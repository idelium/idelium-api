<?php

namespace App\Http\Controllers;

use App\Library\TestLauncher;
use App\Library\TestLauncherException;
use App\Models\Browser;
use App\Models\Costumer;
use App\Models\Platform;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

            try {
                return $launcher->launch(
                    $platform->hostname,
                    $browser->name,
                    $request->input('idTestCycle'),
                    $request->input('idProject'),
                    $request->input('environment'),
                    $apiKey->apiKey
                );
            } catch (TestLauncherException $exception) {
                return response()->json([
                    'error' => [
                        'code' => $exception->errorCode,
                        'message' => $exception->getMessage(),
                    ],
                ], $exception->httpStatus);
            }
        }

        return response()->json('ko');
    }
}
