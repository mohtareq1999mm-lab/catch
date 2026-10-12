<?php
return [
    'order' => [
        'cancelOrder'         => [
            'admin'      => [
                'message' => 'تم إلغاء الطلب برقم التتبع :ORDER_TRACKING_NUMBER',
                'subject' => 'تم إلغاء الطلب',
            ],
            'customer'   => [
                'message' => 'تم إلغاء طلبك ورقم التتبع :ORDER_TRACKING_NUMBER',
                'subject' => 'تم إلغاء طلبك',
            ],
            'storeOwner' => [
                'message' => 'عزيزي صاحب المتجر، تم إلغاء الطلب برقم التتبع :ORDER_TRACKING_NUMBER',
                'subject' => 'تم إلغاء الطلب',
            ],
        ],
        'orderCreated'        => [
            'admin'      => [
                'message' => 'تم تقديم طلب جديد بواسطة :customer_name برقم التتبع :ORDER_TRACKING_NUMBER',
                'subject' => 'تم تقديم طلب جديد',
            ],
            'customer'   => [
                'message' => 'تم تقديم طلبك بنجاح برقم التتبع :ORDER_TRACKING_NUMBER',
                'subject' => 'تم تقديم طلبك بنجاح',
            ],
            'storeOwner' => [
                'message' => 'عزيزي صاحب المتجر، تم تقديم طلب جديد بواسطة :customer_name برقم التتبع :ORDER_TRACKING_NUMBER',
                'subject' => 'تم تقديم طلب جديد',
            ],
        ],
        'deliverOrder'        => [
            'admin'      => [
                'message' => 'تم توصيل الطلب بنجاح برقم التتبع :ORDER_TRACKING_NUMBER',
                'subject' => 'تم توصيل الطلب',
            ],
            'customer'   => [
                'message' => 'تم توصيل طلبك بنجاح برقم التتبع :ORDER_TRACKING_NUMBER',
                'subject' => 'تم توصيل طلبك بنجاح',
            ],
            'storeOwner' => [
                'message' => 'عزيزي صاحب المتجر، تم توصيل الطلب بنجاح برقم التتبع :ORDER_TRACKING_NUMBER',
                'subject' => 'تم توصيل الطلب',
            ],

        ],
        'statusChangeOrder'   => [
            'admin'      => [
                'message' => 'تم تغيير حالة الطلب إلى :order_status برقم التتبع :ORDER_TRACKING_NUMBER',
                'subject' => 'تم تغيير حالة الطلب',
            ],
            'customer'   => [
                'message' => 'تم تغيير حالة طلبك إلى :order_status برقم التتبع :ORDER_TRACKING_NUMBER',
                'subject' => 'تم تغيير حالة طلبك',
            ],
            'storeOwner' => [
                'message' => 'عزيزي صاحب المتجر، تم تغيير حالة الطلب إلى :order_status برقم التتبع :ORDER_TRACKING_NUMBER',
                'subject' => 'تم تغيير حالة الطلب',
            ],
        ],
        'paymentSuccessOrder' => [
            'admin'      => [
                'message' => 'تم الدفع للطلب بنجاح برقم التتبع :ORDER_TRACKING_NUMBER',
                'subject' => 'تم الدفع للطلب بنجاح',
            ],
            'customer'   => [
                'message' => 'تم الدفع لطلبك بنجاح برقم التتبع :ORDER_TRACKING_NUMBER',
                'subject' => 'تم الدفع لطلبك بنجاح',
            ],
            'storeOwner' => [
                'message' => 'عزيزي صاحب المتجر، تم الدفع للطلب بنجاح برقم التتبع :ORDER_TRACKING_NUMBER',
                'subject' => 'تم الدفع للطلب بنجاح',
            ],
        ],
        'paymentFailedOrder'  => [
            'admin'      => [
                'message' => 'فشل الدفع للطلب برقم التتبع :ORDER_TRACKING_NUMBER',
                'subject' => 'فشل الدفع للطلب',
            ],
            'customer'   => [
                'message' => 'فشل الدفع لطلبك برقم التتبع :ORDER_TRACKING_NUMBER',
                'subject' => 'فشل الدفع لطلبك',
            ],
            'storeOwner' => [
                'message' => 'عزيزي صاحب المتجر، فشل الدفع للطلب برقم التتبع :ORDER_TRACKING_NUMBER',
                'subject' => 'فشل الدفع للطلب',
            ],
        ],
        'refundRequested'     => [
            'admin'    => [
                'message' => 'طلب العميل استردادًا للطلب برقم التتبع :ORDER_TRACKING_NUMBER',
                'subject' => 'طلب استرداد',
            ],
            'customer' => [
                'message' => 'تم تقديم طلب الاسترداد بنجاح برقم التتبع :ORDER_TRACKING_NUMBER',
                'subject' => 'تم تقديم طلب الاسترداد بنجاح',
            ],
        ],
        'refundStatusChange' => [
            'admin'    => [
                'message' => 'تم تغيير حالة الاسترداد إلى :refund_status برقم التتبع :ORDER_TRACKING_NUMBER',
                'subject' => 'تم تغيير حالة الاسترداد',
            ],
            'customer' => [
                'message' => 'تم تغيير حالة الاسترداد إلى :refund_status برقم التتبع :ORDER_TRACKING_NUMBER',
                'subject' => 'تم تغيير حالة الاسترداد',
            ],
        ],
    ]
];
