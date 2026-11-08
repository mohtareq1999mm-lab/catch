# WMS Packing & Package Flow — Mobile Integration & QA Guide

This documentation covers the Warehouse Management System (WMS) Packing module lifecycle. It provides mobile developers and QA engineers with the exact state machines, endpoint specifications, sequence diagrams, and end-to-end use cases (happy paths, rollbacks, and validation guards).

---

## 1. Architectural Overview & State Machine

The packing module consists of two distinct but synchronized entities:

1. **`PackingTask` (Workflow State Machine):** Tracks warehouse labor, station allocation, supervisor verification, and recorded package physical dimensions.
   * Status lifecycle: `pending` ➔ `assigned` ➔ `packing` ➔ `verified`
2. **`Package` (Physical Custody & Parcel):** Tracks the physical container, carton barcode, and items packed inside.
   * Status lifecycle: `open` ➔ `sealed` (or `voided` prior to sealing)

```
[Fulfillment: picked]
        │
        ▼
   Create Task (pending)
        │
        ▼
   Assign to Station (assigned)
        │
        ▼
   Start Task (packing)  <──────────────┐ (Rollback via VOID)
        │                               │
        ├──────────────────────┐        │
        ▼                      ▼        │
   Open Package (open)    Add Items     │
        │                      │        │
        └──────────┬───────────┘        │
                   ▼                    │
            1. Verify Items             │
                   │                    │
                   ▼                    │
            2. Weigh Box (Pack) ────────┘
                   │
                   ▼
            3. Seal Package (sealed) ➔ [Point of No Return]
                   │
                   ▼
      [Fulfillment: ready_to_ship]
```

---

## 2. API Endpoints Reference

All requests require:
* `Authorization: Bearer <sanctum_token>`
* `Accept: application/json`
* `Content-Type: application/json`

| # | Action | HTTP Method | Endpoint | Required Permission |
|---|--------|-------------|----------|---------------------|
| 1 | **Create Task** | `POST` | `/api/v1/admin/fulfillments/{id}/create-packing-task` | `fulfillments.create-packing-task` |
| 2 | **Assign Station** | `POST` | `/api/v1/admin/packing-tasks/{id}/assign` | `packing-tasks.assign` |
| 3 | **Start Task** | `POST` | `/api/v1/admin/packing-tasks/{id}/start` | `packing-tasks.start` |
| 4 | **Create Package** | `POST` | `/api/v1/admin/packages` | `packages.create` |
| 5 | **Add Item to Package** | `POST` | `/api/v1/admin/packages/{id}/add-item` | `packages.add-item` |
| 6 | **Verify Packaging** | `POST` | `/api/v1/admin/packing-tasks/{id}/verify` | `packing-tasks.verify` |
| 7 | **Weigh & Complete (Pack)**| `POST` | `/api/v1/admin/packing-tasks/{id}/pack` | `packing-tasks.pack` |
| 8 | **Seal Package** | `POST` | `/api/v1/admin/packages/{id}/seal` | `packages.seal` |
| 9 | **Void Open Package** | `POST` | `/api/v1/admin/packages/{id}/void` | `packages.void` (`manage-fulfillment`) |
| 10| **Cancel Task** | `POST` | `/api/v1/admin/packing-tasks/{id}/cancel` | `packing-tasks.cancel` |

---

## 3. Comprehensive Use Cases

---

### Use Case 1: Standard Happy Path (Canonical Flow)

**Preconditions:**
* Fulfillment status is `picked`.
* Packing Station ID `5` is active and belongs to the same warehouse.

#### Step 1: Create Packing Task
* **Request:** `POST /api/v1/admin/fulfillments/101/create-packing-task`
* **Response (201 Created):**
```json
{
  "success": true,
  "message": "Packing task created successfully",
  "data": {
    "id": 42,
    "fulfillment_id": 101,
    "status": "pending",
    "packing_station_id": null,
    "assigned_to": null
  }
}
```

#### Step 2: Assign Task to Station and Worker
The authenticated caller becomes the assigned operator.
* **Request:** `POST /api/v1/admin/packing-tasks/42/assign`
```json
{
  "station_id": 5
}
```
* **Response (200 OK):**
```json
{
  "success": true,
  "message": "Packing task assigned successfully",
  "data": {
    "id": 42,
    "status": "assigned",
    "packing_station_id": 5,
    "assigned_to": 12
  }
}
```

#### Step 3: Start Packing Task
* **Request:** `POST /api/v1/admin/packing-tasks/42/start`
* **Response (200 OK):**
```json
{
  "success": true,
  "message": "Packing started successfully",
  "data": {
    "id": 42,
    "status": "packing",
    "started_at": "2026-11-08T10:00:00.000000Z"
  }
}
```

#### Step 4: Open a Package Container
* **Request:** `POST /api/v1/admin/packages`
```json
{
  "fulfillment_id": 101,
  "packing_task_id": 42,
  "notes": "Standard Box M"
}
```
* **Response (201 Created):**
```json
{
  "success": true,
  "message": "Package created successfully",
  "data": {
    "id": 88,
    "package_number": "PCK-20261108-A9B1C2",
    "fulfillment_id": 101,
    "status": "open",
    "barcode": null,
    "items": []
  }
}
```

#### Step 5: Pack Items Into Container
Repeat for all fulfillment items until `quantity == quantity_picked`.
* **Request:** `POST /api/v1/admin/packages/88/add-item`
```json
{
  "fulfillment_item_id": 305,
  "quantity": 2
}
```
* **Response (200 OK):**
```json
{
  "success": true,
  "message": "Item added to package successfully",
  "data": {
    "id": 88,
    "status": "open",
    "items": [
      {
        "id": 12,
        "fulfillment_item_id": 305,
        "quantity": 2.0
      }
    ]
  }
}
```

#### Step 6: Verify Packaging (`verify`)
Supervisor confirms that 100% of picked items are inside the container while it is still open.
* **Request:** `POST /api/v1/admin/packing-tasks/42/verify`
```json
{
  "notes": "All items verified by Supervisor"
}
```
* **Response (200 OK):**
```json
{
  "success": true,
  "message": "Packing verified successfully",
  "data": {
    "id": 42,
    "status": "verified",
    "verified_at": "2026-11-08T10:05:00.000000Z"
  }
}
```

#### Step 7: Weigh and Measure Container (`pack`)
Enter gross weight and physical box dimensions.
* **Request:** `POST /api/v1/admin/packing-tasks/42/pack`
```json
{
  "weight": 3.45,
  "dimensions": {
    "length": 35.0,
    "width": 25.0,
    "height": 15.0
  },
  "materials": ["bubble_wrap", "tape"],
  "notes": "Weighed on Scale #2"
}
```
* **Response (200 OK):**
```json
{
  "success": true,
  "message": "Packing completed successfully",
  "data": {
    "id": 42,
    "status": "verified",
    "weight": "3.45",
    "dimensions": {
      "length": 35.0,
      "width": 25.0,
      "height": 15.0
    },
    "packed_at": "2026-11-08T10:07:00.000000Z"
  }
}
```

#### Step 8: Seal Package (`seal`) — Final Handoff
Seals the box, assigns permanent tracking barcode, and advances fulfillment.
* **Request:** `POST /api/v1/admin/packages/88/seal`
```json
{}
```
* **Response (200 OK):**
```json
{
  "success": true,
  "message": "Package sealed successfully",
  "data": {
    "id": 88,
    "package_number": "PCK-20261108-A9B1C2",
    "barcode": "PKG-20261108-994E8F21",
    "status": "sealed",
    "weight": "3.45",
    "dimensions": {
      "length": 35.0,
      "width": 25.0,
      "height": 15.0
    },
    "sealed_at": "2026-11-08T10:08:00.000000Z"
  }
}
```
* **System Side-Effect:** Fulfillment transitions automatically to `ready_to_ship`.

---

### Use Case 2: Rollback via VOID (After Verify / After Pack)

**Scenario:** Box is verified and weighed, but operator notices a damaged carton or wrong item before taping the box shut.

#### Step 1: Void the Open Package
* **Request:** `POST /api/v1/admin/packages/88/void`
```json
{
  "reason": "Carton torn during handling, transferring to new box"
}
```
* **Response (200 OK):**
```json
{
  "success": true,
  "message": "Package voided successfully",
  "data": {
    "id": 88,
    "status": "voided",
    "notes": "Carton torn during handling, transferring to new box"
  }
}
```
* **Automatic Rollback Triggered:**
  * `Package #88` is archived as `voided`.
  * `FulfillmentItems` status reverts to `picked`.
  * `PackingTask #42` rolls back:
    * `status` ➔ `packing`
    * `verified_at` ➔ `null`
    * `packed_at` ➔ `null`
    * `weight` ➔ `null`
    * `dimensions` ➔ `null`

#### Step 2: Open Replacement Package & Complete Clean Flow
1. Operator calls `POST /api/v1/admin/packages` ➔ Gets `Package #89` (`open`).
2. Operator calls `POST /api/v1/admin/packages/89/add-item` ➔ Adds items.
3. Supervisor calls `POST /api/v1/admin/packing-tasks/42/verify` ➔ Succeeds (`200 OK`).
4. Operator calls `POST /api/v1/admin/packing-tasks/42/pack` ➔ Succeeds (`200 OK`).
5. Operator calls `POST /api/v1/admin/packages/89/seal` ➔ Package sealed (`ready_to_ship`).

---

### Use Case 3: Re-weighing (Correction Before Seal)

**Scenario:** Weight was mistakenly entered as `0.45 kg` instead of `4.5 kg`. Box is still unsealed (`open`).

* **Action:** Operator calls `/pack` again with corrected values.
* **Request:** `POST /api/v1/admin/packing-tasks/42/pack`
```json
{
  "weight": 4.50,
  "dimensions": { "length": 35, "width": 25, "height": 15 },
  "notes": "Corrected scale calibration"
}
```
* **Response (200 OK):**
  * `weight` updated to `4.50`. Latest values overwrite safely because container is not sealed.

---

### Use Case 4: Point of No Return (Attempting Void After Seal)

**Scenario:** Box is already sealed (`status == sealed`). Operator attempts to void it.

* **Request:** `POST /api/v1/admin/packages/88/void`
```json
{
  "reason": "Try to cancel"
}
```
* **Response (422 Unprocessable Entity):**
```json
{
  "success": false,
  "message": "Cannot void package in status: sealed (only open packages may be voided)"
}
```
* **Rule:** Sealed custody cannot be destroyed at the packing station.

---

### Use Case 5: Attempting Re-weigh After Seal

**Scenario:** Operator tries to modify weight on a task whose package is already sealed.

* **Request:** `POST /api/v1/admin/packing-tasks/42/pack`
```json
{
  "weight": 9.99,
  "dimensions": { "length": 1 }
}
```
* **Response (422 Unprocessable Entity):**
```json
{
  "success": false,
  "message": "Cannot record weight: package is already sealed (no return after seal)"
}
```

---

### Use Case 6: Out-of-Order Execution Guards

The API rejects any attempt to skip or reverse mandatory gates:

| Attempted Sequence | Endpoint Called | HTTP Status | Error Message Key | Description |
|---|---|---|---|---|
| Call `pack` before `verify` | `POST .../pack` | `422` | `wms.packing.complete_unverified` | Cannot weigh unverified items |
| Call `seal` before `verify` | `POST .../seal` | `422` | `wms.packing.seal_unverified` | Verification required before seal |
| Call `seal` before `pack` | `POST .../seal` | `422` | `wms.packing.seal_unpacked` | Weight/dimensions mandatory |
| Verify with missing items | `POST .../verify` | `422` | `wms.packing.verify_quantities` | All picked items must be in package |
| Over-pack items | `POST .../add-item` | `422` | `wms.packing.overpack` | Exceeds `quantity_picked` |
| Create 2nd package | `POST /packages` | `409` | `wms.packing.active_package` | Single active package per fulfillment |

---

## 4. Mobile UI State Management Matrix

Guide for enabling/disabling buttons in the mobile packing screen:

| Current Task / Package State | "Add Item" Button | "Verify" Button | "Weigh (Pack)" Button | "Seal" Button | "Void Package" Button |
|---|---|---|---|---|---|
| `task: packing` & `pkg: open` (empty) | ✅ Enabled | ❌ Disabled | ❌ Disabled | ❌ Disabled | ✅ Enabled |
| `task: packing` & `pkg: open` (items complete) | ✅ Enabled | ✅ **Enabled** | ❌ Disabled | ❌ Disabled | ✅ Enabled |
| `task: verified` & `pkg: open` (unweighed) | ❌ Disabled | ❌ Disabled | ✅ **Enabled** | ❌ Disabled | ✅ Enabled (Triggers Rollback) |
| `task: verified` & `pkg: open` (weighed) | ❌ Disabled | ❌ Disabled | ✅ Enabled (Re-weigh) | ✅ **Enabled** | ✅ Enabled (Triggers Rollback) |
| `task: verified` & `pkg: sealed` | ❌ Disabled | ❌ Disabled | ❌ Disabled | ❌ Disabled | ❌ **Disabled (Locked)** |

---

## 5. Mobile Error Handling Best Practices

1. **Conflict (`409 Conflict`):**
   * Occurs if two operators attempt to assign the same task or open two packages simultaneously.
   * *Action:* Refresh task list and inform operator: *"This task was already claimed by another station."*
2. **Precondition Failure (`422 Unprocessable Entity`):**
   * Shows backend validation messages (available in both `ar` and `en` via `Accept-Language` header).
   * *Action:* Display server error directly in toast/snackbar.
3. **Barcode Scanning:**
   * After `/seal` succeeds, display the generated `barcode` (e.g., `PKG-20261108-994E8F21`) on screen for immediate thermal printing or manual barcode scanning verification.

