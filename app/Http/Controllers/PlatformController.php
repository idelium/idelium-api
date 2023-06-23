<?php

namespace App\Http\Controllers;

use App\Models\Platform;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PlatformController extends Controller
{
    public function index(Request $request, $type)
    {

        if (Auth::user()->role != 1) {
            return response()->json('ok');
        }
        return Platform::where('type', '=', $type)
            ->orderBy('osDescription', 'asc')
            ->get();
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'addressname' => 'required',
            'location' => 'required',
            'os' => 'required',
            'osversion' => 'required',
            'brand' => 'required',
            'brandDescription' => 'required',
            'osDescription' => 'required',
            'browserDescription' => 'required',
            'status' => 'required',
        ]);

        if (Auth::user()->role != 1) {
            return response()->json('ok');
        }
        $platform = new Platform;
        $platform->type = $request->input('type');
        $platform->hostname = 'https://' . $request->input('addressname') . ':' . env('IDELIUM_CL_PORT');
        $platform->location = $request->input('location');
        $platform->os = $request->input('os');
        $platform->osversion = $request->input('osversion');
        $platform->brand = $request->input('brand');
        $platform->browser = $request->input('browser');
        $platform->brandDescription = $request->input('brandDescription');
        $platform->osDescription = $request->input('osDescription');
        $platform->browserDescription = $request->input('browserDescription');
        $platform->status = 1;
        $platform->save();
        return $this->index($request, $request->input('type'));
    }

    public function update(Request $request)
    {
        $this->validate($request, [
            'id' => 'required',
            'status' => 'required',
            'type' => 'required',
        ]);

        if (Auth::user()->role != 1) {
            return response()->json('ok');
        }
        $platform = Platform::findorFail($request->input('id'));

        $platform->status = $request->input('status');
        $platform->save();
        return $this->index($request, $request->input('type'));
    }

    public function delete(Request $request, $type, $id)
    {
        if (Auth::user()->role != 1) {
            return response()->json('ok');
        }
        $platform = Platform::findorFail($id);
        if ($platform->delete()) {
            return $this->index($request, $type);
        }
    }
}
