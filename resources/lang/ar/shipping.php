<?php

return [
    'audit' => [
        'override_reason_required' => 'يتطلب تغيير مزود الشحن سبباً لا يتجاوز 500 حرف.',
        'provider_overridden' => 'تم تغيير مزود الشحن بواسطة المدير',
    ],
    'webhook_accepted' => 'تم استقبال إشعار الشحن بنجاح.',
    'webhook_duplicate' => 'تم تسجيل إشعار الشحن مسبقاً.',
    'webhook_invalid_signature' => 'توقيع إشعار الشحن غير صالح.',
    'webhook_invalid_payload' => 'بيانات إشعار الشحن غير صالحة.',
    'webhook_rate_limited' => 'تم تجاوز عدد إشعارات الشحن المسموح بها. يُرجى المحاولة لاحقاً.',
    'webhook_unavailable' => 'التحقق من إشعار الشحن غير متاح حالياً.',
];
