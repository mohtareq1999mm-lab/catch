@component('mail::message')
# {{ __('Product Update Notice') }}

{{ __('Hello :name,', ['name' => $user->name]) }}

{{ __('One new update is available of this product ":product". You can download the update from your user profile\'s download section.', ['product' => $product->name]) }}

{{ __('Admin message (optional) :') }}

{{ $optional_message['update_message'] }}

{{ __('For more details, you can visit the product link.') }}

@component('mail::button', ['url' => $url ])
{{ __('View product') }}
@endcomponent

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
@endcomponent