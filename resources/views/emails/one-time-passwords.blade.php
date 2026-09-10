@component('mail::message')
@if($locale == 'ar')
<div style="direction: rtl; text-align: right; font-family: Arial, sans-serif;">

# مرحباً بك في {{ $appName }} 👋

لقد استلمنا طلباً للوصول إلى حسابك باستخدام رمز التحقق (OTP). إذا لم تكن أنت من قام بهذا الطلب، يرجى تجاهل هذا البريد.

@component('mail::panel')
<div style="text-align: center;">
<p style="margin: 0; font-size: 16px; color: #4a5568;">رمز التحقق الخاص بك هو</p>
<span style="font-size: 32px; font-weight: bold; letter-spacing: 6px; color: #1a202c; display: inline-block; margin: 10px 0;">{{ $oneTimePassword->password }}</span>
<p style="margin: 0; font-size: 13px; color: #e53e3e; font-weight: bold;">هذا الرمز صالح لمدة 10 دقائق فقط</p>
</div>
@endcomponent

### لماذا استلمت هذا البريد؟
تُستخدم هذه الرموز لضمان أمان حسابك ومنع الوصول غير المصرح به.

⚠️ **تنبيه أمني:** موظفو **{{ $appName }}** لن يطلبوا منك هذا الرمز أبداً عبر الهاتف أو الرسائل. لا تقم بمشاركته مع أي شخص.

شكرًا لثقتك بنا،<br>
فريق عمل {{ $appName }}
</div>
@else
<div style="direction: ltr; text-align: left; font-family: Arial, sans-serif;">

# Welcome to {{ $appName }} 👋

We received a request to access your account using a One-Time Password (OTP). If you didn't make this request, please ignore this email.

@component('mail::panel')
<div style="text-align: center;">
<p style="margin: 0; font-size: 16px; color: #4a5568;">Your verification code is</p>
<span style="font-size: 32px; font-weight: bold; letter-spacing: 6px; color: #1a202c; display: inline-block; margin: 10px 0;">{{ $oneTimePassword->password }}</span>
<p style="margin: 0; font-size: 13px; color: #e53e3e; font-weight: bold;">This code is valid for 10 minutes only</p>
</div>
@endcomponent

### Why did you receive this?
This code is used to ensure your account security and prevent unauthorized access.

⚠️ **Security Notice:** **{{ $appName }}** staff will never ask for this code via phone or text. Do not share it with anyone.

Best regards,<br>
The {{ $appName }} Team
</div>
@endif
@endcomponent