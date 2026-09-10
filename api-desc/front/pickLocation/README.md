# Pickup Location Module — Frontend (Public API)

## Overview

The Pickup Location module manages physical store/branch locations where customers can pick up their orders. Endpoints return only active locations ordered by display priority.

## Key Files

| Layer | File |
|-------|------|
| Controller | `app/Http/Controllers/Api/General/PickupLocationController.php` |
| Service | `app/Services/General/PickupLocationService.php` |
| Resource | `app\Http\Resources\PickupLocation\PickupLocationResource.php` |
| Model | `Marvel\Database\Models\PickupLocation.php` |
| Routes | `routes/api.php` (lines 69-70) |

## Routes

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/api/v1/general/pickup-locations` | Public | List active pickup locations |
| GET | `/api/v1/general/pickup-locations/{id}` | Public | Get single pickup location by ID |

## Dependencies

- **SoftDeletes** — locations soft-deleted, not returned
- **PickupLocationResource** — response transformation
- **PickupLocationService** — query builder with active filter and search
