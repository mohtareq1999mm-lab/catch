# Admin Shipment Integration Guide

## Overview

This guide explains how to integrate the admin shipment management API into your admin panel. The API allows administrators to update shipment status, tracking information, and estimated delivery dates while automatically recording all changes in the order tracking timeline.

---

## Architecture

### Event-Driven Design

All shipment updates emit events that trigger timeline recording:

```
Admin Update → ShipmentStatusChanged Event → RecordShipmentStatusInTimeline Listener
            ↘ EstimatedDeliveryChanged Event → RecordETAChangeInTimeline Listener
```

### Database Transaction Safety

Updates are wrapped in database transactions to ensure atomicity:

```php
DB::transaction(function () use ($order, $request) {
    // Emit events first
    event(new ShipmentStatusChanged(...));
    
    if ($etaChanged) {
        event(new EstimatedDeliveryChanged(...));
    }
    
    // Event listeners update Order model
});
```

**Important**: Do NOT manually update the Order model after emitting events. The event listeners handle model updates.

---

## Authentication

All admin endpoints require:

**Middleware**: `api`, `auth:sanctum`, `throttle:admin`

**Guard**: `sanctum`

**Permissions**: Admin role required (enforced by middleware)

---

## Endpoints

### 1. Update Shipment Status

**POST** `/api/v1/admin/orders/{orderId}/shipment/update-status`

Update the shipment status, tracking information, courier details, and estimated delivery date for an order.

#### Path Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| orderId | integer | Yes | Order ID |

#### Request Body

```json
{
  "status": "in_transit",
  "tracking_number": "TRK123456789",
  "courier_name": "DHL Express",
  "location_data": {
    "city": "Cairo",
    "country": "Egypt",
    "latitude": 30.0444,
    "longitude": 31.2357
  },
  "estimated_delivery_at": "2026-09-25",
  "notes": "Package cleared customs"
}
```

#### Validation Rules

| Field | Type | Required | Rules |
|-------|------|----------|-------|
| status | string | Yes | One of: pending, label_created, picked_up, in_transit, out_for_delivery, delivered, failed_delivery, returned, cancelled |
| tracking_number | string | No | max:255 |
| courier_name | string | No | max:255 |
| location_data | object | No | Free-form JSON object |
| estimated_delivery_at | date | No | Valid date (Y-m-d format) |
| notes | string | No | Admin notes |

#### Status Enum Values

```php
'pending'           // Awaiting shipment
'label_created'     // Shipping label created
'picked_up'         // Package picked up by courier
'in_transit'        // Package in transit
'out_for_delivery'  // Package out for delivery
'delivered'         // Package delivered
'failed_delivery'   // Delivery attempt failed
'returned'          // Package returned to sender
'cancelled'         // Shipment cancelled
```

#### Success Response (200)

```json
{
  "success": true,
  "message": "Shipment status updated successfully",
  "data": {
    "order_id": 1,
    "shipment_status": "in_transit",
    "tracking_number": "TRK123456789"
  }
}
```

#### Error Responses

**401 Unauthorized**
```json
{
  "success": false,
  "message": "Unauthenticated"
}
```

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
  "errors": {
    "status": ["The selected status is invalid."],
    "estimated_delivery_at": ["The estimated delivery at must be a valid date."]
  }
}
```

**500 Internal Server Error**
```json
{
  "success": false,
  "message": "Failed to update shipment status",
  "error": "Error details..."
}
```

---

### 2. Get Shipment Details

**GET** `/api/v1/admin/orders/{orderId}/shipment`

Retrieve current shipment information for an order.

#### Path Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| orderId | integer | Yes | Order ID |

#### Success Response (200)

```json
{
  "success": true,
  "data": {
    "order_id": 1,
    "shipment_status": "in_transit",
    "tracking_number": "TRK123456789",
    "courier_name": "DHL Express",
    "estimated_delivery_at": "2026-09-25T00:00:00Z",
    "actual_delivery_at": null
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

---

## Events Emitted

### ShipmentStatusChanged

Emitted whenever shipment status is updated.

**Event Class**: `App\Events\Shipment\ShipmentStatusChanged`

**Payload**:
```php
public function __construct(
    public Order $order,
    public string $oldStatus,
    public string $newStatus,
    public ?string $trackingNumber = null,
    public ?string $courierName = null,
    public ?array $locationData = null,
    public ?\DateTime $estimatedDelivery = null,
)
```

**Listener**: `App\Listeners\Shipment\RecordShipmentStatusInTimeline`

**Timeline Event Type**: `shipment.{status}` (e.g., `shipment.in_transit`)

---

### EstimatedDeliveryChanged

Emitted when ETA is updated (only if it differs from current ETA).

**Event Class**: `App\Events\Shipment\EstimatedDeliveryChanged`

**Payload**:
```php
public function __construct(
    public Order $order,
    public ?\DateTime $oldEta,
    public \DateTime $newEta,
    public string $reason = 'admin_update',
)
```

**Listener**: `App\Listeners\Shipment\RecordETAChangeInTimeline`

**Timeline Event Type**: 
- `shipment.eta_delayed` (if newEta > oldEta)
- `shipment.eta_updated` (otherwise)

---

## Integration Examples

### JavaScript/Axios

```javascript
// Update shipment status
async function updateShipmentStatus(orderId, data) {
  try {
    const response = await axios.post(
      `/api/v1/admin/orders/${orderId}/shipment/update-status`,
      data,
      {
        headers: {
          'Authorization': `Bearer ${adminToken}`,
          'Content-Type': 'application/json'
        }
      }
    );
    
    console.log('Shipment updated:', response.data);
    return response.data;
  } catch (error) {
    if (error.response?.status === 422) {
      console.error('Validation errors:', error.response.data.errors);
    }
    throw error;
  }
}

// Usage
updateShipmentStatus(1, {
  status: 'in_transit',
  tracking_number: 'TRK123456789',
  courier_name: 'DHL Express',
  estimated_delivery_at: '2026-09-25',
  notes: 'Package in transit'
});
```

### PHP/Guzzle

```php
use GuzzleHttp\Client;

$client = new Client([
    'base_uri' => 'https://api.example.com',
    'headers' => [
        'Authorization' => 'Bearer ' . $adminToken,
        'Content-Type' => 'application/json',
    ],
]);

try {
    $response = $client->post('/api/v1/admin/orders/1/shipment/update-status', [
        'json' => [
            'status' => 'in_transit',
            'tracking_number' => 'TRK123456789',
            'courier_name' => 'DHL Express',
            'estimated_delivery_at' => '2026-09-25',
            'notes' => 'Package in transit',
        ],
    ]);
    
    $data = json_decode($response->getBody(), true);
    echo "Success: " . $data['message'];
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
```

### cURL

```bash
curl -X POST "https://api.example.com/api/v1/admin/orders/1/shipment/update-status" \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "status": "in_transit",
    "tracking_number": "TRK123456789",
    "courier_name": "DHL Express",
    "estimated_delivery_at": "2026-09-25",
    "notes": "Package in transit"
  }'
```

---

## Workflow Examples

### Workflow 1: Create Shipment

```javascript
// Step 1: Order is ready to ship
await updateShipmentStatus(orderId, {
  status: 'label_created',
  tracking_number: 'TRK123456789',
  courier_name: 'DHL Express',
  estimated_delivery_at: '2026-09-25',
  notes: 'Shipping label created'
});

// Timeline event created:
// - Type: shipment.label_created
// - Title: "Label Created"
// - Description: "Shipping label has been created."
```

### Workflow 2: Package Picked Up

```javascript
await updateShipmentStatus(orderId, {
  status: 'picked_up',
  tracking_number: 'TRK123456789',
  courier_name: 'DHL Express',
  location_data: {
    city: 'Cairo',
    facility: 'Cairo Distribution Center'
  },
  notes: 'Package picked up from warehouse'
});

// Timeline event created:
// - Type: shipment.picked_up
// - Title: "Package Picked Up"
// - Description: "Your package has been picked up by DHL Express."
```

### Workflow 3: In Transit

```javascript
await updateShipmentStatus(orderId, {
  status: 'in_transit',
  location_data: {
    city: 'Alexandria',
    checkpoint: 'Alexandria Hub',
    latitude: 31.2001,
    longitude: 29.9187
  },
  notes: 'Package arrived at Alexandria hub'
});

// Timeline event created:
// - Type: shipment.in_transit
// - Title: "In Transit"
// - Description: "Your package is on its way (Tracking: TRK123456789)."
```

### Workflow 4: ETA Delay

```javascript
// Original ETA: 2026-09-25
// New ETA: 2026-09-27 (2 days delay)

await updateShipmentStatus(orderId, {
  status: 'in_transit',
  estimated_delivery_at: '2026-09-27',
  notes: 'Delayed due to customs inspection'
});

// Two timeline events created:
// 1. shipment.in_transit
// 2. shipment.eta_delayed
//    - Title: "Delivery Delayed"
//    - Description: "Delivery delayed by 2 days. New estimated delivery: 2026-09-27"
```

### Workflow 5: Out for Delivery

```javascript
await updateShipmentStatus(orderId, {
  status: 'out_for_delivery',
  location_data: {
    city: 'Cairo',
    driver: 'Ahmed Mohamed',
    vehicle: 'Van-007'
  },
  notes: 'Out for delivery'
});

// Timeline event created:
// - Type: shipment.out_for_delivery
// - Title: "Out for Delivery"
// - Description: "Your package is out for delivery today."
```

### Workflow 6: Delivered

```javascript
await updateShipmentStatus(orderId, {
  status: 'delivered',
  location_data: {
    recipient_name: 'John Doe',
    signature: 'Signed by customer'
  },
  notes: 'Successfully delivered'
});

// Timeline event created:
// - Type: shipment.delivered
// - Title: "Delivered"
// - Description: "Your package has been delivered successfully."

// Note: Order model's actual_delivery_at is automatically set to now()
```

### Workflow 7: Failed Delivery

```javascript
await updateShipmentStatus(orderId, {
  status: 'failed_delivery',
  location_data: {
    reason: 'Customer not available',
    next_attempt: '2026-09-26'
  },
  notes: 'Customer not home, will retry tomorrow'
});

// Timeline event created:
// - Type: shipment.failed_delivery
// - Title: "Delivery Failed"
// - Description: "Delivery attempt failed. DHL Express will retry."
```

---

## Best Practices

### 1. Status Progression

Follow logical status progression:

```
pending → label_created → picked_up → in_transit → out_for_delivery → delivered
```

### 2. Always Include Tracking Number

Once assigned, always include the tracking number in subsequent updates:

```javascript
// ❌ Bad: Omitting tracking number
await updateShipmentStatus(orderId, {
  status: 'in_transit'
});

// ✅ Good: Including tracking number
await updateShipmentStatus(orderId, {
  status: 'in_transit',
  tracking_number: 'TRK123456789',
  courier_name: 'DHL Express'
});
```

### 3. Update ETA Proactively

Update estimated delivery date as soon as delays are known:

```javascript
// Don't wait until delivery fails
// Update ETA when delay is anticipated
await updateShipmentStatus(orderId, {
  status: 'in_transit',
  estimated_delivery_at: '2026-09-27', // Updated ETA
  notes: 'Customs clearance taking longer than expected'
});
```

### 4. Use Location Data for Tracking Checkpoints

Provide detailed location updates:

```javascript
await updateShipmentStatus(orderId, {
  status: 'in_transit',
  location_data: {
    checkpoint: 'Alexandria Distribution Center',
    city: 'Alexandria',
    country: 'Egypt',
    timestamp: '2026-09-23T14:30:00Z',
    latitude: 31.2001,
    longitude: 29.9187
  }
});
```

### 5. Error Handling

Always handle errors gracefully:

```javascript
async function safeUpdateShipment(orderId, data) {
  try {
    const result = await updateShipmentStatus(orderId, data);
    return { success: true, data: result };
  } catch (error) {
    if (error.response?.status === 422) {
      // Validation error
      return {
        success: false,
        errors: error.response.data.errors,
        message: 'Validation failed'
      };
    } else if (error.response?.status === 404) {
      // Order not found
      return {
        success: false,
        message: 'Order not found'
      };
    } else {
      // Other errors
      return {
        success: false,
        message: error.message || 'Failed to update shipment'
      };
    }
  }
}
```

### 6. Batch Updates

If updating multiple orders, use Promise.all with error handling:

```javascript
const orderIds = [1, 2, 3, 4, 5];

const results = await Promise.allSettled(
  orderIds.map(orderId => 
    updateShipmentStatus(orderId, {
      status: 'picked_up',
      tracking_number: `TRK${orderId}`,
      courier_name: 'DHL Express'
    })
  )
);

results.forEach((result, index) => {
  if (result.status === 'fulfilled') {
    console.log(`Order ${orderIds[index]}: Success`);
  } else {
    console.error(`Order ${orderIds[index]}: Failed`, result.reason);
  }
});
```

---

## Timeline Event Details

### What Gets Recorded

Every status update creates a timeline event with:

```php
[
    'order_id' => $order->id,
    'event_type' => 'shipment.{status}',
    'event_timestamp' => now(),
    'actor_type' => 'courier',
    'actor_name' => $courierName ?? 'Courier',
    'old_status' => $oldStatus,
    'new_status' => $newStatus,
    'metadata' => [
        'tracking_number' => $trackingNumber,
        'courier_name' => $courierName,
        'location_data' => $locationData,
        'estimated_delivery_at' => $estimatedDelivery,
        'notes' => $notes,
        'changed_at' => now()->toIso8601String(),
    ],
    'customer_visible' => true,
    'customer_label_key' => 'tracking.shipment.{status}',
    'customer_description_key' => 'tracking.shipment.{status}_description',
    'source' => 'courier',
    'ip_address' => request()->ip(),
]
```

### ETA Change Events

When ETA changes:

```php
[
    'order_id' => $order->id,
    'event_type' => $isDelayed ? 'shipment.eta_delayed' : 'shipment.eta_updated',
    'event_timestamp' => now(),
    'actor_type' => 'courier',
    'actor_name' => $order->courier_name ?? 'Courier',
    'old_status' => $oldEta->format('Y-m-d'),
    'new_status' => $newEta->format('Y-m-d'),
    'metadata' => [
        'old_eta' => $oldEta->toIso8601String(),
        'new_eta' => $newEta->toIso8601String(),
        'reason' => $reason,
        'is_delayed' => $isDelayed,
        'delay_days' => $delayDays,
        'changed_at' => now()->toIso8601String(),
    ],
    'customer_visible' => true,
    'customer_label_key' => $isDelayed 
        ? 'tracking.shipment.eta_delayed' 
        : 'tracking.shipment.eta_updated',
    'customer_description_key' => $isDelayed 
        ? 'tracking.shipment.eta_delayed_description' 
        : 'tracking.shipment.eta_updated_description',
    'source' => 'courier',
]
```

---

## Testing

### Manual Testing

```bash
# Get shipment details
curl -X GET "http://localhost:8000/api/v1/admin/orders/1/shipment" \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN"

# Update status to picked_up
curl -X POST "http://localhost:8000/api/v1/admin/orders/1/shipment/update-status" \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "status": "picked_up",
    "tracking_number": "TRK123456789",
    "courier_name": "DHL Express",
    "estimated_delivery_at": "2026-09-25"
  }'

# Update to in_transit with location
curl -X POST "http://localhost:8000/api/v1/admin/orders/1/shipment/update-status" \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "status": "in_transit",
    "tracking_number": "TRK123456789",
    "location_data": {
      "city": "Alexandria",
      "checkpoint": "Alexandria Hub"
    }
  }'

# Mark as delivered
curl -X POST "http://localhost:8000/api/v1/admin/orders/1/shipment/update-status" \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "status": "delivered",
    "tracking_number": "TRK123456789"
  }'
```

### Verify Timeline

After each update, verify the timeline was recorded:

```bash
# View order tracking timeline
curl -X GET "http://localhost:8000/api/v1/general/orders/1/track" \
  -H "Authorization: Bearer USER_TOKEN"
```

---

## Troubleshooting

### Issue 1: Events Not Recording

**Symptom**: Shipment status updates but no timeline events created

**Check**:
1. Verify EventServiceProvider has listeners registered
2. Run `php artisan event:list | grep Shipment`
3. Check application logs for errors
4. Verify database transaction completed successfully

### Issue 2: ETA Not Updating

**Symptom**: EstimatedDeliveryChanged event not firing

**Cause**: ETA value matches current ETA (no change detected)

**Solution**: Ensure new ETA differs from current order ETA

### Issue 3: Validation Errors

**Symptom**: 422 response with validation errors

**Common Issues**:
- Invalid status enum value
- Date format incorrect (use Y-m-d)
- Required field missing

**Solution**: Check validation rules and fix request payload

---

## Security Considerations

1. **Authentication Required**: All endpoints require admin authentication
2. **Authorization**: Middleware enforces admin role
3. **Rate Limiting**: `throttle:admin` prevents abuse
4. **Input Validation**: All inputs validated and sanitized
5. **Transaction Safety**: Database transactions ensure atomicity
6. **IP Tracking**: All updates log IP address for audit trail

---

## Changelog

### [1.0.0] - 2026-09-21

#### Added
- Admin shipment update endpoint
- Admin shipment details endpoint
- ShipmentStatusChanged event emission
- EstimatedDeliveryChanged event emission
- Automatic timeline recording
- Transaction-wrapped updates
- Location data tracking
- ETA delay detection
- IP address logging

---

## Support

For integration support:
- Review ShipmentController source code
- Check EventServiceProvider for event mappings
- Review translation keys in `resources/lang/{locale}/tracking.php`
- Check OrderTrackingEvent model structure
