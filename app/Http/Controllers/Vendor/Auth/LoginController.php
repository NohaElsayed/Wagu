<?php

namespace App\Http\Controllers\Vendor\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Vendor;
use Gregwar\Captcha\CaptchaBuilder;
use Illuminate\Support\Facades\Session;
use App\CentralLogics\Helpers;
use Brian2694\Toastr\Facades\Toastr;


class LoginController extends Controller
{
    public function __construct()
    {
        $this->middleware('guest:vendor', ['except' => 'logout']);
    }

    public function login()
    {
        // $custome_recaptcha = new CaptchaBuilder;
        // $custome_recaptcha->build();
        // Session::put('six_captcha', $custome_recaptcha->getPhrase());
        return view('vendor-views.auth.login');
    }

    public function submit(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|min:6'
        ]);

        $recaptcha = Helpers::get_business_settings('recaptcha');
        if (isset($recaptcha) && $recaptcha['status'] == 1) {
            $request->validate([
                'g-recaptcha-response' => [
                    function ($attribute, $value, $fail) {
                        $secret_key = Helpers::get_business_settings('recaptcha')['secret_key'];
                        $response = $value;
                        $url = 'https://www.google.com/recaptcha/api/siteverify?secret=' . $secret_key . '&response=' . $response;
                        $response = \file_get_contents($url);
                        $response = json_decode($response);
                        if (!$response->success) {
                            $fail(trans('messages.ReCAPTCHA Failed'));
                        }
                    },
                ],
            ]);
        } else if(session('six_captcha') != $request->custome_recaptcha)
        {
            Toastr::error(trans('messages.ReCAPTCHA Failed'));
            return back();
        }

        $vendor = Vendor::where('email', $request->email)->first();
        if($vendor)
        {
            if($vendor->restaurants[0]->status == 0)
            {
                return redirect()->back()->withInput($request->only('email', 'remember'))
            ->withErrors([trans('messages.inactive_vendor_warning')]);
            }
        }
        if (auth('vendor')->attempt(['email' => $request->email, 'password' => $request->password], $request->remember)) {
            return redirect()->route('vendor.dashboard');
        }

        return redirect()->back()->withInput($request->only('email', 'remember'))
            ->withErrors(['Credentials does not match.']);
    }

    public function logout(Request $request)
    {
        auth()->guard('vendor')->logout();
        return redirect()->route('vendor.auth.login');
    }
}
