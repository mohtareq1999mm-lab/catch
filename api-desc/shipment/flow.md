# Request Flows — Shipment Module

## Flow 1: List Shipments

```
Client → GET /api/v1/shipments?status=in_transit&courier=DHL&from=2026-07-01&limit=15
         ↓
    [auth:sanctum] middleware → authenticate token
         ↓
    [permission:view-shipments] middleware → check Spatie permission
         ↓
    ShipmentController@index(Request)
         ↓
    ShipmentService::list($filters, 15)
         ↓
    Shipment::query()
      → with('order')
      → when('status') → where('status', 'in_transit')
      → when('courier') → where('courier', 'DHL')
      → when('from') → whereDate('created_at', '>=', '2026-07-01')
      → orderBy('created_at', 'desc')
      → paginate(min(15, 100))
         ↓
    Return: { status:200, message, success:true, data: { data[], pagination_meta } }
```

## Flow 2: Create Shipment

```
Client → POST /api/v1/shipments
         Body: { order_id: 101, courier: "DHL", shipping_cost: 50 }
         ↓
    [auth:sanctum] middleware
         ↓
    [permission:create-shipment] middleware
         ↓
    CreateShipmentRequest → validation rules:
      - order_id: required, integer, exists:orders,id
      - tracking_number: nullable, string, max:100, unique
      - courier: nullable, string, max:50
      - shipping_cost: nullable, numeric, min:0
      - ... (all other fields nullable)
         ↓
    Fail? → 422 with field errors
         ↓
    ShipmentController@store($request)
         ↓
    ShipmentService::create($request->validated())
         ↓
    DB::beginTransaction()
      ├─ $data['status'] = 'pending'
      ├─ Shipment::create($data)
      │    └─ Model boot: auto-generate uuid via Str::orderedUuid()
      └─ DB::commit()
         ↓
    Return: { status:201, message:"Shipment created successfully", success:true, data }
```

## Flow 3: Show Shipment by ID

```
Client → GET /api/v1/shipments/1
         ↓
    [auth:sanctum] → [permission:view-shipment]
         ↓
    ShipmentController@show(1)
         ↓
    ShipmentService::find(1)
         ↓
    Shipment::with('order')->findOrFail(1)
         ↓
    Found? → Return: { status:200, message, success:true, data }
    Not found? → ModelNotFoundException → Laravel exception page (not caught)
```

## Flow 4: Show Shipment by UUID

```
Client → GET /api/v1/shipments/uuid/550e8400-e29b-41d4-a716-446655440000
         ↓
    [auth:sanctum] → [permission:view-shipment]
         ↓
    ShipmentController@showByUuid($uuid)
         ↓
    ShipmentService::findByUuid($uuid)
         ↓
    Shipment::with('order')->where('uuid', $uuid)->firstOrFail()
         ↓
    Found? → Return: { status:200, message, success:true, data }
    Not found? → ModelNotFoundException → Laravel exception page (not caught)
```

## Flow 5: Update Shipment Details

```
Client → PUT /api/v1/shipments/1
         Body: { courier: "FedEx", tracking_number: "FX-001" }
         ↓
    [auth:sanctum] → [permission:update-shipment]
         ↓
    UpdateShipmentRequest → validation (unique tracking_number ignores current ID)
         ↓
    ShipmentController@update($request, 1)
         ↓
    ShipmentService::update(1, $request->validated())
         ↓
    Shipment::findOrFail(1)
         ↓
    $shipment->update(['courier' => 'FedEx', 'tracking_number' => 'FX-001'])
         ↓
    $shipment->fresh()
         ↓
    Return: { status:200, message:"Shipment updated successfully", success:true, data }
```

## Flow 6: Update Shipment Status

```
Client → PUT /api/v1/shipments/1/status
         Body: { status: "in_transit", notes: "Package picked up by courier" }
         ↓
    [auth:sanctum] → [permission:update-shipment]
         ↓
    UpdateShipmentStatusRequest → validation:
      - status: required, Enum(ShipmentStatus::class)
      - notes: nullable, string, max:2000
         ↓
    Fail? → 422 with field errors
         ↓
    ShipmentController@updateStatus($request, 1)
         ↓
    try:
      ShipmentService::updateStatus(1, 'in_transit', 'Package picked up')
         ↓
      DB::beginTransaction()
        ├─ Shipment::lockForUpdate()->findOrFail(1)    [pessimistic lock]
        ├─ State Machine Check:
        │    └─ canTransitionTo('in_transit')
        │         └─ pending → ['label_created', 'cancelled'] → NOT ALLOWED
        │         └─ label_created → ['picked_up', 'cancelled'] → NOT ALLOWED
        │         └─ picked_up → ['in_transit', 'cancelled'] → ALLOWED
        │
        ├─ If NOT allowed → throw RuntimeException("cannot transition")
        │
        ├─ If 'shipped'|'picked_up':
        │    └─ shipped_at = existing ?? now()
        │
        ├─ If 'delivered':
        │    └─ delivered_at = now()
        │
        ├─ $shipment->update(['status' => 'in_transit', 'notes' => 'Package picked up'])
        └─ DB::commit()
         ↓
      $shipment->fresh()
         ↓
      Return: { status:200, message:"Shipment status updated", success:true, data }

    catch RuntimeException:
      Return: { status:422, message:"Shipment 1 cannot transition from 'pending' to 'in_transit'", success:false }
```

## Status State Machine Diagram

```
                    ┌──────────┐
                    │ PENDING  │
                    └────┬─────┘
                    │         │
                 ┌──v──┐  ┌──v───────┐
                 │CANCELLED│ │LABEL_CREATED│
                 └───────┘  └─────┬──────┘
                              │         │
                        ┌─────v──┐  ┌──v───────┐
                        │PICKED_UP│  │CANCELLED │
                        └──┬──┬──┘  └──────────┘
                           │  │
                     ┌─────v──v──────┐
                     │   IN_TRANSIT  │◄───────┐
                     └───────┬───────┘        │
                             │         │      │
                       ┌─────v──────┐  ┌v──────v──┐
                       │OUT_FOR_DELIVERY│ │ DELAYED  │
                       └──┬──┬──────┘  └─────┬─────┘
                          │  │               │
              ┌───────────v──v───────┐  ┌────v─────┐
              │      DELIVERED      │  │ IN_TRANSIT│
              └──────────────────────┘  └──────────┘
                          │
              ┌───────────v───────────┐
              │  FAILED_DELIVERY     │
              └──┬──────────────┬────┘
                 │              │
          ┌──────v──────┐  ┌───v──────┐
          │OUT_FOR_DELIVERY│  │ RETURNED  │
          └─────────────┘  └──────────┘
```
