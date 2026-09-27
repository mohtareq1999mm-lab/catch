<?php

return [
    'cod_success' => 'Your order has been placed. You will pay upon delivery.',
    'cart_not_found' => 'Cart not found', 
    'cart_empty' => 'Cart is empty',  
    'pay_at_cashier_requires_pickup' => 'When choosing pay at cashier, you should choose pickup fulfillment type.',
    'payment_method_online' => 'Online payment',
    'payment_method_cod' => 'Cash on delivery',
    'payment_method_pay_at_cashier' => 'Pay at cashier',

    // Fast Shipping
    'fast_shipping_unavailable' => 'Fast shipping is not available at this time.',
    'fast_shipping_hours_only' => 'Fast shipping is only available between :start and :end.',
    'fast_shipping_governorate_unavailable' => 'Fast shipping is not available in your governorate.',
    'fast_shipping_items_ineligible' => 'One or more items in your cart are not eligible for fast shipping.',
    'invalid_order_status_transition' => 'Cannot change order status from :from to :to.',
    'invalid_flow_transition' => 'Cannot change order status from :from to :to in its flow.',
    'shipping_type_unsupported' => 'The selected shipping type is not supported.',
    'shipping_type_unavailable' => 'The selected shipping type is not available for this order.',
    'flow_not_configured' => 'No active order flow is configured for this shipping type.',
    'flow_statuses_required' => 'The flow must contain at least one status.',
    'flow_statuses_duplicate' => 'The flow must not contain duplicate statuses.',
    'flow_statuses_unknown' => 'One or more statuses do not exist.',
    'flow_status_inactive' => 'Status :code is inactive and cannot be added to a flow.',
    'no_pending_cod_transaction' => 'No pending COD transaction found.',
    'no_pending_cashier_transaction' => 'No pending Pay at Cashier transaction found.',
];
