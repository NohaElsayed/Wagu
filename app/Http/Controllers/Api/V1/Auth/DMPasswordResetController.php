<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\DeliveryMan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\CentralLogics\SMS_module;
use App\Mail\otpMail;
use Illuminate\Support\Facades\Mail;

class DMPasswordResetController extends Controller
{
    public function reset_password_request(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $deliveryman = DeliveryMan::Where(['email' => $request['email']])->first();

        if (isset($deliveryman)) {
            if(env('APP_MODE') =='demo')
            {
                return response()->json(['message' => trans('messages.otp_sent_successfull')], 200);
            }
            $token = rand(1000,9999);
            DB::table('password_resets')->updateOrInsert([
                'email' => $deliveryman['email'],
                'token' => $token,
                'created_at' => now(),
            ]);
            Mail::to($deliveryman['email'])->send(new otpMail($token));
            return response()->json(['message' => trans('messages.otp_sent_successfull')], 200);
            // $response = SMS_module::send($request['phone'],$token);
            // if($response == 'success')
            // {
            // }
            // else
            // {
            //     $errors = [];
            //     array_push($errors, ['code' => 'otp', 'message' => trans('messages.failed_to_send_sms')]);
            //     return response()->json([
            //         'errors' => $errors
            //     ], 405);
            // }
        }
        $errors = [];
        array_push($errors, ['code' => 'not-found', 'message' => 'email not found!']);
        return response()->json(['errors' => $errors], 404);
    }

    public function verify_token(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required',
            'reset_token'=> 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $user=DeliveryMan::where('email', $request->email)->first();
        if (!isset($user)) {
            $errors = [];
            array_push($errors, ['code' => 'not-found', 'message' => 'email not found!']);
            return response()->json(['errors' => $errors
            ], 404);
        }
        if(env('APP_MODE')=='demo')
        {
            if($request['reset_token'] == '1234')
            {
                return response()->json(['message'=>"Token found, you can proceed"], 200);
            }
            $errors = [];
            array_push($errors, ['code' => 'reset_token', 'message' => 'Invalid token.']);
            return response()->json(['errors' => $errors
                ], 400);
        }
        // 'token' => $request['reset_token'],
        $data = DB::table('password_resets')->where(['email'=>$user->email])->orderByDesc('created_at')->first();
        if (isset($data) && $data->token == $request['reset_token']) {
            return response()->json(['message'=>"Token found, you can proceed"], 200);
        }
        $errors = [];
        array_push($errors, ['code' => 'reset_token', 'message' => 'Invalid token.']);
        return response()->json(['errors' => $errors
            ], 400);
    }

    public function reset_password_submit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required',
            'reset_token'=> 'required',
            'password'=> 'required|min:6',
            'confirm_password'=> 'required|same:password',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        if(env('APP_MODE')=='demo')
        {
            if($request['reset_token']=="1234")
            {
                DB::table('delivery_men')->where(['email' => $request['email']])->update([
                    'password' => bcrypt($request['confirm_password'])
                ]);
                return response()->json(['message' => 'Password changed successfully.'], 200);
            }
            $errors = [];
            array_push($errors, ['code' => 'invalid', 'message' => 'Invalid token.']);
            return response()->json(['errors' => $errors], 400);
        }
        $data = DB::table('password_resets')->where(['token' => $request['reset_token'],'email' => $request['email']])->first();
        if (isset($data)) {
            if ($request['password'] == $request['confirm_password']) {
                DB::table('delivery_men')->where(['email' => $data->email])->update([
                    'password' => bcrypt($request['confirm_password'])
                ]);
                DB::table('password_resets')->where(['token' => $request['reset_token'], 'email' => $request['email']])->delete();
                return response()->json(['message' => 'Password changed successfully.'], 200);
            }
            $errors = [];
            array_push($errors, ['code' => 'mismatch', 'message' => 'Password did not match!']);
            return response()->json(['errors' => $errors], 401);
        }

        $errors = [];
        array_push($errors, ['code' => 'invalid', 'message' => 'Invalid token.']);
        return response()->json(['errors' => $errors], 400);
    }
}
