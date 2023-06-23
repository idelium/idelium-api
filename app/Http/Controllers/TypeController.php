<?php

namespace App\Http\Controllers;

use App\Models\Type;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;


class TypeController extends Controller
{
    public function index(Request $request)
    {
        return Type::select('id', 'name')->get();
    }
}
