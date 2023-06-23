<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {

        if (Auth::user()->role > 2) return response()->json('ok');
        if (Auth::user()->role==1) {
            return DB::table('users')
                ->join('costumers', 'users.idCostumer', '=', 'costumers.id')
                ->join('roles', 'users.role', '=', 'roles.id')
                ->select('users.*', 'costumers.costumer', 'roles.name as roleName')
                ->get();
        }
        return DB::table('users')
                ->join('costumers', 'users.idCostumer', '=', 'costumers.id')
                ->join('roles', 'users.role', '=', 'roles.id')
                ->select('users.*', 'costumers.costumer', 'roles.name as roleName')
                ->where('role', '>', 1)
                ->where('idCostumer', Auth::user()->idCostumer)
                ->orderBy('email', 'asc')->get();
    }

    public function store(Request $request)
    {
        if (Auth::user()->role > 2) {
            return response()->json('ok');
        }
        $this->validate($request, [
            'name' => 'required',
            'password' => 'required|string|min:8',
            'email' => 'required',
            'role' => 'required',
        ]);
        $user = new User;
        if (Auth::user()->role != 1 && $user->role == 1) {
            return response()->json('ok');
        }
        $user->name = $request->input('name');
        $user->password = bcrypt($request->input('password'));
        $user->email = $request->input('email');
        $user->role = $request->input('role');
        if (Auth::user()->role != 1) {
            $user->idCostumer = Auth::user()->idCostumer;
        } else {
            $user->idCostumer = $request->input('idCostumer');
        }
        $user->save();
      return $this->index($request);
    }

    public function getuser(Request $request)
    {

        $users=DB::table('users')
            ->join('costumers', 'users.idCostumer', '=', 'costumers.id')
            ->join('roles', 'users.role', '=', 'roles.id')
            ->select('users.email', 'users.name', 'costumers.costumer as companyName', 'roles.name as roleName')
            ->where('users.id', Auth::user()->id)
            ->orderBy('email', 'asc')->get();
        return $users[0];
    }
    public function updatePasswordUser(Request $request)
    {
        $this->validate($request, [
            'password' => 'required',
        ]);

        $user = User::findorFail(Auth::user()->id);
        $user->password = bcrypt($request->input('password'));
        $user->save();
        return $this->index($request);
    }

    public function update(Request $request, $id)
    {
        if (Auth::user()->role > 2) {
            return response()->json('ok');
        }
        $this->validate($request, [
          'name' => 'required',
          'password' => 'required',
        ]);
        $user = User::findorFail($id);
        if (Auth::user()->role != 1 && ($user->role == 1 ||
            ($user->idCostumer != Auth::user()->idCostumer))) {
                return response()->json('ok');
        }
        $user->name = $request->input('name');
        $user->password = bcrypt($request->input('password'));
        $user->save();
        return $this->index($request);
    }
    public function destroy(Request $request,$id)
    {
        if (Auth::user()->role > 2) {
            return response()->json('ok');
        }
        $user = User::findorFail($id);
        if (Auth::user()->role != 1 && ($user->role==1 ||
                                    ($user->idCostumer != Auth::user()->idCostumer))) {
                                        return response()->json('ok');
                                    }
        if ($user->delete()) {
            return $this->index($request);
        }
    }
}
