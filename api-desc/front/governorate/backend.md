# Governorate Module — Backend Documentation

---

### GET /api/v1/general/governorates

**Purpose:** Public endpoint returning active governorates for checkout delivery address dropdown.

**Authentication:** None (public — no middleware)

**Execution Flow:**
```
Client
  ↓
GET /api/v1/general/governorates
  ↓
App\Http\Controllers\Api\General\GovernorateController@index
  ↓
Marvel\Database\Repositories\GovernorateRepository::allActive()
  ↓
Marvel\Database\Models\Governorate::query()->active()->orderByDesc('id')->get()
  ↓
Marvel\Http\Resources\GovernorateResource::collection()
  ↓
JSON Response
```

**Database:**
- Table: `governorates`
- Query: `SELECT * FROM governorates WHERE status = 1 ORDER BY id DESC`
- No joins (lightweight query)

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| id | int | Governorate ID (send as `governorate_id` in checkout) |
| country_id | int | Country this governorate belongs to |
| name | string | Translated name (current locale) |
| status | bool | Whether governorate is active |
| is_fast_shipping_enabled | bool | Whether fast shipping is available |
| created_at | string | ISO 8601 timestamp |
