<?php

return [
    // Order Lifecycle Events
    'order' => [
        'created' => 'Order Placed',
        'created_description' => 'Your order has been successfully placed and is being processed.',
        'confirmed' => 'Order Confirmed',
        'confirmed_description' => 'Your order has been confirmed and will be prepared for shipment.',
        'processing' => 'Order Processing',
        'processing_description' => 'Your order is currently being prepared.',
        'cancelled' => 'Order Cancelled',
        'cancelled_description' => 'Your order has been cancelled. Reason: :cancellation_reason',
        'completed' => 'Order Completed',
        'completed_description' => 'Your order has been successfully completed.',
    ],

    // Cancellation Reasons
    'cancellation_reasons' => [
        'payment_failed' => 'Payment Failed',
        'customer_request' => 'Customer Request',
        'out_of_stock' => 'Out of Stock',
        'fraud_detected' => 'Fraud Detected',
        'duplicate_order' => 'Duplicate Order',
        'other' => 'Other',
        'unknown' => 'Unknown',
    ],

    // Payment Events
    'payment' => [
        'pending' => 'Payment Pending',
        'pending_description' => 'Awaiting payment confirmation.',
        'processing' => 'Payment Processing',
        'processing_description' => 'Your payment is being processed.',
        'succeeded' => 'Payment Successful',
        'succeeded_description' => 'Payment of :amount via :method completed successfully.',
        'failed' => 'Payment Failed',
        'failed_description' => 'Payment attempt failed. This is attempt #:retry_attempt. Please try again.',
        'refunded' => 'Payment Refunded',
        'refunded_description' => 'Refund of :amount has been processed to your wallet.',
    ],

    // Fulfillment Events
    'fulfillment' => [
        'pending' => 'Awaiting Fulfillment',
        'pending_description' => 'Your order is waiting to be fulfilled.',
        'processing' => 'Preparing for Shipment',
        'processing_description' => 'Your items are being prepared for shipping.',
        'out_for_delivery' => 'Out for Delivery',
        'out_for_delivery_description' => 'Your order is out for delivery.',
        'delivered' => 'Delivered',
        'delivered_description' => 'Your order has been delivered successfully.',
        'failed' => 'Delivery Failed',
        'failed_description' => 'Delivery attempt failed. We will retry soon.',
    ],

    // Shipment Events
    'shipment' => [
        'created' => 'Shipment Created',
        'created_description' => 'Shipment has been created with tracking number :tracking_number.',
        'picked_up' => 'Package Picked Up',
        'picked_up_description' => 'Your package has been picked up by :carrier.',
        'in_transit' => 'In Transit',
        'in_transit_description' => 'Your package is on its way.',
        'out_for_delivery' => 'Out for Delivery',
        'out_for_delivery_description' => 'Your package is out for delivery.',
        'delivered' => 'Delivered',
        'delivered_description' => 'Your package has been delivered.',
        'failed' => 'Delivery Failed',
        'failed_description' => 'Delivery attempt failed.',
        'returned' => 'Package Returned',
        'returned_description' => 'Your package has been returned.',
    ],

    // Inventory Events
    'inventory' => [
        'reserved' => 'Inventory Reserved',
        'reserved_description' => 'Items have been reserved for your order.',
        'committed' => 'Inventory Committed',
        'committed_description' => 'Items have been allocated from inventory.',
        'restored' => 'Inventory Restored',
        'restored_description' => 'Items have been returned to inventory.',
    ],

    // Shipment Events
    'shipment' => [
        'pending' => 'Shipment Pending',
        'pending_description' => 'Your shipment is being prepared.',
        'label_created' => 'Label Created',
        'label_created_description' => 'Shipping label has been created.',
        'picked_up' => 'Package Picked Up',
        'picked_up_description' => 'Your package has been picked up by :courier_name.',
        'in_transit' => 'In Transit',
        'in_transit_description' => 'Your package is on its way (Tracking: :tracking_number).',
        'out_for_delivery' => 'Out for Delivery',
        'out_for_delivery_description' => 'Your package is out for delivery today.',
        'delivered' => 'Delivered',
        'delivered_description' => 'Your package has been delivered successfully.',
        'failed_delivery' => 'Delivery Failed',
        'failed_delivery_description' => 'Delivery attempt failed. :courier_name will retry.',
        'returned' => 'Returned',
        'returned_description' => 'Package has been returned to sender.',
        'cancelled' => 'Shipment Cancelled',
        'cancelled_description' => 'Shipment has been cancelled.',
        'eta_updated' => 'Delivery Date Updated',
        'eta_updated_description' => 'Estimated delivery: :new_eta',
        'eta_delayed' => 'Delivery Delayed',
        'eta_delayed_description' => 'Delivery delayed by :delay_days days. New estimated delivery: :new_eta',
    ],

    // Refund Events
    'refund' => [
        'requested' => 'Refund Requested',
        'requested_description' => 'A refund request has been submitted for :amount.',
        'processing' => 'Refund Processing',
        'processing_description' => 'Your refund is being processed.',
        'approved' => 'Refund Approved',
        'approved_description' => 'Refund of :amount has been approved and credited to your wallet.',
        'processed' => 'Refund Processed',
        'processed_description' => ':refund_type refund of :refund_amount processed. Total refunded: :total_refunded. Remaining balance: :remaining_balance',
        'rejected' => 'Refund Rejected',
        'rejected_description' => 'Your refund request has been rejected. Reason: :reason',
        'failed' => 'Refund Failed',
        'failed_description' => 'Refund processing failed. Please contact support.',
    ],

    // Refund Types
    'refund_types' => [
        'full' => 'Full Refund',
        'partial' => 'Partial Refund',
    ],

    // Status Change Events
    'status' => [
        'changed' => 'Status Changed',
        'changed_description' => 'Order status changed from :old_status to :new_status.',
    ],

    // Progress Milestones
    'milestone' => [
        'order_placed' => 'Order Placed',
        'payment_confirmed' => 'Payment Confirmed',
        'preparing_shipment' => 'Preparing Shipment',
        'shipped' => 'Shipped',
        'out_for_delivery' => 'Out for Delivery',
        'delivered' => 'Delivered',
    ],

    // Actor Types
    'actor' => [
        'system' => 'System',
        'admin' => 'Admin',
        'customer' => 'Customer',
        'courier' => 'Courier',
    ],

    // Estimated Delivery
    'eta' => [
        'source' => [
            'shipment' => 'Based on carrier tracking',
            'calculated' => 'Estimated delivery time',
            'fallback' => 'Standard delivery estimate',
        ],
        'confidence' => [
            'high' => 'Accurate',
            'medium' => 'Estimated',
            'low' => 'Approximate',
        ],
    ],
];
