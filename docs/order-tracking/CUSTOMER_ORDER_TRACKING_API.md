# Customer Order Tracking API

## Overview

This document describes the customer-facing order tracking API that provides real-time visibility into order progress, shipment status, and delivery updates.

The tracking system uses the **OrderTrackingEvent** projection model to provide a unified timeline of all order-related events with translation support and progress tracking.

---

## Authentication

### Public Tracking (No Authentication)
Customers can track orders using their order number without authentication.

### Authenticated Tracking (Requires Authentication)
Logged-in customers can view their order history and detailed tracking information.

**Guard**: `sanctum`

---

## Endpoints

### 1. Track Order by Order Number (Public)

**POST** `/api/v1/general/track-order`

Track an order using only the order number. Returns customer-visible events only.

#### Request Body

```json
{
  "order_number": "ORD-123456"
}
```

#### Validation Rules

| Field | Type | Required | Rules |
|-------|------|----------|-------|
| order_number | string | Yes | required, exists:orders,order_number |

#### Success Response (200)

```json
{
  "success": true,
  "message": "Order tracking retrieved successfully",
  "data": {
    "order": {
      "id": 1,
      "order_number": "ORD-123456",
      "status": "processing",
      "shipment_status": "in_transit",
      "tracking_number": "TRK123456789",
      "courier_name": "DHL Express",
      "estimated_delivery_at": "2026-09-25T14:00:00Z",
      "created_at": "2026-09-21T10:00:00Z"
    },
    "timeline": [
      {
        "timestamp": "2026-09-21T10:00:00Z",
        "event_type": "order.created",
        "title": "Order Placed",
        "description": "Your order has been successfully placed and is being processed.",
        "actor": "System",
        "icon": "shopping-cart",
        "metadata": {
          "order_number": "ORD-123456"
        }
      },
      {
        "timestamp": "2026-09-21T10:05:00Z",
        "event_type": "payment.succeeded",
        "title": "Payment Successful",
        "description": "Payment of 150.00 EGP via credit_card completed successfully.",
        "actor": "Payment Gateway",
        "icon": "credit-card",
        "metadata": {
          "amount": "150.00 EGP",
          "method": "credit_card"
        }
      },
      {
        "timestamp": "2026-09-22T09:00:00Z",
        "event_type": "shipment.picked_up",
        "title": "Package Picked Up",
        "description": "Your package has been picked up by DHL Express.",
        "actor": "DHL Express",
        "icon": "truck",
        "metadata": {
          "tracking_number": "TRK123456789",
          "courier_name": "DHL Express"
        }
      },
      {
        "timestamp": "2026-09-23T14:30:00Z",
        "event_type": "shipment.in_transit",
        "title": "In Transit",
        "description": "Your package is on its way (Tracking: TRK123456789).",
        "actor": "DHL Express",
        "icon": "map-pin",
        "metadata": {
          "tracking_number": "TRK123456789"
        }
      }
    ]
  }
}
```

#### Error Responses

**404 Not Found**
```json
{
  "success": false,
  "message": "Order not found"
}
```

**422 Validation Error**
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "order_number": ["The order number field is required."]
  }
}
```

---

### 2. Track Authenticated Order (Authenticated)

**GET** `/api/v1/general/orders/{orderId}/track`

Track an order with full details including progress widget. Only accessible to the order owner.

**Authentication**: Required (`sanctum`)

#### Path Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| orderId | integer | Yes | Order ID |

#### Success Response (200)

```json
{
  "success": true,
  "message": "Order tracking retrieved successfully",
  "data": {
    "order": {
      "id": 1,
      "order_number": "ORD-123456",
      "status": "processing",
      "shipment_status": "in_transit",
      "tracking_number": "TRK123456789",
      "courier_name": "DHL Express",
      "estimated_delivery_at": "2026-09-25T14:00:00Z",
      "created_at": "2026-09-21T10:00:00Z"
    },
    "timeline": [
      // Same format as public tracking
    ],
    "progress": {
      "stages": {
        "order_placed": true,
        "payment_confirmed": true,
        "preparing_shipment": true,
        "shipped": true,
        "out_for_delivery": false,
        "delivered": false
      },
      "progress_percentage": 67,
      "completed_stages": 4,
      "total_stages": 6
    }
  }
}
```

#### Progress Widget Stages

The progress widget tracks 6 key milestones:

1. **order_placed** - Order has been created
2. **payment_confirmed** - Payment succeeded
3. **preparing_shipment** - Order is being prepared for shipment
4. **shipped** - Package has been picked up or is in transit
5. **out_for_delivery** - Package is out for delivery
6. **delivered** - Package has been delivered

#### Stage Mapping Rules

Each `event_type` maps to one or more stages:

- `order.created` → `order_placed`
- `payment.succeeded` → `payment_confirmed`
- `fulfillment.processing` → `preparing_shipment`
- `order.shipped`, `shipment.picked_up`, `shipment.in_transit` → `shipped`
- `shipment.out_for_delivery`, `fulfillment.out_for_delivery` → `out_for_delivery`
- `order.delivered`, `shipment.delivered`, `fulfillment.delivered` → `delivered`

#### Error Responses

**401 Unauthorized**
```json
{
  "success": false,
  "message": "Unauthenticated"
}
```

**403 Forbidden**
```json
{
  "success": false,
  "message": "You do not have permission to view this order"
}
```

**404 Not Found**
```json
{
  "success": false,
  "message": "Order not found"
}
```

---

### 3. List User Orders (Authenticated)

**GET** `/api/v1/general/my-orders`

Get paginated list of authenticated user's orders with basic tracking information.

**Authentication**: Required (`sanctum`)

#### Query Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| page | integer | No | 1 | Page number |
| per_page | integer | No | 15 | Items per page (max 50) |

#### Success Response (200)

```json
{
  "success": true,
  "message": "Orders retrieved successfully",
  "data": [
    {
      "id": 1,
      "order_number": "ORD-123456",
      "status": "processing",
      "shipment_status": "in_transit",
      "tracking_number": "TRK123456789",
      "courier_name": "DHL Express",
      "total": 150.00,
      "created_at": "2026-09-21T10:00:00Z",
      "estimated_delivery_at": "2026-09-25T14:00:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 15,
    "total": 42,
    "last_page": 3
  }
}
```

---

## Timeline Event Structure

Each timeline event contains:

| Field | Type | Description |
|-------|------|-------------|
| timestamp | string (ISO 8601) | When the event occurred |
| event_type | string | System event type (e.g., "order.created") |
| title | string | Translated customer-facing title |
| description | string | Translated customer-facing description with placeholders replaced |
| actor | string | Who triggered the event (System, Admin, Courier, etc.) |
| icon | string | Suggested icon name for UI display |
| metadata | object | Additional event-specific data |

---

## Translation Support

All customer-facing strings use Laravel's translation system with parameterized keys:

### Translation Keys

Events use translation keys from `resources/lang/{locale}/tracking.php`:

- **Title**: `tracking.{category}.{event}`
- **Description**: `tracking.{category}.{event}_description`

### Placeholder Replacement

Descriptions support dynamic placeholders from event metadata:

```php
// Translation key
'tracking.payment.succeeded_description' => 'Payment of :amount via :method completed successfully.'

// Metadata
{
  "amount": "150.00 EGP",
  "method": "credit_card"
}

// Result
"Payment of 150.00 EGP via credit_card completed successfully."
```

### Supported Locales

- `en` - English
- `ar` - Arabic

---

## Event Types Reference

### Order Events
- `order.created` - Order placed
- `order.confirmed` - Order confirmed
- `order.processing` - Order processing
- `order.cancelled` - Order cancelled
- `order.completed` - Order completed
- `order.shipped` - Order shipped
- `order.delivered` - Order delivered

### Payment Events
- `payment.pending` - Payment pending
- `payment.processing` - Payment processing
- `payment.succeeded` - Payment successful
- `payment.failed` - Payment failed
- `payment.refunded` - Payment refunded

### Shipment Events
- `shipment.pending` - Shipment pending
- `shipment.label_created` - Label created
- `shipment.picked_up` - Package picked up
- `shipment.in_transit` - In transit
- `shipment.out_for_delivery` - Out for delivery
- `shipment.delivered` - Delivered
- `shipment.failed_delivery` - Delivery failed
- `shipment.returned` - Returned
- `shipment.cancelled` - Shipment cancelled
- `shipment.eta_updated` - Delivery date updated
- `shipment.eta_delayed` - Delivery delayed

### Fulfillment Events
- `fulfillment.pending` - Awaiting fulfillment
- `fulfillment.processing` - Preparing for shipment
- `fulfillment.out_for_delivery` - Out for delivery
- `fulfillment.delivered` - Delivered
- `fulfillment.failed` - Delivery failed

### Refund Events
- `refund.requested` - Refund requested
- `refund.processing` - Refund processing
- `refund.approved` - Refund approved
- `refund.processed` - Refund processed
- `refund.rejected` - Refund rejected
- `refund.failed` - Refund failed

---

## Customer Visibility

Events are filtered by the `customer_visible` boolean flag in the OrderTrackingEvent model.

### Public Tracking
- Returns only events where `customer_visible = true`
- No authentication required

### Authenticated Tracking
- Returns all events (both visible and internal)
- Requires authentication
- User must own the order

---

## Best Practices

### Frontend Integration

1. **Polling**: Poll tracking endpoint every 30-60 seconds for live updates
2. **Caching**: Cache timeline data for 30 seconds to reduce server load
3. **Progress Widget**: Display progress bar using `progress_percentage`
4. **Icons**: Map icon names to your UI icon library
5. **Timestamps**: Format timestamps according to user's locale and timezone

### Error Handling

1. Handle 404 errors gracefully (order not found)
2. Handle 403 errors (order belongs to another user)
3. Retry failed requests with exponential backoff
4. Show user-friendly error messages

### Performance

1. Use pagination for order lists
2. Cache tracking responses
3. Lazy load timeline events if very long
4. Consider WebSocket/Server-Sent Events for real-time updates (future enhancement)

---

## Testing

### Manual Testing

```bash
# Public tracking
curl -X POST http://localhost:8000/api/v1/general/track-order \
  -H "Content-Type: application/json" \
  -d '{"order_number": "ORD-123456"}'

# Authenticated tracking
curl -X GET http://localhost:8000/api/v1/general/orders/1/track \
  -H "Authorization: Bearer YOUR_TOKEN"

# List user orders
curl -X GET http://localhost:8000/api/v1/general/my-orders \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

## Changelog

### [1.0.0] - 2026-09-21

#### Added
- Public order tracking endpoint (POST /track-order)
- Authenticated order tracking endpoint (GET /orders/{orderId}/track)
- My orders endpoint (GET /my-orders)
- 6-stage progress widget
- Translation support for AR/EN
- Metadata placeholder replacement
- Customer visibility filtering
- Event type mapping to progress stages

---

## Support

For questions or issues:
- Check translation keys in `resources/lang/{locale}/tracking.php`
- Review OrderTrackingEvent model structure
- Check EventServiceProvider for event-listener mappings
- Review OrderTrackingController for implementation details
