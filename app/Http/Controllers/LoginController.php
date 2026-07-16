<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Library\GoogleVerify;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Validator;

class LoginController extends BaseController
{
    public function __construct(private GoogleVerify $googleVerify) {}

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'email' => 'required|email',
            'password' => 'required',
            'c_password' => 'required|same:password',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $input = $request->all();
        $input['password'] = bcrypt($input['password']);
        $user = User::create($input);
        $success['token'] = $user->createToken('MyApp')->accessToken;
        $success['name'] = $user->name;

        return $this->sendResponse($success, 'User register successfully.');
    }

    public function logout(Request $request)
    {
        $accessToken = $request->user()->currentAccessToken();
        if ($accessToken instanceof PersonalAccessToken) {
            $accessToken->delete();
        }

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        Auth::forgetGuards();

        return response()->noContent();
    }

    public function login(LoginRequest $request)
    {
        $recaptchaSecret = config('services.recaptcha.secret');
        if ($recaptchaSecret !== null) {
            if (! $this->googleVerify->passes($request->input('token'), $recaptchaSecret)) {
                return response()->json([
                    'message' => 'Invalid login details',
                ], 401);
            }
        }

        $user = User::where('email', $request->input('email'))->first();
        if ($user === null || ! Hash::check($request->input('password'), $user->password)) {
            return response()->json([
                'message' => 'Invalid login details',
            ], 401);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'authenticated' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }
}
