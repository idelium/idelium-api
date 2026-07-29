<?php

namespace App\Http\Controllers;

use App\Models\BrandDevice;
use App\Support\EnterpriseGridResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BrandDeviceController extends Controller
{
    public function index(Request $request)
    {
        return app(EnterpriseGridResponse::class)->build(
            $request,
            BrandDevice::query(),
            ['id', 'brand', 'created_at', 'updated_at'],
            'id',
            'asc',
            ['brand'],
            ['id'],
        );
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'brand' => 'required',
        ]);
        if (Auth::user()->role != 1) {
            return response()->json('ok');
        }
        $brandDevice = new BrandDevice;
        $brandDevice->brand = $request->input('brand');
        $brandDevice->save();

        return $this->index($request);
    }

    public function update(Request $request)
    {

        $this->validate($request, [
            'brand' => 'required',
            'id' => 'required',
        ]);
        if (Auth::user()->role != 1) {
            return response()->json('ok');
        }
        $brandDevice = BrandDevice::findorFail($request->input('id'));
        $brandDevice->brand = $request->input('brand');
        $brandDevice->save();

        return $this->index($request);
    }
}
