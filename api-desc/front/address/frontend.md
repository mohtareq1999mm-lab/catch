# Frontend — Address Feature

## Status

**No dedicated frontend Vue/React components** found in `resources/js/`. The frontend is a separate SPA.

## Consumption Patterns

### 1. Address List

```
GET /api/v1/address

Response:
{
    "message": "MESSAGE.ADDRESS_FOUND",
    "status": 200,
    "success": true,
    "data": [
        {
            "id": 1,
            "title": "Home",
            "type": "shipping",
            "default": true,
            "address": {
                "street_address": "123 Main St",
                "city": "Cairo",
                "state": "Cairo Governorate",
                "zip": "11511",
                "country": "Egypt"
            },
            "location": {
                "latitude": 30.0444,
                "longitude": 31.2357
            },
            "customer_id": 10,
            "created_at": "2026-07-26T10:00:00.000000Z"
        }
    ]
}
```

### 2. Create Address

```
POST /api/v1/address
Content-Type: application/json

Request Body:
{
    "title": "Home",
    "type": "shipping",
    "default": "1",
    "address": {
        "street_address": "123 Main St",
        "city": "Cairo",
        "state": "Cairo Governorate",
        "zip": "11511",
        "country": "Egypt"
    },
    "location": {
        "latitude": 30.0444,
        "longitude": 31.2357
    }
}

Response (201): { "message": "MESSAGE.COULD_NOT_CREATE_THE_RESOURCE", "status": 201, "success": true, "data": { ... } }
```

### 3. Get Address

```
GET /api/v1/address/1

Response (200): { "message": "MESSAGE.ADDRESS_FOUND", "status": 200, "success": true, "data": { ... } }
Response (404): { "message": "ERROR.ADDRESS_NOT_FOUND", "status": 404, "success": false }
```

### 4. Update Address

```
PUT /api/v1/address/1
Content-Type: application/json

Request Body:
{
    "title": "Home (Updated)",
    "type": "billing",
    "default": "1",
    "address": {
        "street_address": "456 New St",
        "city": "Cairo",
        "state": "Cairo Governorate",
        "zip": "11511",
        "country": "Egypt"
    },
    "location": {
        "latitude": 30.0444,
        "longitude": 31.2357
    }
}

Response (200): { "message": "MESSAGE.ADDRESS_UPDATED", "status": 200, "success": true, "data": { ... } }
```

### 5. Delete Address

```
DELETE /api/v1/address/1

Response (200): { "message": "MESSAGE.ADDRESS_DELETED", "status": 200, "success": true }
Response (404): { "message": "ERROR.ADDRESS_NOT_FOUND", "status": 404, "success": false }
```

## What a Frontend Implementation Would Need

### Components

```
AddressList.vue
  Fetches: GET /api/v1/address
  Displays: Address cards with title, type badge, address details, action buttons
  Features:
    - Edit button → opens AddressForm in edit mode
    - Delete button → confirmation dialog
    - Default badge for default address
    - Empty state: "No addresses saved yet"

AddressForm.vue
  Props: address (object, for edit mode)
  Fields:
    - title (text input, required)
    - type (select: billing/shipping, required)
    - default (checkbox/toggle)
    - street_address (text input, required)
    - city (text input, required)
    - state (text input, required)
    - zip (text input, required)
    - country (text/select input, required)
    - latitude (number input, optional)
    - longitude (number input, optional)
  Validation: Show field-level 422 errors
  Submit: POST /api/v1/address (create) or PUT /api/v1/address/{id} (update)

AddressDeleteDialog.vue
  Props: address (object)
  Actions:
    - Confirm → DELETE /api/v1/address/{id}
    - Cancel
```

### API Service Layer

```javascript
// services/addressApi.js
export const addressApi = {
  list()                       // GET /api/v1/address
  show(id)                     // GET /api/v1/address/{id}
  create(data)                 // POST /api/v1/address
  update(id, data)             // PUT /api/v1/address/{id}
  delete(id)                   // DELETE /api/v1/address/{id}
}
```

### Error Handling

| Status | Handling |
|--------|----------|
| 200 | Success — render data |
| 401 | Redirect to login |
| 404 | Show "Address not found" |
| 422 | Display field-level validation errors on form |
| 500 | Show "Something went wrong" toast with retry |

### Edge Cases

- No addresses saved: Show empty state with "Add Address" CTA
- Address with no location: Show address fields only, hide map
- Deleting the last address: List becomes empty
- Network error on save: Show error toast, keep form data
