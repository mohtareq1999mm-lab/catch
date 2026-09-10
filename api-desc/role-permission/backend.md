# Backend Architecture — Role & Permission

---

## Controller

**File:** `packages/marvel/src/Http/Controllers/RoleAndPermissionController.php`

**Extends:** `CoreController`

**Trait:** `ApiResponse` (for standardized JSON responses)

**Structure:** 12 methods — one per route. No Service/Repository layer; logic directly in controller.

### Methods

| Method | Visibility | Returns |
|--------|-----------|---------|
| `getAllRoles()` | public | JSON collection |
| `addRole()` | public | JSON single resource |
| `showRole($id)` | public | JSON single resource |
| `updateRole($id, Request)` | public | JSON single resource |
| `destroyRole($id)` | public | JSON message |
| `assignRole($userId, Request)` | public | JSON message |
| `removeRoleFromUser($userId, Request)` | public | JSON message |
| `getAllPermissions()` | public | JSON collection |
| `assignPermissionToRole($roleId, Request)` | public | JSON message |
| `givePermission($userId, Request)` | public | JSON message |
| `syncPermissions($userId, Request)` | public | JSON message |
| `removePermission($userId, Request)` | public | JSON message |

### Validation (Inline — No Form Requests)

Validation is performed inside each method using manual checks. There are **no Form Request classes** for this feature.

Key validation patterns:
- `display_name.en` required for role creation
- `role_id` must exist in `roles` table for assign/remove
- `permissions` must be an array for permission operations
- User `type === 'user'` check for role assignment (not customer)

---

## Models

### Role Model

**File:** `packages/marvel/src/Database/Models/Role.php`

**Extends:** `Spatie\Permission\Models\Role`

**Trait:** `HasTranslations`

| Feature | Details |
|---------|---------|
| Translatable | `display_name` field |
| Table | `roles` |
| Guard | `api` (configurable) |
| Relations | Inherits `permissions()` from Spatie |

**Key Notes:**
- Uses Spatie's built-in `permissions()` BelongsToMany relation
- No custom `users()` relation defined (inherits from Spatie)

### Permission Model

**File:** `packages/marvel/src/Database/Models/Permission.php`

**Extends:** `Spatie\Permission\Models\Permission`

| Feature | Details |
|---------|---------|
| Table | `permissions` |
| Guard | `api` (configurable) |
| Relations | Custom `users()` BelongsToMany via `model_has_permissions` |

**Key Notes:**
- Adds a `users()` relation (not in base Spatie)
- No translatable fields

---

## Enums

### Marvel\Enums\Role

| Constant | Value |
|----------|-------|
| `SUPER_ADMIN` | `'super_admin'` |
| `STORE_OWNER` | `'store_owner'` |
| `STAFF` | `'staff'` |
| `CUSTOMER` | `'customer'` |
| `EDITOR` | `'editor'` |

### Marvel\Enums\Permission

All system permission constants (see `README.md` for full list). Used for middleware checks via `@permission:CONSTANT` in route definitions.

---

## Resources

### RoleResource

**File:** `packages/marvel/src/Http/Resources/RoleResource.php`

**Extends:** `JsonResource`

| Field | Source | Type |
|-------|--------|------|
| `id` | `$this->id` | int |
| `name` | `$this->name` | string |
| `guard_name` | `$this->guard_name` | string |
| `display_name` | `$this->display_name` | string\|array |
| `created_at` | `$this->created_at` | datetime |
| `updated_at` | `$this->updated_at` | datetime |
| `permissions` | `$this->whenLoaded('permissions')` | collection |

### PermissionResource

**File:** `packages/marvel/src/Http/Resources/PermissionResource.php`

**Extends:** `JsonResource`

| Field | Source | Type |
|-------|--------|------|
| `id` | `$this->id` | int |
| `name` | `$this->name` | string |
| `guard_name` | `$this->guard_name` | string |
| `label` | Translated via `__("permissions.{$this->name}")` | string |
| `created_at` | `$this->created_at` | datetime |
| `updated_at` | `$this->updated_at` | datetime |

---

## Translations

**File:** `resources/lang/en/permissions.php`

All 40+ permission keys mapped to human-readable English labels:
```php
return [
    'super_admin' => 'Super Admin Access',
    'view_store' => 'View Store',
    'view_stores' => 'View Stores',
    'create_store' => 'Create Store',
    // ...
];
```

**File:** `resources/lang/ar/permissions.php` — Arabic translations of same keys.

The `PermissionResource` uses `__("permissions.{$this->name}")` to resolve the label dynamically.

---

## Constants/Messages

**File:** `packages/marvel/config/constants.php`

Key messages used in this feature:
```php
'ROLE_NOT_FOUND' => 'Role not found',
'USER_NOT_FOUND' => 'User not found',
'ROLE_ALREADY_ASSIGNED' => 'Role already assigned to user',
'ROLE_NOT_ASSIGNED' => 'Role is not assigned to user',
'ROLE_NOT_DELETABLE' => 'Cannot delete role assigned to users',
'PERMISSION_DENIED' => 'Permission denied',
```

---

## Architecture Diagram

```
Client Request
     │
     ▼
  Route (api.php / shop.php)
     │
     ▼
  Middleware (auth:api + permission:XXX)
     │
     ▼
  RoleAndPermissionController
     │
     ├── Validation (inline)
     │
     ├── Spatie Role Model
     │     └── RoleResource
     │
     ├── Spatie Permission Model
     │     └── PermissionResource
     │
     └── ClearUserCacheById (Job dispatched)
     │
     ▼
  JSON Response
```

**Note:** This feature lacks Service and Repository layers. Business logic resides directly in the controller. This is acceptable for simple CRUD but should be refactored if complexity grows.
