# Fixed: Order Status Filter Not Working

## What Happened

When fetching your orders with a status filter like `?status=pending` or `?status=completed`, the API was ignoring the filter entirely and returning all orders regardless of their status.

For example:
- `GET /api/v1/general/orders?status=cancelled` would return orders with status `pending`, `completed`, `processing`, etc.
- The `status` parameter was completely bypassed — you always got every order.

## What Was Fixed

The backend now correctly reads the `status` query parameter and applies it as a database filter. Only orders matching the requested status will be returned.

## How to Use

Simply add `?status={value}` to your request:

```
GET /api/v1/general/orders?status=pending
GET /api/v1/general/orders?status=completed&limit=15&page=1
GET /api/v1/general/orders?status=cancelled
```

### Valid Status Values

| Value | Description |
|-------|-------------|
| `pending` | Awaiting processing |
| `processing` | Being processed |
| `completed` | Completed |
| `cancelled` | Cancelled |
| `delivered` | Delivered |
| `refunded` | Refunded |
| `failed` | Failed |
| `at_local_facility` | At local facility |
| `out_for_delivery` | Out for delivery |
| `ready_for_pickup` | Ready for pickup |

No changes needed on the frontend — just pass the `status` parameter and it will now work correctly.
