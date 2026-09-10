# Backend Architecture — Admin Users

## Controller: `UserController`

**File**: `packages/marvel/src/Http/Controllers/UserController.php`

Extends `CoreController`. Uses traits: `WalletsTrait`, `UsersTrait`, `ApiResponse`.

### Constructor Middleware (Permission-based)

```php
$this->middleware("permission:" . Permission::VIEW_USERS, ["only" => ["index", "show", "adminTrashedUsers"]]);
$this->middleware("permission:" . Permission::CREATE_USER, ["only" => ["adminAddUsers"]]);
$this->middleware("permission:" . Permission::DELETE_USER, ["only" => ["adminDeleteUsers", "adminDeleteUsersForever", "destroy"]]);
$this->middleware("permission:" . Permission::EDIT_USER, ["only" => ["adminUpdateActivationUsers", "update"]]);
$this->middleware("permission:" . Permission::RESTORE_USER, ["only" => ["adminRestoreUser"]]);
```

### Endpoint Logic

| Method | Key Logic |
|--------|-----------|
| `me()` | Returns authenticated user with relations; sets `role` as first role name. |
| `index()` | Applies filters: trash/trashed, type (user/admin), active/inactive, search (name/email LIKE), order_by/sort. Paginates with 15 default limit. |
| `show($id)` | If admin, loads `roles` + `permissions`. If user, loads `address`. |
| `update($request, $id)` | SUPER_ADMIN can update any user; others can only update themselves. Delegates to `UserRepository::updateUser()`. |
| `adminAddUsers()` | Creates user via `UserRepository::addUserWithRole()`. Loads `roles` relation. |
| `adminUpdateActivationUsers()` | Validates `user_id` exists. Prevents deactivating an active super_admin (except self if already inactive). Toggles `is_active`. |
| `adminDeleteUsers($id)` | Prevents deleting super_admin or self. Revokes tokens, soft-deletes. |
| `adminRestoreUser($id)` | Only works on trashed users; returns 400 if not trashed. |
| `adminDeleteUsersForever($id)` | Same guard as delete, uses `forceDelete()`. |

## Repository: `UserRepository`

**File**: `packages/marvel/src/Database/Repositories/UserRepository.php`

Extends `BaseRepository`. Uses `MediaManager` trait.

### Key Methods

| Method | Description |
|--------|-------------|
| `storeUser($request)` | Creates user with name, email, hashed password, email_verified_at=now, phone_number. Handles image upload. |
| `updateUser($request, $user)` | Creates/updates address records if provided. Handles image replacement. Updated only name, email, shop_id (via `$dataArray`). |
| `addUserWithRole($request)` | Creates user with type='admin'. Assigns roles from `$request->roles` (array of role IDs). |
| `sendResetEmail($email, $token)` | Sends password reset email via `ForgetPassword` mailable. |

## Model: `User`

**File**: `packages/marvel/src/Database/Models/User.php`

Extends `Authenticatable`, implements `MustVerifyEmail`, `HasMedia`.

### Key Traits
- `HasRoles` — Spatie permission/role assignment (overridden `assignRole`, `syncRoles`, `removeRole` to dispatch `UserRolesUpdated` event)
- `HasApiTokens` — Sanctum API tokens
- `SoftDeletes` — Soft delete support
- `InteractsWithMedia` — Media Library for avatars
- `HasOneTimePasswords` — OTP-based login

### Global Scope
- Orders all queries by `updated_at desc`

### Guard
- `protected $guard_name = 'api'`

### Fillable
`name`, `email`, `password`, `is_active`, `type`, `email_verified_at`, `phone_number`, `remember_token`

### Appends
- `email_verified` — computed boolean via `hasVerifiedEmail()`

## Events

| Event | Dispatched When | Listener |
|-------|----------------|----------|
| `UserRolesUpdated` | Roles assigned/synced/removed via model traits, or via `makeOrRevokeAdmin` | `LogUserRolesUpdated` (queued) — logs role change to activity log |

## Observer: `UserObserver`

**File**: `app/Observers/UserObserver.php`

Dispatches `LogActivityJob` on: `created`, `updated` (separate event for status changes vs. other changes), `deleted`, `restored`, `forceDeleted`.

## Translations Used

### Messages (en)
- `MESSAGE.USER_CREATED_SUCCESSFULLY`
- `MESSAGE.USER_UPDATED_SUCCESSFULLY`
- `MESSAGE.USER_DELETED_SUCCESSFULLY`
- `MESSAGE.USER_RESTORED_SUCCESSFULLY`
- `MESSAGE.USER_BANNED_SUCCESSFULLY`
- `MESSAGE.USER_ACTIVATED_SUCCESSFULLY`
- `MESSAGE.USER_NOT_FOUND`
- `MESSAGE.USER_CANNOT_BE_UPDATED`
- `MESSAGE.USER_CANNOT_BE_RESTORED`

### Activity Log (en)
- `user_created`, `user_updated`, `user_deleted`, `user_restored`, `user_force_deleted`, `user_activated`, `user_deactivated`, `user_role_changed`

### Arabic translations exist for all keys in `lang/ar/message.php` and `lang/ar/activity.php`.
