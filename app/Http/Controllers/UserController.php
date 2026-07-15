<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $authenticatedUser = Auth::user();
        if ($authenticatedUser->role > 2) {
            return response()->json('ok');
        }

        if ($authenticatedUser->role == 1) {
            return $this->accountQuery()
                ->orderBy('users.email', 'asc')
                ->get();
        }

        return $this->accountQuery()
            ->where('users.role', '>', 1)
            ->where('users.idCostumer', $authenticatedUser->idCostumer)
            ->orderBy('users.email', 'asc')
            ->get();
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
        return DB::table('users')
            ->join('costumers', 'users.idCostumer', '=', 'costumers.id')
            ->join('roles', 'users.role', '=', 'roles.id')
            ->select([
                'users.email',
                'users.name',
                'costumers.costumer as companyName',
                'roles.name as roleName',
            ])
            ->where('users.id', Auth::user()->id)
            ->first();
    }
    public function updatePasswordUser(Request $request)
    {
        $this->validate($request, [
            'password' => 'required',
        ]);

        $user = User::findorFail(Auth::user()->id);
        $user->password = bcrypt($request->input('password'));
        $user->save();
        return $this->getuser($request);
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
    public function destroy(Request $request, $id)
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

    private function accountQuery(): Builder
    {
        return DB::table('users')
            ->join('costumers', 'users.idCostumer', '=', 'costumers.id')
            ->join('roles', 'users.role', '=', 'roles.id')
            ->select([
                'users.id',
                'users.email',
                'users.name',
                'users.role',
                'users.idCostumer',
                'costumers.costumer',
                'roles.name as roleName',
            ]);
    }
}
