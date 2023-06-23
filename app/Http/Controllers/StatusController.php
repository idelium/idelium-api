<?php

namespace App\Http\Controllers;

use App\Models\Status;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StatusController extends Controller
{
    public function index(Request $request)
    {
        if (Auth::user()->role != 1)
        {
            return response()->json('ok');
        }
        return Status::select('id', 'name')->get();
    }

}
