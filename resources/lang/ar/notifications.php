<?php

return [
    'order' => [
        'created' => [
            'title' => 'تم تأكيد الطلب',
            'body' => 'تم تقديم طلبك رقم :order_number بنجاح.',
        ],
        'delivered' => [
            'title' => 'تم توصيل الطلب',
            'body' => 'تم توصيل طلبك رقم :order_number.',
        ],
        'cancelled' => [
            'title' => 'تم إلغاء الطلب',
            'body' => 'تم إلغاء طلبك رقم :order_number.',
        ],
        'refunded' => [
            'title' => 'تمت الموافقة على الاسترداد',
            'body' => 'تمت الموافقة على استرداد مبلغ طلبك رقم :order_number.',
        ],
    ],
    'payment' => [
        'succeeded' => [
            'title' => 'تم الدفع بنجاح',
            'body' => 'تمت عملية الدفع لطلبك رقم :order_number بنجاح.',
        ],
        'failed' => [
            'title' => 'فشل الدفع',
            'body' => 'فشل الدفع لطلبك رقم :order_number. يرجى المحاولة مرة أخرى.',
        ],
    ],
    'digital' => [
        'available' => [
            'title' => 'منتجك الرقمي جاهز',
            'body' => 'منتجاتك الرقمية للطلب رقم :order_number متاحة الآن للتنزيل.',
        ],
        'delivery_failed' => [
            'title' => 'فشل التسليم الرقمي',
            'body' => 'فشل التسليم الرقمي للطلب رقم :order_number. السبب: :reason',
        ],
    ],
    'coupon' => [
        'assigned' => [
            'title' => 'تم إسناد قسيمة إليك',
            'body' => 'تم إسناد القسيمة :coupon_code إلى حسابك.',
        ],
        'available' => [
            'title' => 'قسيمة جديدة متاحة',
            'body' => 'أصبحت قسيمة جديدة :coupon_code متاحة لك.',
        ],
        'used' => [
            'title' => 'تم استخدام القسيمة',
            'body' => 'لقد استخدمت القسيمة :coupon_code.',
        ],
    ],
    'flash_sale' => [
        'available' => [
            'title' => 'البيع المفاجئ مباشر الآن',
            'body' => 'بدأ بيع مفاجئ جديد :flash_sale_title. اغتنم العروض قبل انتهائها!',
        ],
        'price_drop' => [
            'title' => 'انخفاض السعر في البيع المفاجئ',
            'body' => 'انخفض سعر منتج ضمن البيع المفاجئ :flash_sale_title. شاهده الآن!',
        ],
        'ending_soon' => [
            'title' => 'البيع المفاجئ ينتهي قريباً',
            'body' => 'ينتهي البيع المفاجئ :flash_sale_title خلال 24 ساعة. لا تفوت الفرصة!',
        ],
    ],
    'review' => [
        'approved' => [
            'title' => 'تمت الموافقة على التقييم',
            'body' => 'تمت الموافقة على تقييمك لـ :product_name وهو ظاهر الآن.',
        ],
        'rejected' => [
            'title' => 'لم يتم اعتماد التقييم',
            'body' => 'تعذر اعتماد تقييمك لـ :product_name في الوقت الحالي.',
        ],
    ],
    'discount' => [
        'changed' => [
            'title' => 'تم تحديث الخصم',
            'body' => 'تم تحديث الخصم على :product_name. اضغط لرؤية العرض الجديد!',
        ],
    ],
    'price' => [
        'drop' => [
            'title' => 'انخفض السعر',
            'body' => 'انخفض سعر :product_name من :old_price إلى :new_price. احصل عليه قبل نفاذ الكمية!',
        ],
    ],
    'back' => [
        'in_stock' => [
            'title' => 'متوفر مجدداً',
            'body' => 'عاد :product_name إلى المخزون. اطلب الآن قبل نفاذه مجدداً!',
        ],
    ],
    'cart' => [
        'abandoned' => [
            'title' => 'تركت منتجات في سلتك',
            'body' => 'سلتك تنتظرك. أكمل طلبك قبل انتهاء صلاحيتها!',
        ],
    ],
    'promotion' => [
        'available' => [
            'title' => 'عرض جديد متاح',
            'body' => 'تحقق من :promotion_name ووفر في طلبك القادم.',
        ],
        'price_drop' => [
            'title' => 'انخفاض السعر في العرض',
            'body' => 'انخفض سعر منتج ضمن العرض :promotion_name. شاهده الآن!',
        ],
        'ending_soon' => [
            'title' => 'العرض ينتهي قريباً',
            'body' => 'ينتهي العرض :promotion_name خلال 24 ساعة. لا تفوت الفرصة!',
        ],
    ],
    'admin' => [
        'new_order' => [
            'title' => 'طلب جديد',
            'body' => 'تم تقديم طلب جديد رقم :order_number.',
        ],
        'digital_delivery_failed' => [
            'title' => 'فشل التسليم الرقمي',
            'body' => 'فشل التسليم الرقمي للطلب رقم :order_number. السبب: :reason',
        ],
        'contact_message' => [
            'title' => 'رسالة تواصل جديدة',
            'body' => 'تم استلام رسالة تواصل جديدة من :customer_name.',
        ],
        'login' => [
            'title' => 'تسجيل دخول المشرف',
            'body' => 'قام :admin_name بتسجيل الدخول للتو.',
        ],
    ],
];
