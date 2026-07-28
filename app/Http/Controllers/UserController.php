<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CapabilityService;
use App\Services\PasswordPolicy;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct(private readonly CapabilityService $capabilities) {}

    public function index(Request $request)
    {
        $authenticatedUser = Auth::user();
        $this->capabilities->require($authenticatedUser, 'accounts.manage');

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
        $authenticatedUser = Auth::user();
        $this->capabilities->require($authenticatedUser, 'accounts.manage');

        $validated = $this->validate($request, [
            'name' => 'required',
            'password' => 'required|string|min:8',
            'email' => 'required|email|unique:users,email',
            'role' => 'required|integer|exists:roles,id',
            'idCostumer' => [
                Rule::requiredIf((int) $authenticatedUser->role === 1),
                'nullable',
                'integer',
                'exists:costumers,id',
            ],
        ]);
        $this->validatePasswordPolicy($validated['password']);

        $user = new User;
        $user->name = $validated['name'];
        $user->password = bcrypt($validated['password']);
        $user->email = $validated['email'];
        $user->role = $validated['role'];
        $user->idCostumer = $validated['idCostumer'] ?? $authenticatedUser->idCostumer;

        Gate::authorize('createAccount', $user);
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
        $this->capabilities->require($request->user(), 'profile.manage');

        $validated = $this->validate($request, [
            'currentPassword' => 'required_without:current_password|string',
            'current_password' => 'required_without:currentPassword|string',
            'password' => 'required',
        ]);

        $user = User::findorFail(Auth::user()->id);
        $currentPassword = $validated['currentPassword']
            ?? $validated['current_password']
            ?? '';

        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'currentPassword' => ['The current password is not valid.'],
            ]);
        }

        $this->validatePasswordPolicy($validated['password']);
        $user->password = bcrypt($validated['password']);
        $user->save();

        return $this->getuser($request);
    }

    public function update(Request $request, $id)
    {
        $authenticatedUser = Auth::user();
        $this->capabilities->require($authenticatedUser, 'accounts.manage');

        $validated = $this->validate($request, [
            'name' => 'required',
            'password' => 'required|string|min:8',
        ]);
        $this->validatePasswordPolicy($validated['password']);

        $user = $this->accountForMutation($authenticatedUser, $id);
        Gate::authorize('update', $user);
        $user->name = $validated['name'];
        $user->password = bcrypt($validated['password']);
        $user->save();

        return $this->index($request);
    }

    public function destroy(Request $request, $id)
    {
        $authenticatedUser = Auth::user();
        $this->capabilities->require($authenticatedUser, 'accounts.manage');

        $user = $this->accountForMutation($authenticatedUser, $id);
        Gate::authorize('delete', $user);
        $user->delete();

        return $this->index($request);
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

    private function accountForMutation(User $authenticatedUser, $id): User
    {
        $query = User::whereKey($id);

        if ((int) $authenticatedUser->role !== 1) {
            $query->where('idCostumer', $authenticatedUser->idCostumer)
                ->where('role', '>', 1);
        }

        return $query->firstOrFail();
    }

    private function validatePasswordPolicy(string $password): void
    {
        $violations = app(PasswordPolicy::class)->violations($password);
        if ($violations !== []) {
            throw ValidationException::withMessages([
                'password' => $violations,
            ]);
        }
    }
}
