# Pickup Location Module — Frontend Integration Guide

---

### 1. GET /api/v1/general/pickup-locations — List Pickup Locations (Public)

**Purpose:** Fetch active pickup locations for rendering a store/branch selector during checkout.

**Authentication:** None (public)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 10 | Items per page |
| limit | int | 10 | Items per page |
| search | string | - | Search by store_name |
| page | int | 1 | Page number |

**Response 200:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "store_name": "Downtown Branch",
      "address": "123 Main St, City Center",
      "phone": "+1-555-0101",
      "email": "downtown@marvel.com",
      "latitude": 30.0444,
      "longitude": 31.2357,
      "working_hours": {
        "saturday": "09:00-21:00",
        "sunday": "09:00-21:00",
        "monday": "09:00-21:00",
        "tuesday": "09:00-21:00",
        "wednesday": "09:00-21:00",
        "thursday": "09:00-22:00",
        "friday": "closed"
      },
      "status": true,
      "display_order": 1
    }
  ]
}
```

---

### 2. GET /api/v1/general/pickup-locations/{id} — Show Pickup Location (Public)

**Purpose:** Get details of a single pickup location by ID.

**Authentication:** None (public)

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| id | integer | Pickup location ID |

**Response 200:** Single `PickupLocationResource` object.
**Response 404:** If not found or inactive.

---

## Frontend Usage

### Checkout Pickup Selector
Use `GET /api/v1/general/pickup-locations` to populate a dropdown or map-based pickup location selector during checkout.

### Store Details
Use `GET /api/v1/general/pickup-locations/{id}` to show detailed info (working hours, map) when a location is selected.

### State Handling

| State | Behavior |
|-------|----------|
| **Listing loading** | Skeleton cards or dropdown placeholder |
| **Listing empty** | "No pickup locations available" |
| **Listing error** | Hide or fall back to "Standard shipping only" |
| **Show loading** | Skeleton details |
| **Show not found** | Show "Location not available" |
| **Show error** | Fall back to listing view |

### Map Integration
Use `latitude` and `longitude` to render a map marker for the selected location. Provide "Get Directions" button using Google Maps / Apple Maps deep link.
