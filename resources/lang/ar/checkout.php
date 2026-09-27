<?php

return [
    'cod_success' => 'تم تقديم طلبك. ستدفع عند الاستلام.',
    'cart_not_found' => 'السلة غير موجودة',
    'cart_empty' => 'السلة فارغة',
    'pay_at_cashier_requires_pickup' => 'عند اختيار الدفع عند الكاشير، يجب عليك اختيار نوع التوصيل استلام من الفرع.',

    // Fast Shipping
    'fast_shipping_unavailable' => 'الشحن السريع غير متاح في هذا الوقت.',
    'fast_shipping_hours_only' => 'الشحن السريع متاح فقط بين :start و :end.',
    'fast_shipping_governorate_unavailable' => 'الشحن السريع غير متاح في محافظتك.',
    'fast_shipping_items_ineligible' => 'واحد أو أكثر من العناصر في سلتك غير مؤهلة للشحن السريع.',
    'invalid_order_status_transition' => 'لا يمكن تغيير حالة الطلب من :from إلى :to.',
    'invalid_flow_transition' => 'لا يمكن تغيير حالة الطلب من :from إلى :to في مسار الشحن.',
    'shipping_type_unsupported' => 'نوع الشحن المحدد غير مدعوم.',
    'shipping_type_unavailable' => 'نوع الشحن المحدد غير متاح لهذا الطلب.',
    'flow_not_configured' => 'لا يوجد مسار طلب نشط معد لنوع الشحن هذا.',
    'flow_statuses_required' => 'يجب أن يحتوي المسار على حالة واحدة على الأقل.',
    'flow_statuses_duplicate' => 'يجب ألا يحتوي المسار على حالات مكررة.',
    'flow_statuses_unknown' => 'واحدة أو أكثر من الحالات غير موجودة.',
    'flow_status_inactive' => 'الحالة :code غير نشطة ولا يمكن إضافتها إلى المسار.',
    'no_pending_cod_transaction' => 'لم يتم العثور على معاملة دفع عند الاستلام معلقة.',
    'no_pending_cashier_transaction' => 'لم يتم العثور على معاملة دفع عند الكاشير معلقة.',
];
