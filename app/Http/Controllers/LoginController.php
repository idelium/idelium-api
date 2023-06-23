<?php
   
namespace App\Http\Controllers;
   
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use App\Library\GoogleVerify;
use Validator;
   
class LoginController extends BaseController
{
    
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'email' => 'required|email',
            'password' => 'required',
            'c_password' => 'required|same:password',
        ]);
   
        if ($validator->fails())
        {
            return $this->sendError('Validation Error.', $validator->errors());
        }
   
        $input = $request->all();
        $input['password'] = bcrypt($input['password']);
        $user = User::create($input);
        $success['token'] =  $user->createToken('MyApp')->accessToken;
        $success['name'] =  $user->name;
   
        return $this->sendResponse($success, 'User register successfully.');
    }
    
    public function logout(Request $request)
    {
        Auth::user()->tokens()->delete();
        Auth::guard('web')->logout();
        return $this->sendResponse('ok',200);
    }

    public function login(Request $request)
    {
        $googleverify = new GoogleVerify;
        $jsonGoogle = $googleverify->check($request['token'], env('GOOGLE_KEY_SECRET'));
        if (!$jsonGoogle->success)
        {
            return response()->json([
                'message' => 'Invalid gLogin details'
            ], 401);
        }
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
            'message' => 'Invalid login details'
                    ], 401);
        }
        $user = User::where('email', $request['email'])->firstOrFail();
        $token = $user->createToken('idelium')->plainTextToken;
        /*
            'role'=>$user->role,
            'costumer' => $user->idCostumer,
            'id' => $user->id,
        */
        Auth::login($user);
        return response()->json([
                'access_token' => $token,
                'token_type' => 'Bearer',
                'session' => 'tbd',
        ]);
    }
}