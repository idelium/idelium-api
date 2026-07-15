<?php

namespace App\Http\Controllers;

use App\Models\Costumer;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HeaderController extends Controller
{
    public function index(Request $request)
    {
        $header = [];
        $role = Auth::user()->role;
        $header['projects'] = Project::orderBy('created_at', 'asc')
            ->where('idCostumer', Auth::user()->idCostumer)
            ->get();
        if ($role == 1) {
            $header['costumers'] = Costumer::orderBy('created_at', 'asc')->get();
        }

        return response()->json($header);
    }

    public function changeCostumer(Request $request, $id)
    {
        if (Auth::user()->role == 1) {
            $user = Auth::user();
            $user->id = $id;
            Auth::login($user);

            return response()->json([
                'session' => 'tbd',
            ]);
        } else {
            return response()->json('ok');
        }
    }
}
