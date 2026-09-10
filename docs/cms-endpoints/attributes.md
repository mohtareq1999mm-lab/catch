# Attributes & Attribute Values API

## Overview

The Attributes module manages product attribute definitions (e.g., Size, Color) and their values (e.g., S, M, L, XL). Attributes are used to define product variants and filterable product properties.

---

## Database Schema

### `attributes` Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | Unique identifier |
| `name` | json | NOT NULL | Translatable name (Spatie HasTranslations) |
| `slug` | varchar(255) | NOT NULL, UNIQUE | Auto-generated from English name |
| `created_at` | timestamp | NULLABLE | Creation time |
| `updated_at` | timestamp | NULLABLE | Last update |

### `attribute_values` Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | Unique identifier |
| `value` | json | NOT NULL | Translatable value (Spatie HasTranslations) |
| `slug` | varchar(255) | NOT NULL, UNIQUE | Auto-generated from value |
| `attribute_id` | bigint | FK → attributes.id, CASCADE | Parent attribute reference |
| `created_at` | timestamp | NULLABLE | Creation time |
| `updated_at` | timestamp | NULLABLE | Last update |

### `attribute_product` Pivot Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | Unique identifier |
| `product_variant_id` | bigint | FK → product_variants.id | Variant reference |
| `attribute_value_id` | bigint | FK → attribute_values.id | Attribute value reference |
| `created_at` | timestamp | NULLABLE | Creation time |
| `updated_at` | timestamp | NULLABLE | Last update |

---

## Response Envelope

All endpoints return:

```json
{
    "status": 200,
    "message": "Translated message string",
    "success": true,
    "data": {}
}
```

Pagination endpoints return pagination fields at the top level alongside `data`.

---

## Resource Structure

### AttributeResource

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Attribute ID |
| `name` | string/object | Translated string on `index`; raw JSON object on `show` |
| `slug` | string | URL slug |
| `values` | array | Attribute values (only when loaded) |

### AttributeValueResource

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Attribute value ID |
| `value` | string/object | Translated string on `index`; raw JSON object on `show` |
| `slug` | string | URL slug |
| `attribute_id` | int | Parent attribute ID |

**Translation Behavior:** Index endpoints return translated strings in the current application locale. Show endpoints return the raw original JSON object containing all translations. This behavior is automatic via Spatie HasTranslations and requires no query parameters.

---

## Route Structure

Routes are registered in two groups within `packages/marvel/src/Rest/Routes.php`:

### Public Group (controller permission middleware only)

| Method | Endpoint | Controller Method |
|--------|----------|-------------------|
| GET | `/attributes` | `index` |
| GET | `/attributes/{id}` | `show` |
| GET | `/attribute-values` | `index` |
| GET | `/attribute-values/{id}` | `show` |

### Protected Group (`auth:sanctum` + `email.verified`)

| Method | Endpoint | Controller Method |
|--------|----------|-------------------|
| POST | `/attributes` | `store` |
| PUT | `/attributes/{id}` | `update` |
| DELETE | `/attributes/{id}` | `destroy` |
| POST | `/attribute-values` | `store` |
| PUT | `/attribute-values/{id}` | `update` |
| DELETE | `/attribute-values/{id}` | `destroy` |

### Import/Export (auth:sanctum, no permission middleware)

| Method | Endpoint | Controller Method | Middleware |
|--------|----------|-------------------|------------|
| POST | `/import-attributes` | `importAttributes` | `auth:sanctum`, `throttle:uploads` |
| GET | `/export-attributes/{id}` | `exportAttributes` | `auth:sanctum` |

---

## Permissions Map

Permissions are enforced via controller middleware. Only the following permissions exist in middleware:

| Permission | Middleware Applied On | Controller Methods |
|------------|----------------------|-------------------|
| `view-attributes` | `AttributeController`, `AttributeValueController` | `index`, `show` |
| `create-attribute` | `AttributeController`, `AttributeValueController` | `store` |
| `update-attribute` | `AttributeController`, `AttributeValueController` | `update` |
| `delete-attribute` | `AttributeController`, `AttributeValueController` | `destroy` |

**No permission middleware is applied** to `importAttributes` or `exportAttributes`.

Permissions are enforced at the controller level via `$this->middleware` in each controller's constructor. The `store`, `update`, and `destroy` routes additionally sit behind an `auth:sanctum` + `email.verified` middleware group at the route level.

---

## Endpoints — Attributes

### GET /attributes — List Attributes

**Purpose:** List all attributes with their values. Supports pagination, sorting, and search.

**Method:** `GET`

**URL:** `/attributes`

**Authentication:** Required (via `view-attributes` permission middleware)

**Permissions:** `view-attributes`

**Query Parameters:**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `limit` | int | 15 | Items per page |
| `order` | string | — | Sort column (`id`, `name`, `slug`, `created_at`, `updated_at`) |
| `sortedBy` | `asc`/`desc` | `asc` | Sort direction |
| `search` | string | — | Search term (matches `name` via LIKE) |

**Example URLs:**
```
GET /attributes?limit=10
GET /attributes?search=size
GET /attributes?order=name&sortedBy=desc
GET /attributes?limit=20&order=created_at&sortedBy=desc
GET /attributes?search=color&limit=5
```

**Business Logic:**
1. Applies optional `order`/`sortedBy` sorting (validated against allowed columns).
2. `RequestCriteria` handles `search` filter automatically via `AttributeRepository.fieldSearchable`.
3. Eager-loads `values` relation.
4. Returns paginated `AttributeResource` collection with translated name values.

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": [
        {
            "id": 1,
            "name": "Size",
            "slug": "size"
        },
        {
            "id": 2,
            "name": "Color",
            "slug": "color"
        }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 2,
    "last_page": 1,
    "path": "http://localhost/api/attributes",
    "per_page": 15,
    "total": 2,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/attributes?page=1",
    "first_page_url": "http://localhost/api/attributes?page=1"
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `view-attributes` permission |

---

### POST /attributes — Create Attribute

**Purpose:** Create a new attribute with optional values.

**Method:** `POST`

**URL:** `/attributes`

**Authentication:** Required

**Permissions:** `create-attribute` (route requires `auth:sanctum` + `email.verified`)

**Request Body (JSON):**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `name` | object | **Yes** | Translatable array |
| `name.en` | string | **Yes** | `string`, `min:2`, `max:50`, unique translation |
| `name.ar` | string | **Yes** | `string`, `min:2`, `max:50`, unique translation |
| `values` | array | No | Array of value objects |
| `values.*` | object | No | Value object |
| `values.*.value` | object | No | Translatable value array |

**Example Request:**
```json
{
    "name": {
        "en": "Size",
        "ar": "حجم"
    },
    "values": [
        { "value": { "en": "Small", "ar": "صغير" } },
        { "value": { "en": "Medium", "ar": "متوسط" } },
        { "value": { "en": "Large", "ar": "كبير" } }
    ]
}
```

**Business Logic:**
1. Validates via `AttributeRequest`.
2. Generates slug from English name via `Sluggable` trait.
3. Creates the attribute inside a database transaction.
4. If `values` provided, creates each `AttributeValue` linked to the attribute.
5. Returns attribute with loaded values.

**Success Response (201):**
```json
{
    "status": 201,
    "message": "Attribute created successfully",
    "success": true,
    "data": {
        "id": 1,
        "name": {
            "en": "Size",
            "ar": "حجم"
        },
        "slug": "size",
        "values": [
            { "id": 1, "value": { "en": "Small", "ar": "صغير" }, "slug": "small", "attribute_id": 1 },
            { "id": 2, "value": { "en": "Medium", "ar": "متوسط" }, "slug": "medium", "attribute_id": 1 },
            { "id": 3, "value": { "en": "Large", "ar": "كبير" }, "slug": "large", "attribute_id": 1 }
        ]
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `create-attribute` permission or role |
| 422 | Validation failure |

---

### GET /attributes/{id} — Show Attribute

**Purpose:** Fetch a single attribute by ID or slug with its values.

**Method:** `GET`

**URL:** `/attributes/{id}`

**Authentication:** Required (via `view-attributes` permission middleware)

**Permissions:** `view-attributes`

**Business Logic:**
1. If `{id}` is numeric, finds by `id`; otherwise finds by `slug`.
2. Eager-loads `values` relation.
3. Returns attribute resource with raw original name (all translations).

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "id": 1,
        "name": {
            "en": "Size",
            "ar": "حجم"
        },
        "slug": "size",
        "values": [
            { "id": 1, "value": { "en": "Small", "ar": "صغير" }, "slug": "small", "attribute_id": 1 },
            { "id": 2, "value": { "en": "Medium", "ar": "متوسط" }, "slug": "medium", "attribute_id": 1 },
            { "id": 3, "value": { "en": "Large", "ar": "كبير" }, "slug": "large", "attribute_id": 1 }
        ]
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `view-attributes` permission |
| 404 | Attribute not found |

---

### PUT /attributes/{id} — Update Attribute

**Purpose:** Update an attribute's name and/or replace its values.

**Method:** `PUT`

**URL:** `/attributes/{id}`

**Authentication:** Required

**Permissions:** `update-attribute` (route requires `auth:sanctum` + `email.verified`)

**Request Body (JSON):**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `name` | object | No | Translatable array |
| `name.en` | string | No | `string`, `min:2`, `max:50`, unique translation (ignores self) |
| `name.ar` | string | No | `string`, `min:2`, `max:50`, unique translation (ignores self) |
| `values` | array | No | Array of value objects (replaces all existing values) |

**Example Request:**
```json
{
    "name": {
        "en": "Size Updated",
        "ar": "حجم محدث"
    },
    "values": [
        { "value": { "en": "XS", "ar": "صغير جداً" } },
        { "value": { "en": "XL", "ar": "كبير جداً" } }
    ]
}
```

**Business Logic:**
1. Validates via `AttributeRequest`.
2. Finds attribute by ID.
3. Updates attribute fields inside a database transaction.
4. If `values` provided, **deletes all existing values** and creates new ones from the input.
5. Returns updated attribute with values.

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Attribute updated successfully",
    "success": true,
    "data": {
        "id": 1,
        "name": {
            "en": "Size Updated",
            "ar": "حجم محدث"
        },
        "slug": "size-updated",
        "values": [
            { "id": 4, "value": { "en": "XS", "ar": "صغير جداً" }, "slug": "xs", "attribute_id": 1 },
            { "id": 5, "value": { "en": "XL", "ar": "كبير جداً" }, "slug": "xl", "attribute_id": 1 }
        ]
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `update-attribute` permission or role |
| 404 | Attribute not found |
| 422 | Validation failure |

---

### DELETE /attributes/{id} — Delete Attribute

**Purpose:** Delete an attribute and its values (cascade).

**Method:** `DELETE`

**URL:** `/attributes/{id}`

**Authentication:** Required

**Permissions:** `delete-attribute` (route requires `auth:sanctum` + `email.verified`)

**Business Logic:**
1. Finds attribute by ID.
2. Deletes the record (database cascade removes attribute_values).
3. Returns success message.

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Attribute deleted successfully",
    "success": true
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `delete-attribute` permission or role |
| 404 | Attribute not found |

---

### POST /import-attributes — Import Attributes via CSV

**Purpose:** Import attributes with values from a CSV file.

**Method:** `POST`

**URL:** `/import-attributes`

**Authentication:** Required

**Permissions:** No permission middleware applied. Route has `throttle:uploads` middleware. Controller checks repository-level `hasPermission`.

**Request:** Multipart form data with CSV file.

**Business Logic:**
1. Route is rate-limited via `throttle:uploads` middleware.
2. Uploads CSV file to `public/csv-files/`.
3. Parses CSV into array.
4. For each row, creates/finds attribute by name.
5. Parses comma-separated `values` column and creates attribute values.
6. Uses `firstOrCreate` to avoid duplicates.

**CSV Format:**
Expected columns: `name`, `values` (comma-separated).

---

### GET /export-attributes/{id} — Export Attributes as CSV

**Purpose:** Export all attributes as a CSV download.

**Method:** `GET`

**URL:** `/export-attributes/{id}`

**Authentication:** Required

**Permissions:** No permission middleware applied.

**Business Logic:**
1. Queries attributes with eager-loaded `values`.
2. Returns CSV file download.
3. Excludes columns: `id`, `created_at`, `updated_at`, `slug`, `translated_languages`.
4. Values column is flattened to comma-separated string.

---

## Endpoints — Attribute Values

### GET /attribute-values — List Attribute Values

**Purpose:** List all attribute values with their parent attribute. Supports pagination, sorting, and search.

**Method:** `GET`

**URL:** `/attribute-values`

**Authentication:** Required (via `view-attributes` permission middleware)

**Permissions:** `view-attributes`

**Query Parameters:**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `limit` | int | 15 | Items per page |
| `order` | string | — | Sort column (`id`, `value`, `attribute_id`, `slug`, `created_at`, `updated_at`) |
| `sortedBy` | `asc`/`desc` | `asc` | Sort direction |
| `search` | string | — | Search term (matches `value` via LIKE) |

**Example URLs:**
```
GET /attribute-values?limit=10
GET /attribute-values?search=small
GET /attribute-values?order=value&sortedBy=desc
GET /attribute-values?limit=20&order=created_at&sortedBy=desc
```

**Business Logic:**
1. Applies optional `order`/`sortedBy` sorting (validated against allowed columns).
2. `RequestCriteria` handles `search` filter automatically.
3. Eager-loads `attribute` relation.
4. Returns paginated `AttributeValueResource` collection with translated value strings.

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": [
        {
            "id": 1,
            "value": "Small",
            "slug": "small",
            "attribute_id": 1
        },
        {
            "id": 2,
            "value": "Medium",
            "slug": "medium",
            "attribute_id": 1
        }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 2,
    "last_page": 1,
    "path": "http://localhost/api/attribute-values",
    "per_page": 15,
    "total": 2,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/attribute-values?page=1",
    "first_page_url": "http://localhost/api/attribute-values?page=1"
}
```

---

### POST /attribute-values — Create Attribute Value

**Purpose:** Create a new attribute value.

**Method:** `POST`

**URL:** `/attribute-values`

**Authentication:** Required

**Permissions:** `create-attribute` (route requires `auth:sanctum` + `email.verified`)

**Request Body (JSON):**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `value` | object | **Yes** | Translatable array |
| `value.en` | string | **Yes** | `string`, `max:255` |
| `value.ar` | string | **Yes** | `string`, `max:255` |
| `attribute_id` | int | **Yes** | `exists:Marvel\Database\Models\Attribute,id` |

**Example Request:**
```json
{
    "value": {
        "en": "red",
        "ar": "احمر"
    },
    "attribute_id": 1
}
```

**Business Logic:**
1. Validates via `AttributeValueRequest`.
2. Creates attribute value with translatable `value` field.
3. Slug auto-generated from value via `Sluggable` trait.
4. Returns created resource.

**Success Response (201):**
```json
{
    "status": 201,
    "message": "Attribute value created successfully",
    "success": true,
    "data": {
        "id": 6,
        "value": {
            "en": "red",
            "ar": "احمر"
        },
        "slug": "red",
        "attribute_id": 1
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `create-attribute` permission or role |
| 422 | Validation failure |

---

### GET /attribute-values/{id} — Show Attribute Value

**Purpose:** Fetch a single attribute value.

**Method:** `GET`

**URL:** `/attribute-values/{id}`

**Authentication:** Required (via `view-attributes` permission middleware)

**Permissions:** `view-attributes`

**Business Logic:**
1. Finds by ID with `attribute` relation.
2. Returns resource with raw original value (all translations).

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "id": 6,
        "value": {
            "en": "Extra Large",
            "ar": "كبير جداً"
        },
        "slug": "extra-large",
        "attribute_id": 1
    }
}
```

---

### PUT /attribute-values/{id} — Update Attribute Value

**Purpose:** Update an attribute value.

**Method:** `PUT`

**URL:** `/attribute-values/{id}`

**Authentication:** Required

**Permissions:** `update-attribute` (route requires `auth:sanctum` + `email.verified`)

**Request Body (JSON):**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `value` | object | No | Translatable array |
| `value.en` | string | No | `string`, `max:255` |
| `value.ar` | string | No | `string`, `max:255` |
| `attribute_id` | int | No | `exists:Marvel\Database\Models\Attribute,id` |

**Example Request:**
```json
{
    "value": {
        "en": "XXL",
        "ar": "اكس اكس ال"
    }
}
```

**Business Logic:**
1. Validates via `AttributeValueRequest`.
2. Updates the attribute value.
3. Returns fresh resource.

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Attribute value updated successfully",
    "success": true,
    "data": {
        "id": 6,
        "value": {
            "en": "XXL",
            "ar": "اكس اكس ال"
        },
        "slug": "xxl",
        "attribute_id": 1
    }
}
```

---

### DELETE /attribute-values/{id} — Delete Attribute Value

**Purpose:** Delete an attribute value.

**Method:** `DELETE`

**URL:** `/attribute-values/{id}`

**Authentication:** Required

**Permissions:** `delete-attribute` (route requires `auth:sanctum` + `email.verified`)

**Business Logic:**
1. Finds the attribute value by ID.
2. Deletes the attribute value.
3. Returns success message.

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Attribute value deleted successfully",
    "success": true
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `delete-attribute` permission or role |
| 404 | Attribute value not found |

---

## Model Features

- **Translatable:** `Attribute.name`, `AttributeValue.value` (Spatie `HasTranslations`)
- **Sluggable:** Auto-generated slugs via `Cviebrock\EloquentSluggable` (from English name/value)
- **Relations:**
  - `Attribute hasMany AttributeValue`
  - `AttributeValue belongsTo Attribute`
  - `AttributeValue belongsToMany ProductVariant` via `attribute_product` pivot (foreign key: `attribute_value_id`, related key: `product_variant_id`)
  - `AttributeProduct belongsTo ProductVariant` (foreign key: `product_variant_id`)
  - `AttributeProduct belongsTo AttributeValue` (foreign key: `attribute_value_id`)

---

## Dependencies

| Class | Type | File |
|-------|------|------|
| `AttributeController` | Controller | `packages/marvel/src/Http/Controllers/AttributeController.php` |
| `AttributeValueController` | Controller | `packages/marvel/src/Http/Controllers/AttributeValueController.php` |
| `AttributeRepository` | Repository | `packages/marvel/src/Database/Repositories/AttributeRepository.php` |
| `AttributeValueRepository` | Repository | `packages/marvel/src/Database/Repositories/AttributeValueRepository.php` |
| `Attribute` | Model | `packages/marvel/src/Database/Models/Attribute.php` |
| `AttributeValue` | Model | `packages/marvel/src/Database/Models/AttributeValue.php` |
| `AttributeProduct` | Pivot Model | `packages/marvel/src/Database/Models/AttributeProduct.php` |
| `AttributeResource` | Resource | `packages/marvel/src/Http/Resources/AttributeResource.php` |
| `AttributeValueResource` | Resource | `packages/marvel/src/Http/Resources/AttributeValueResource.php` |
| `AttributeRequest` | Form Request | `packages/marvel/src/Http/Requests/AttributeRequest.php` |
| `AttributeValueRequest` | Form Request | `packages/marvel/src/Http/Requests/AttributeValueRequest.php` |
| `Permission` | Enum | `packages/marvel/src/Enums/Permission.php` |

---

## Translations

Translation keys are defined in `packages/marvel/src/` language files.

| Key | English | Arabic |
|-----|---------|--------|
| `ATTRIBUTE_CREATED_SUCCESSFULLY` | Attribute created successfully | تم إنشاء السمة بنجاح |
| `ATTRIBUTE_UPDATED_SUCCESSFULLY` | Attribute updated successfully | تم تحديث السمة بنجاح |
| `ATTRIBUTE_DELETED_SUCCESSFULLY` | Attribute deleted successfully | تم حذف السمة بنجاح |
| `ATTRIBUTE_VALUE_CREATED_SUCCESSFULLY` | Attribute value created successfully | تم إنشاء قيمة السمة بنجاح |
| `ATTRIBUTE_VALUE_UPDATED_SUCCESSFULLY` | Attribute value updated successfully | تم تحديث قيمة السمة بنجاح |
| `ATTRIBUTE_VALUE_DELETED_SUCCESSFULLY` | Attribute value deleted successfully | تم حذف قيمة السمة بنجاح |

---

## Architecture Notes

### Execution Flow

```
Request
   ↓
Controller (permission middleware applied)
   ↓
Repository (storeAttribute / updateAttribute)
   ↓
Model (Attribute / AttributeValue)
   ↓
Resource (AttributeResource / AttributeValueResource)
   ↓
JSON Response
```

### Key Architecture Decisions

1. **Repository Pattern:** All database operations (create, update, delete) are handled by `AttributeRepository` and `AttributeValueRepository`. Controllers delegate to repositories.

2. **Database Transactions:** `AttributeRepository::storeAttribute` wraps attribute + values creation in a `DB::beginTransaction` / `DB::commit` / `DB::rollBack` block for atomicity.

3. **Value Replacement on Update:** `AttributeRepository::updateAttribute` deletes all existing values and recreates them from the input. There is no partial sync or individual value update through the attribute endpoint.

4. **Translation Handling:** Translatable fields (`Attribute.name`, `AttributeValue.value`) use Spatie HasTranslations. Resources switch behavior based on route: `index` returns translated string (`getTranslation`), `show` returns raw JSON object (`getRawOriginal`).

5. **Permission Architecture:** The controller constructor applies `permission:...` middleware. The `store`/`update`/`destroy` routes additionally sit behind an `auth:sanctum` + `email.verified` middleware group.

6. **Slug Generation:** Slugs are auto-generated from the English translation value and are read-only after creation.

### Known Constraints

1. **Attribute values are fully replaced on update** — there is no partial update support. Clients must send the complete desired list of values.

2. **No individual attribute value update via attribute endpoint** — individual values can only be updated via the dedicated `/attribute-values/{id}` endpoint.

3. **Slug uniqueness is global** — slug uniqueness is enforced via database unique constraint without language scoping.

4. **Missing `codezero/laravel-unique-translation` dependency** — The `AttributeRequest` uses `CodeZero\UniqueTranslation\UniqueTranslationRule` but the package is not declared in `composer.json`. The create/update endpoints would throw a 500 error in production when this class is not autoloadable.

---

## Testing Coverage

### Existing Tests (`tests/Feature/AttributeApiTest.php`)

**Index (requires `view-attributes`):**
- ✔ `test_authenticated_user_can_list_attributes` — GET `/api/v1/attributes` returns 200
- ✔ `test_guest_cannot_list_attributes` — GET without auth returns 403
- ✔ `test_list_attributes_returns_empty_data_when_none_exist` — GET with no data returns 200

**Show (requires `view-attributes`):**
- ✔ `test_authenticated_user_can_show_attribute_by_id` — GET `/api/v1/attributes/{id}` returns attribute
- ✔ `test_guest_gets_403_for_attribute_show` — GET without auth returns 403
- ✔ `test_authenticated_user_gets_404_for_nonexistent_attribute_id` — GET with invalid ID returns 404

**Create (requires `create-attribute`):**
- ✔ `test_unauthenticated_user_cannot_create_attribute` — POST without auth returns 401
- ✔ `test_authenticated_admin_can_create_attribute` — POST with auth + permission returns 201
- ✔ `test_user_without_required_permission_gets_forbidden_for_create` — POST without required route authorization returns 403

**Update (requires `update-attribute`):**
- ✔ `test_unauthenticated_user_cannot_update_attribute` — PUT without auth returns 401

**Delete (requires `delete-attribute`):**
- ✔ `test_unauthenticated_user_cannot_delete_attribute` — DELETE without auth returns 401
- ✔ `test_authenticated_admin_can_delete_attribute` — DELETE with auth returns 200

**Attribute Values - Create (requires `create-attribute`):**
- ✔ `test_unauthenticated_user_cannot_create_attribute_value` — POST without auth returns 401
- ✔ `test_authenticated_admin_can_create_attribute_value` — POST with auth returns 201

**Attribute Values - Delete (requires `delete-attribute`):**
- ✔ `test_authenticated_admin_can_delete_attribute_value` — DELETE with auth returns 200

**Cascade:**
- ✔ `test_deleting_attribute_cascades_to_its_values` — DELETE attribute removes associated values

### Missing Test Coverage

- ✗ Validation failure tests (422 responses)
- ✗ Update attribute with permission (PUT success)
- ✗ Show attribute by slug
- ✗ Pagination edge cases (limit, page boundary)
- ✗ Search/filter behavior
- ✗ Sorting behavior (various columns, directions)
- ✗ Update attribute value (PUT /attribute-values/{id})
- ✗ Show attribute value (GET /attribute-values/{id})
- ✗ Translation serialization (index returns translated string, show returns raw JSON)
- ✗ Resource serialization structure (JSON shape assertions)
- ✗ Database persistence assertions (verify attribute/value saved to DB)
- ✗ Duplicate data (unique translation enforcement)
- ✗ Mass assignment protection
- ✗ Import/export endpoints
- ✗ Transaction rollback scenarios
- ✗ Response envelope structure assertions (status, message, success, data keys)
