# Order Flow API

## 1. How the Flow Works

```text
Admin creates Flow
        ↓
Admin selects shipping_type (local | international)
        ↓
Admin adds statuses (order = lifecycle order)
        ↓
Admin adds required inputs
        ↓
Flow becomes active
        ↓
Frontend calls available endpoint
        ↓
Frontend receives local/international + inputs
        ↓
Customer selects shipping_type
        ↓
Frontend sends shipping_type + flow_values
        ↓
Backend resolves the correct Flow
        ↓
Order is created and pinned to that Flow
```

### shipping_type

Controlled values: `local` | `international`. This is the business
identity the customer chooses. Not admin-creatable.

### Flow

Backend resolves `shipping_type → active Flow → flow_id`. The customer
never sends `flow_id`. `code` is only a label. One active Flow per
shipping type.

### Status

Statuses attach to a Flow in order (`sort_order`). The order IS the
lifecycle: position N moves to N+1, plus supervised exits
(`completed`, `cancelled`, `failed_delivery`, `returned`).

### Flow Input

Inputs tell the frontend what extra data to collect (e.g. origin
country) and when (`checkout` or a specific status transition).

## 2. Admin APIs

Auth: `auth:sanctum`. Permissions per endpoint below.

### GET /api/v1/admin/order-flows

What it does:
Lists flows with their ordered statuses. Filters narrow the list.

Request:
```json
{
  "shipping_type": "local",
  "is_active": true,
  "search": "local",
  "per_page": 50
}
```

Response:
```json
{
  "status": 200,
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "code": "local",
        "name": { "en": "Local Flow", "ar": "المسار المحلي" },
        "shipping_type": "local",
        "is_default": true,
        "is_active": true,
        "statuses": [
          { "id": 1, "code": "pending", "name": { "en": "Pending", "ar": "قيد الانتظار" }, "is_active": true, "sort_order": 1 }
        ]
      }
    ],
    "meta": { "current_page": 1, "per_page": 50, "total": 2 }
  }
}
```

Next:
Open one flow to see its inputs.

### POST /api/v1/admin/order-flows

What it does:
Creates a Flow for one shipping type. Needs
`create-order-flows` permission.

Request:
```json
{
  "code": "international",
  "name": { "en": "International Flow", "ar": "المسار الدولي" },
  "shipping_type": "international",
  "is_default": true,
  "is_active": true,
  "status_ids": [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]
}
```

Response:
```json
{
  "status": 201,
  "success": true,
  "data": {
    "id": 2,
    "code": "international",
    "name": { "en": "International Flow", "ar": "المسار الدولي" },
    "shipping_type": "international",
    "is_default": true,
    "is_active": true,
    "statuses": []
  }
}
```

Next:
Add inputs via `POST .../{id}/inputs`.

### GET /api/v1/admin/order-flows/{id}

What it does:
Returns one Flow with ordered statuses plus ordered `inputs[]`.

Request:
```json
{}
```

Response:
```json
{
  "status": 200,
  "success": true,
  "data": {
    "id": 2,
    "code": "international",
    "shipping_type": "international",
    "statuses": [],
    "inputs": []
  }
}
```

Next:
Edit the Flow or manage its inputs.

### PUT /api/v1/admin/order-flows/{id}

What it does:
Updates name, code, flags, or status ordering. Needs
`update-order-flows` permission.

Request:
```json
{
  "name": { "en": "International Flow", "ar": "المسار الدولي" },
  "is_active": true,
  "status_ids": [1, 2, 3]
}
```

Response:
```json
{
  "status": 200,
  "success": true,
  "data": {}
}
```

Next:
Verify via `GET {id}`.

`shipping_type` cannot be changed. Removing statuses used by in-flight
orders → 422. Deactivating the last active flow per type → 422. No
DELETE endpoint exists (deactivate instead).

### GET /api/v1/admin/order-statuses

What it does:
Lists the global status catalog (the vocabulary flows pick from).

Request:
```json
{
  "search": "customs",
  "is_active": true,
  "per_page": 50
}
```

Response:
```json
{
  "status": 200,
  "success": true,
  "data": {
    "data": [
      { "id": 7, "code": "customs_clearance", "name": { "en": "Customs Clearance", "ar": "التخليص الجمركي" }, "description": "Shipment under customs inspection", "is_active": true }
    ],
    "meta": { "current_page": 1, "per_page": 50, "total": 22 }
  }
}
```

Next:
Assign statuses to a flow via `PUT order-flows/{id}`.

Catalog = what codes exist. Membership = which codes a flow uses, in
which order. They are different things.

### PUT /api/v1/admin/order-statuses/{id}

What it does:
Renames or activates/deactivates a catalog status. `code` never
changes. No POST/DELETE for statuses.

Request:
```json
{
  "name": { "en": "Customs Clearance", "ar": "التخليص الجمركي" },
  "is_active": true
}
```

Response:
```json
{
  "status": 200,
  "success": true,
  "data": { "id": 7, "code": "customs_clearance", "name": { "en": "Customs Clearance", "ar": "التخليص الجمركي" }, "is_active": true }
}
```

Next:
Use it in a flow via `status_ids`.

### GET /api/v1/admin/order-flows/{flowId}/inputs

What it does:
Lists a flow's input definitions ordered by `sort_order`.

Request:
```json
{
  "is_active": true
}
```

Response:
```json
{
  "status": 200,
  "success": true,
  "data": [
    {
      "id": 1,
      "flow_id": 2,
      "key": "from_country",
      "label": { "en": "Country of origin", "ar": "بلد المنشأ" },
      "type": "select",
      "source": "countries",
      "required": true,
      "required_at": "checkout",
      "sort_order": 1,
      "validation": null,
      "is_active": true
    }
  ]
}
```

Next:
Bulk-create more inputs or edit one.

### POST /api/v1/admin/order-flows/{flowId}/inputs

What it does:
Creates many input definitions in one atomic request. All-or-nothing.

Request:
```json
{
  "inputs": [
    {
      "key": "from_country",
      "label": { "en": "Origin country", "ar": "بلد المنشأ" },
      "placeholder": { "en": "Select origin", "ar": "اختر بلد المنشأ" },
      "type": "select",
      "source": "countries",
      "required": true,
      "required_at": "checkout"
    },
    {
      "key": "customs_reference",
      "label": { "en": "Customs reference", "ar": "المرجع الجمركي" },
      "type": "text",
      "required": true,
      "required_at": "transition:customs_clearance",
      "validation": { "min": 3, "max": 100 }
    }
  ]
}
```

Response:
```json
{
  "status": 201,
  "success": true,
  "data": []
}
```

Next:
Check the public schema via the available endpoint.

| Field | Meaning |
|---|---|
| key | Stable identifier, immutable after create |
| label/placeholder/help_text | Bilingual `{en, ar}` display text |
| type | `text` `number` `boolean` `date` `select` `multi_select` |
| source | Option catalog: `countries` `governorates` `warehouses` `pickup_locations` |
| required | Whether it must be provided |
| required_at | `checkout` or `transition:<status_code>` |
| sort_order | Display order (auto-allocated if omitted) |
| validation | `options[]`, `min`, `max`, `pattern` |
| is_active | Retired when false (ignored, never demanded) |

### PUT /api/v1/admin/order-flow-inputs/{id}

What it does:
Edits one input. `key` cannot be renamed. Unrequiring/deactivating a
REQUIRED input with in-flight orders → 422.

Request:
```json
{
  "label": { "en": "Customs ref.", "ar": "المرجع الجمركي" },
  "is_active": true
}
```

Response:
```json
{
  "status": 200,
  "success": true,
  "data": {}
}
```

Next:
Verify the public schema.

### DELETE /api/v1/admin/order-flow-inputs/{id}

What it does:
Deletes one input. Deleting a REQUIRED input with in-flight orders
→ 422.

Request:
```json
{}
```

Response:
```json
{
  "status": 200,
  "success": true,
  "message": "Flow input deleted successfully."
}
```

Next:
Verify the public schema.

## 3. Frontend APIs

### GET /api/v1/general/order-flows/available

What it does:
Returns every ACTIVE flow. Guest-safe, no auth. This is the canonical
discovery endpoint.

Request:
```json
{}
```

Response:
```json
{
  "status": 200,
  "success": true,
  "data": {
    "flows": [
      {
        "shipping_type": "local",
        "code": "local",
        "name": { "en": "Local Flow", "ar": "المسار المحلي" },
        "statuses": [
          { "code": "pending", "name": { "en": "Pending", "ar": "قيد الانتظار" }, "sort_order": 1 }
        ],
        "inputs": []
      },
      {
        "shipping_type": "international",
        "code": "international",
        "name": { "en": "International Flow", "ar": "المسار الدولي" },
        "statuses": [],
        "inputs": [
          {
            "key": "from_country",
            "label": { "en": "Country of origin", "ar": "بلد المنشأ" },
            "placeholder": { "en": "Select origin", "ar": "اختر بلد المنشأ" },
            "help_text": { "en": "Where the shipment starts.", "ar": "من أين تبدأ الشحنة." },
            "type": "select",
            "source": "countries",
            "required": true,
            "required_at": "checkout",
            "sort_order": 1,
            "validation": null
          }
        ]
      }
    ]
  }
}
```

Next:
Let the customer pick a shipping type, then render its inputs.

Frontend uses shipping_type to determine which Flow the customer
selected. Frontend uses inputs to build the required UI. No `id`,
`flow_id`, or admin flags are exposed.

### GET /api/v1/general/order-flows/by-shipping-type/{type}

What it does:
Returns one flow using the same sanitized contract. Needs
`auth:sanctum`. `{type}` = `local` or `international`.

Request:
```json
{}
```

Response:
```json
{
  "status": 200,
  "success": true,
  "data": {
    "shipping_type": "international",
    "code": "international",
    "name": { "en": "International Flow", "ar": "المسار الدولي" },
    "statuses": [],
    "inputs": []
  }
}
```

Errors: unknown type or no active flow → 422.

Next:
Checkout with the selected shipping type.

`type = select` + `source = countries` means: display a country
selector using the existing countries endpoint; send the selected
country id. `type = multi_select` + `validation.options = [...]`
means: allow multiple values, only from those options. `required_at =
checkout` means: needed at checkout. `required_at =
transition:<code>` means: needed later when moving to that status.

## 4. Checkout

### POST /api/v1/general/checkout

What it does:
Creates the order and resolves its Flow. Needs `auth:sanctum`.

Request:
```json
{
  "shipping_type": "international",
  "flow_values": {
    "from_country": 1,
    "to_country": 2
  }
}
```

(Plus normal checkout fields: name, phone, address, governorate,
payment method. Full field list lives in the checkout docs; only
`shipping_type` + `flow_values` belong to Order Flow.)

Response:
```json
{
  "status": 200,
  "success": true,
  "data": { "order_id": 101 }
}
```

Online payments return `{ "url": "..." }` instead.

Next:
Read the order to show its status.

```text
Frontend sends shipping_type.
        ↓
Backend validates shipping_type.
        ↓
Backend resolves active Flow.
        ↓
Backend validates flow_values against that Flow.
        ↓
Order is created with the resolved Flow.
```

Customer sends: `shipping_type`. Backend determines: `flow_id`.
Order stores: `shipping_type`, `flow_id`, `current_status_id`.
Existing orders never re-resolve. Retrying a pending order with a
different shipping type → 422, nothing changes.

## 5. Order Response

Customer order detail exposes (no internal ids, no admin flags):

```json
{
  "shipping_type": "international",
  "flow": {
    "code": "international",
    "name": { "en": "International Flow", "ar": "المسار الدولي" },
    "shipping_type": "international",
    "statuses": [
      { "code": "pending", "name": { "en": "Pending", "ar": "قيد الانتظار" }, "sort_order": 1 }
    ]
  },
  "current_status": {
    "code": "processing",
    "name": { "en": "Processing", "ar": "قيد التجهيز" },
    "sort_order": 2
  },
  "payment_status": "pending",
  "fulfillment_status": "pending"
}
```

List rows carry the same shape minus `flow.statuses` (kept `null`).
`payment_status` / `fulfillment_status` are included as a short note;
full payment/shipment detail lives in their own endpoints.

### GET /api/v1/orders/{id}/statuses

What it does:
Tells the client which status transitions are currently available for
this order. Needs owner or view permission. Advisory only.

Request:
```json
{}
```

Response:
```json
{
  "status": 200,
  "success": true,
  "data": {
    "current_status": { "code": "pending", "name": { "en": "Pending", "ar": "قيد الانتظار" } },
    "flow": { "code": "local", "shipping_type": "local" },
    "statuses": [
      {
        "code": "processing",
        "name": { "en": "Processing", "ar": "قيد التجهيز" },
        "sort_order": 2,
        "transition_allowed": true,
        "permitted": true,
        "allowed": true,
        "permission": "change-order-status.processing",
        "reason": null,
        "requires_inputs": []
      }
    ]
  }
}
```

Next:
Send the chosen status to the mutation endpoint.

This endpoint tells the client which status transitions are currently
available. The backend validates the transition again when the status
is changed.

### PATCH /api/v1/orders/status

What it does:
Changes status for one or many orders (max 50). Staff only:
`update-order-status` AND `change-order-status.<target>`.

Request:
```json
{
  "order_ids": [101],
  "status": "processing",
  "flow_values": {}
}
```

Response:
```json
{
  "status": 200,
  "success": true,
  "data": {
    "summary": { "total": 1, "succeeded": 1, "failed": 0 },
    "results": [
      { "order_id": 101, "success": true, "status": "processing", "current_status": { "code": "processing", "sort_order": 2 } }
    ]
  }
}
```

Next:
Read the order detail for the new status.

Backend checks that the target status belongs to the Order's pinned
Flow. Backend validates required Flow Inputs. Backend then changes
the Order status. Legacy `PATCH /api/v1/orders/{id}/status` delegates
to this same pipeline with its historical response shape.

## 6. Complete Example

Admin creates Flow `International Shipping`, `shipping_type`
`international`, statuses `pending → processing → packed → shipped →
in_transit → arrived_at_destination_country → customs_clearance →
customs_cleared → local_carrier → out_for_delivery → delivered`,
inputs `from_country`, `to_country`, `customs_reference`.

### Step 1 — Admin creates Flow

```http
POST /api/v1/admin/order-flows
```

Request:
```json
{
  "code": "international",
  "name": { "en": "International Flow", "ar": "المسار الدولي" },
  "shipping_type": "international",
  "is_default": true,
  "is_active": true,
  "status_ids": [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]
}
```

Response: `201` with the created flow resource.

### Step 2 — Admin adds Inputs

```http
POST /api/v1/admin/order-flows/2/inputs
```

Request:
```json
{
  "inputs": [
    { "key": "from_country", "label": { "en": "Origin country", "ar": "بلد المنشأ" }, "type": "select", "source": "countries", "required": true, "required_at": "checkout" },
    { "key": "to_country", "label": { "en": "Destination country", "ar": "بلد الوجهة" }, "type": "select", "source": "countries", "required": true, "required_at": "checkout" },
    { "key": "customs_reference", "label": { "en": "Customs reference", "ar": "المرجع الجمركي" }, "type": "text", "required": true, "required_at": "transition:customs_clearance", "validation": { "min": 3, "max": 100 } }
  ]
}
```

Response: `201` with the created collection.

### Step 3 — Frontend discovers Flow

```http
GET /api/v1/general/order-flows/available
```

Response: `{flows: [local, international]}` with statuses + inputs.

### Step 4 — Customer selects International

Frontend stores: `shipping_type = international`. Renders country
selectors for `from_country` / `to_country`.

### Step 5 — Checkout

```http
POST /api/v1/general/checkout
```

Request:
```json
{
  "shipping_type": "international",
  "flow_values": { "from_country": 1, "to_country": 2 }
}
```

Response: `200` with `{order_id}` (or `{url}` for online payment).

### Step 6 — Order

```json
{
  "shipping_type": "international",
  "flow": { "code": "international", "name": { "en": "International Flow", "ar": "المسار الدولي" } },
  "current_status": { "code": "pending", "name": { "en": "Pending", "ar": "قيد الانتظار" }, "sort_order": 1 },
  "payment_status": "pending",
  "fulfillment_status": "pending"
}
```

## 7. Endpoint Summary

| Actor | Method | Endpoint | What it does | Next |
|---|---|---|---|---|
| Admin | GET | /api/v1/admin/order-flows | List flows | Show flow |
| Admin | POST | /api/v1/admin/order-flows | Create Flow | Add inputs/statuses |
| Admin | GET | /api/v1/admin/order-flows/{id} | Flow detail + inputs | Update |
| Admin | PUT | /api/v1/admin/order-flows/{id} | Update Flow | Verify |
| Admin | GET | /api/v1/admin/order-statuses | Status catalog | Assign to flow |
| Admin | PUT | /api/v1/admin/order-statuses/{id} | Edit status | Use in flow |
| Admin | GET | /api/v1/admin/order-flows/{flowId}/inputs | List inputs | Create/edit |
| Admin | POST | /api/v1/admin/order-flows/{flowId}/inputs | Add inputs | Frontend discovery |
| Admin | PUT | /api/v1/admin/order-flow-inputs/{id} | Edit input | Verify |
| Admin | DELETE | /api/v1/admin/order-flow-inputs/{id} | Delete input | Verify |
| Frontend | GET | /api/v1/general/order-flows/available | Get available Flows | Select shipping_type |
| Frontend | GET | /api/v1/general/order-flows/by-shipping-type/{type} | Get one Flow | Checkout |
| Customer | POST | /api/v1/general/checkout | Checkout using Flow | Order |
| Customer | GET | /api/v1/general/orders/{id} | Read current Flow/Status | Continue order |
| Customer | GET | /api/v1/orders/{id}/statuses | Available transitions | Change status |
| Staff/Admin | PATCH | /api/v1/orders/status | Change status | Next status |
