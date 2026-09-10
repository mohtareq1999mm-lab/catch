# Governorate Module — Frontend (Public API)

## Overview

The Governorate module manages governorates (administrative regions) for delivery address selection during checkout. Endpoints return only active governorates.

## Key Files

| Layer | File |
|-------|------|
| Controller | `app/Http/Controllers/Api/General/GovernorateController.php` |
| Repository | `Marvel\Database\Repositories\GovernorateRepository.php` |
| Resource | `Marvel\Http\Resources\GovernorateResource.php` |
| Model | `Marvel\Database\Models\Governorate.php` |
| Routes | `routes/api.php` |

## Routes

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/api/v1/general/governorates` | Public | List active governorates |

## Dependencies

- **GovernorateRepository** — `allActive()` scope
- **GovernorateResource** — response transformation
- **Governorate model** — `scopeActive()` for status filter
