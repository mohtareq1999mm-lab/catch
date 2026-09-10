# Shipment Module

## Overview

The Shipment module manages order shipment tracking for the e-commerce platform. It provides a single authenticated API surface for managing the full shipment lifecycle from label creation through delivery.

**API Prefix:** `/api/v1/shipments` (all endpoints authenticated via `auth:sanctum`)

**Key Capabilities:**
- List shipments with filtering (by order, status, courier, tracking number, date range)
- Create shipments linked to orders
- Track shipment status with state machine validation
- Look up by primary ID or UUID
- Update shipment details (courier, tracking number, addresses, etc.)

## Key Files

| Layer | File |
|-------|------|
| Controller | `app/Http/Controllers/Api/ShipmentController.php` |
| Service | `app/Services/Shipment/ShipmentService.php` |
| Model | `app/Models/Shipment.php` |
| Enum | `app/Enums/ShipmentStatus.php` |
| Create Request | `app/Http/Requests/Shipment/CreateShipmentRequest.php` |
| Update Request | `app/Http/Requests/Shipment/UpdateShipmentRequest.php` |
| Status Request | `app/Http/Requests/Shipment/UpdateShipmentStatusRequest.php` |
| Migration | `database/migrations/2026_07_28_000004_create_shipments_table.php` |
| Routes | `routes/api.php` (lines 112-120) |
| Permissions | `packages/marvel/src/Enums/Permission.php` (lines 262-266) |

## Dependencies

- **Laravel Sanctum** — authentication
- **Spatie Permissions** — granular access control (4 permissions)
- **Order model** (`Marvel\Database\Models\Order`) — belongsTo relationship
- **UUID** — auto-generated ordered UUIDs for public-safe identifiers

## Permissions

| Permission | Required For |
|------------|-------------|
| `view-shipments` | GET /shipments |
| `view-shipment` | GET /shipments/{id}, GET /shipments/uuid/{uuid} |
| `create-shipment` | POST /shipments |
| `update-shipment` | PUT /shipments/{id}, PUT /shipments/{id}/status |

## Routes

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/shipments` | List shipments (paginated, filterable) |
| GET | `/api/v1/shipments/{id}` | Show shipment by ID |
| GET | `/api/v1/shipments/uuid/{uuid}` | Show shipment by UUID |
| POST | `/api/v1/shipments` | Create shipment for an order |
| PUT | `/api/v1/shipments/{id}` | Update shipment details |
| PUT | `/api/v1/shipments/{id}/status` | Update shipment status (state machine) |
