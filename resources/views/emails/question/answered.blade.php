{{--$question collection is available here--}}

@component('mail::message')
@if(($locale ?? 'en') === 'ar')
<div style="direction: rtl; text-align: right;">

# س: {{$question->question}}؟

ج: {{$question->answer}}

@component('mail::button', ['url' => $url ])
    عرض {{$product->name}}
@endcomponent
شكرًا،<br>
{{ config('app.name') }}
</div>
@else
# Q: {{$question->question}}?

A: {{$question->answer}}

@component('mail::button', ['url' => $url ])
    View {{$product->name}}
@endcomponent
Thanks,<br>
{{ config('app.name') }}
@endif
@endcomponent
