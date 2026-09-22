<?php

return [
    // Order Lifecycle Events
    'order' => [
        'created' => 'تم تقديم الطلب',
        'created_description' => 'تم تقديم طلبك بنجاح وهو قيد المعالجة.',
        'confirmed' => 'تم تأكيد الطلب',
        'confirmed_description' => 'تم تأكيد طلبك وسيتم تحضيره للشحن.',
        'processing' => 'جاري معالجة الطلب',
        'processing_description' => 'طلبك قيد التحضير حالياً.',
        'cancelled' => 'تم إلغاء الطلب',
        'cancelled_description' => 'تم إلغاء طلبك. السبب: :cancellation_reason',
        'completed' => 'تم إكمال الطلب',
        'completed_description' => 'تم إكمال طلبك بنجاح.',
    ],

    // Cancellation Reasons
    'cancellation_reasons' => [
        'payment_failed' => 'فشل الدفع',
        'customer_request' => 'طلب العميل',
        'out_of_stock' => 'نفاد المخزون',
        'fraud_detected' => 'تم اكتشاف احتيال',
        'duplicate_order' => 'طلب مكرر',
        'other' => 'أخرى',
        'unknown' => 'غير معروف',
    ],

    // Payment Events
    'payment' => [
        'pending' => 'الدفع قيد الانتظار',
        'pending_description' => 'في انتظار تأكيد الدفع.',
        'processing' => 'جاري معالجة الدفع',
        'processing_description' => 'يتم معالجة دفعتك.',
        'succeeded' => 'تم الدفع بنجاح',
        'succeeded_description' => 'تم الدفع بمبلغ :amount عبر :method بنجاح.',
        'failed' => 'فشل الدفع',
        'failed_description' => 'فشلت محاولة الدفع. هذه المحاولة رقم :retry_attempt. يرجى المحاولة مرة أخرى.',
        'refunded' => 'تم استرداد المبلغ',
        'refunded_description' => 'تم معالجة استرداد بقيمة :amount إلى محفظتك.',
    ],

    // Fulfillment Events
    'fulfillment' => [
        'pending' => 'في انتظار التنفيذ',
        'pending_description' => 'طلبك في انتظار التنفيذ.',
        'processing' => 'جاري التحضير للشحن',
        'processing_description' => 'يتم تحضير منتجاتك للشحن.',
        'out_for_delivery' => 'قيد التوصيل',
        'out_for_delivery_description' => 'طلبك قيد التوصيل.',
        'delivered' => 'تم التوصيل',
        'delivered_description' => 'تم توصيل طلبك بنجاح.',
        'failed' => 'فشل التوصيل',
        'failed_description' => 'فشلت محاولة التوصيل. سنحاول مجدداً قريباً.',
    ],

    // Shipment Events
    'shipment' => [
        'created' => 'تم إنشاء الشحنة',
        'created_description' => 'تم إنشاء الشحنة برقم تتبع :tracking_number.',
        'picked_up' => 'تم استلام الطرد',
        'picked_up_description' => 'تم استلام طردك من قبل :carrier.',
        'in_transit' => 'قيد النقل',
        'in_transit_description' => 'طردك في الطريق إليك.',
        'out_for_delivery' => 'قيد التوصيل',
        'out_for_delivery_description' => 'طردك قيد التوصيل.',
        'delivered' => 'تم التوصيل',
        'delivered_description' => 'تم توصيل طردك.',
        'failed' => 'فشل التوصيل',
        'failed_description' => 'فشلت محاولة التوصيل.',
        'returned' => 'تم إرجاع الطرد',
        'returned_description' => 'تم إرجاع طردك.',
    ],

    // Inventory Events
    'inventory' => [
        'reserved' => 'تم حجز المخزون',
        'reserved_description' => 'تم حجز المنتجات لطلبك.',
        'committed' => 'تم تخصيص المخزون',
        'committed_description' => 'تم تخصيص المنتجات من المخزون.',
        'restored' => 'تم إعادة المخزون',
        'restored_description' => 'تم إرجاع المنتجات إلى المخزون.',
    ],

    // Shipment Events
    'shipment' => [
        'pending' => 'في انتظار الشحن',
        'pending_description' => 'جاري تجهيز شحنتك.',
        'label_created' => 'تم إنشاء ملصق الشحن',
        'label_created_description' => 'تم إنشاء ملصق الشحن.',
        'picked_up' => 'تم استلام الطرد',
        'picked_up_description' => 'تم استلام طردك من قبل :courier_name.',
        'in_transit' => 'في الطريق',
        'in_transit_description' => 'طردك في الطريق (رقم التتبع: :tracking_number).',
        'out_for_delivery' => 'خارج للتوصيل',
        'out_for_delivery_description' => 'طردك خارج للتوصيل اليوم.',
        'delivered' => 'تم التوصيل',
        'delivered_description' => 'تم توصيل طردك بنجاح.',
        'failed_delivery' => 'فشل التوصيل',
        'failed_delivery_description' => 'فشلت محاولة التوصيل. سيحاول :courier_name مجدداً.',
        'returned' => 'تم الإرجاع',
        'returned_description' => 'تم إرجاع الطرد للمرسل.',
        'cancelled' => 'تم إلغاء الشحنة',
        'cancelled_description' => 'تم إلغاء الشحنة.',
        'eta_updated' => 'تحديث موعد التوصيل',
        'eta_updated_description' => 'الوصول المتوقع: :new_eta',
        'eta_delayed' => 'تأخر موعد التوصيل',
        'eta_delayed_description' => 'تأخر التوصيل بمقدار :delay_days أيام. الموعد الجديد: :new_eta',
    ],

    // Refund Events
    'refund' => [
        'requested' => 'تم طلب استرداد المبلغ',
        'requested_description' => 'تم تقديم طلب استرداد بقيمة :amount.',
        'processing' => 'جاري معالجة الاسترداد',
        'processing_description' => 'يتم معالجة طلب الاسترداد الخاص بك.',
        'approved' => 'تمت الموافقة على الاسترداد',
        'approved_description' => 'تمت الموافقة على استرداد بقيمة :amount وإضافته إلى محفظتك.',
        'processed' => 'تم معالجة الاسترداد',
        'processed_description' => 'تم معالجة :refund_type بقيمة :refund_amount. إجمالي المبلغ المسترد: :total_refunded. الرصيد المتبقي: :remaining_balance',
        'rejected' => 'تم رفض الاسترداد',
        'rejected_description' => 'تم رفض طلب الاسترداد. السبب: :reason',
        'failed' => 'فشل الاسترداد',
        'failed_description' => 'فشلت معالجة الاسترداد. يرجى الاتصال بالدعم.',
    ],

    // Refund Types
    'refund_types' => [
        'full' => 'استرداد كامل',
        'partial' => 'استرداد جزئي',
    ],

    // Status Change Events
    'status' => [
        'changed' => 'تم تغيير الحالة',
        'changed_description' => 'تم تغيير حالة الطلب من :old_status إلى :new_status.',
    ],

    // Progress Milestones
    'milestone' => [
        'order_placed' => 'تم تقديم الطلب',
        'payment_confirmed' => 'تم تأكيد الدفع',
        'preparing_shipment' => 'جاري تحضير الشحنة',
        'shipped' => 'تم الشحن',
        'out_for_delivery' => 'قيد التوصيل',
        'delivered' => 'تم التوصيل',
    ],

    // Actor Types
    'actor' => [
        'system' => 'النظام',
        'admin' => 'المشرف',
        'customer' => 'العميل',
        'courier' => 'شركة الشحن',
    ],

    // Estimated Delivery
    'eta' => [
        'source' => [
            'shipment' => 'بناءً على تتبع شركة الشحن',
            'calculated' => 'وقت التوصيل المقدر',
            'fallback' => 'تقدير التوصيل القياسي',
        ],
        'confidence' => [
            'high' => 'دقيق',
            'medium' => 'تقديري',
            'low' => 'تقريبي',
        ],
    ],
];
