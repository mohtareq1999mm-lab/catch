<?php

return [
    'order' => [
        'created' => [
            'title' => 'Order placed',
            'body' => 'Your order #:order_number has been placed successfully.',
        ],
        'delivered' => [
            'title' => 'Order delivered',
            'body' => 'Your order #:order_number has been delivered.',
        ],
        'cancelled' => [
            'title' => 'Order cancelled',
            'body' => 'Your order #:order_number has been cancelled.',
        ],
        'refunded' => [
            'title' => 'Refund approved',
            'body' => 'Your refund for order #:order_number has been approved.',
        ],
    ],
    'payment' => [
        'succeeded' => [
            'title' => 'Payment successful',
            'body' => 'Your payment for order #:order_number was successful.',
        ],
        'failed' => [
            'title' => 'Payment failed',
            'body' => 'Your payment for order #:order_number failed. Please try again.',
        ],
    ],
    'digital' => [
        'available' => [
            'title' => 'Digital product ready',
            'body' => 'Your digital products for order #:order_number are now available to download.',
        ],
        'delivery_failed' => [
            'title' => 'Digital delivery failed',
            'body' => 'Digital delivery failed for order #:order_number. Reason: :reason',
        ],
    ],
    'coupon' => [
        'assigned' => [
            'title' => 'Coupon assigned to you',
            'body' => 'The coupon :coupon_code has been assigned to your account.',
        ],
        'available' => [
            'title' => 'New coupon available',
            'body' => 'A new coupon :coupon_code is now available for you.',
        ],
        'used' => [
            'title' => 'Coupon used',
            'body' => 'You have used the coupon :coupon_code.',
        ],
    ],
    'flash_sale' => [
        'available' => [
            'title' => 'Flash sale is live',
            'body' => 'A new flash sale :flash_sale_title has started. Grab the deals before they end!',
        ],
        'price_drop' => [
            'title' => 'Price drop in flash sale',
            'body' => 'A product in the :flash_sale_title flash sale just dropped in price. Check it out now!',
        ],
        'ending_soon' => [
            'title' => 'Flash sale ending soon',
            'body' => 'The :flash_sale_title flash sale ends within 24 hours. Don’t miss out!',
        ],
    ],
    'review' => [
        'approved' => [
            'title' => 'Review approved',
            'body' => 'Your review for :product_name has been approved and is now visible.',
        ],
        'rejected' => [
            'title' => 'Review not approved',
            'body' => 'Your review for :product_name could not be approved at this time.',
        ],
    ],
    'discount' => [
        'changed' => [
            'title' => 'Discount updated',
            'body' => 'The discount on :product_name has been updated. Tap to see the new offer!',
        ],
    ],
    'price' => [
        'drop' => [
            'title' => 'Price dropped',
            'body' => ':product_name dropped from :old_price to :new_price. Grab it before it’s gone!',
        ],
    ],
    'back' => [
        'in_stock' => [
            'title' => 'Back in stock',
            'body' => ':product_name is back in stock. Order now before it sells out again!',
        ],
    ],
    'cart' => [
        'abandoned' => [
            'title' => 'You left items in your cart',
            'body' => 'Your cart is waiting for you. Complete your order before it expires!',
        ],
    ],
    'promotion' => [
        'available' => [
            'title' => 'New promotion available',
            'body' => 'Check out :promotion_name and save on your next order.',
        ],
        'price_drop' => [
            'title' => 'Price drop in promotion',
            'body' => 'A product in the :promotion_name promotion just dropped in price. Check it out now!',
        ],
        'ending_soon' => [
            'title' => 'Promotion ending soon',
            'body' => 'The :promotion_name promotion ends within 24 hours. Don’t miss out!',
        ],
    ],
    'admin' => [
        'new_order' => [
            'title' => 'New order received',
            'body' => 'New order #:order_number has been placed.',
        ],
        'digital_delivery_failed' => [
            'title' => 'Digital delivery failed',
            'body' => 'Digital delivery failed for order #:order_number. Reason: :reason',
        ],
        'contact_message' => [
            'title' => 'New contact message',
            'body' => 'A new contact message was received from :customer_name.',
        ],
        'login' => [
            'title' => 'Admin login',
            'body' => ':admin_name just logged in.',
        ],
    ],
];
