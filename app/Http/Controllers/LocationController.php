<?php

namespace App\Http\Controllers;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LocationController extends Controller
{
    public function index(Request $request)
    {
        if (Auth::user()->role != 1)
        {
            return response()->json('ok');
        }
        return Location::get();
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'name' => 'required',
        ]);
        if (Auth::user()->role != 1)
        {
            return response()->json('ok');
        }
        $location = new Location;
        $location->name = $request->input('name');
        $location->save();
        return $this->index($request);
    }

    public function update(Request $request)
    {

        $this->validate($request, [
            'name' => 'required',
            'id' => 'required',
        ]);
        if (Auth::user()->role != 1) {
            return response()->json('ok');}
        $location = Location::findorFail($request->input('id'));

        $location->name = $request->input('name');
        $location->save();
        return $this->index($request);
    }
}
