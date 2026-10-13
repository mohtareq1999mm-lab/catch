@component('mail::message')
# {{ __('Password Reset Token') }}

{{ __('Please copy the below token to reset your password.') }}

```{{$token}}```

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
@endcomponent