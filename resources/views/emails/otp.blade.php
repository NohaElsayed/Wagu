@component('mail::message')
# OTP verification

Your OTP number is : {{$otp}}

Thanks,<br>
{{ config('app.name') }}
@endcomponent
