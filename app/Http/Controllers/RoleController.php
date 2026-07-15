<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        if (Auth::user()->role > 2) {
            return response()->json('ok');
        }
        $roles = Role::orderBy('id', 'asc')->get();
        if (Auth::user()->role == 2) {
            $roles->shift();
        }

        return $roles;
    }
}
