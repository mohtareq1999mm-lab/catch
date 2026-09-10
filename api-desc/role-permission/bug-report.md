# Bug Report — Role & Permission

---

## BUG-RP-001: Assign Role Overwrites All Roles (syncRoles vs assignRole)

**Severity:** Medium

**Component:** `RoleAndPermissionController::assignRole()`

**Description:** The `assignRole()` method uses `syncRoles()` which replaces all current roles with the single new role. This means a user can only have one role at a time. If the intent is to allow multiple roles per user, `assignRole()` should use `assignRole()` instead of `syncRoles()`.

**Code Location:** `packages/marvel/src/Http/Controllers/RoleAndPermissionController.php` — assignRole method

**Current Behavior:**
```php
$user->syncRoles($role); // replaces ALL roles
```

**Expected Behavior (if multi-role support is intended):**
```php
$user->assignRole($role); // appends to existing roles
```

**Impact:** Users are restricted to a single role. To change a role, admin must assign a different role, which removes the previous one.

---

## BUG-RP-002: No Guard Validation on Permission Name Format

**Severity:** Low

**Component:** Permission creation methods

**Description:** Permission names are accepted as-is without any format validation. While Spatie internally handles guard-name uniqueness, there's no check for:
- Empty permission names
- Special characters in names
- Length limits

**Code Location:** Multiple methods in `RoleAndPermissionController` (assignPermissionToRole, givePermission, syncPermissions, removePermission)

**Impact:** Permissions with malformed names could be created. However, since the system seeds all valid permissions, this is low risk.

---

## BUG-RP-003: Permission Lookup Without Guard Check May Cause Cross-Guard Issues

**Severity:** Low

**Component:** Permission operations

**Description:** When finding permissions by name (e.g. in `removePermission` using `Permission::findByName($permission)`), the lookup uses the default guard. If a permission exists with the same name but a different guard, this could match the wrong record.

**Code Location:** All permission CRUD methods in the controller

**Current Pattern:**
```php
$permission = Permission::findByName($permission);
```

**Recommended:**
```php
$permission = Permission::findByName($permission, 'api');
```

**Impact:** Low, since all system permissions use the `api` guard. Could cause issues if the system is extended with multiple guards.

---

## BUG-RP-004: No Transaction Wrapping in Permission Assignment

**Severity:** Low

**Component:** `assignPermissionToRole()`

**Description:** When assigning multiple permissions to a role in a loop, if one fails partway through, some permissions may be assigned while others are not. There is no database transaction wrapping the operation.

**Code Location:** `assignPermissionToRole()` method

**Current Behavior:**
```php
foreach ($permissions as $permission) {
    $permission = Permission::firstOrCreate(...);
    $role->givePermissionTo($permission);
}
```

**Recommended:**
```php
DB::transaction(function () use ($role, $permissions) {
    foreach ($permissions as $permission) {
        // ...
    }
});
```

**Impact:** Low. `givePermissionTo` failures are unlikely. The `firstOrCreate` and `givePermissionTo` operations are simple inserts.

---

## BUG-RP-005: No Role Update Guard Prevents Super Admin Demotion

**Severity:** Medium

**Component:** `updateRole()` / `assignRole()`

**Description:** There is no check preventing the last Super Admin from having their role removed or changed. This could lock out all super admins from the system.

**Code Location:** `assignRole()` and `removeRoleFromUser()` methods

**Reproduction Steps:**
1. There is exactly one user with `super_admin` role
2. Assign a different role to that user → `syncRoles` replaces super_admin
3. No remaining users have `super_admin` role

**Impact:** Critical — could result in permanent lockout from admin functionality. No recovery without database intervention.

---

## BUG-RP-006: Assigning Role Returns Generic 404 for Both Missing Entities

**Severity:** Medium

**Component:** `assignRole()` / `removeRoleFromUser()`

**Description:** When a user is not found, the endpoint returns `'User not found'`. When a role is not found, it also returns `'User not found'`. However, the role lookup happens after the user lookup, so a missing role also returns "User not found" instead of "Role not found".

Actually, reviewing the code more carefully: the role lookup happens first for assignRole via `$request->role_id` validation — but if validation passes and the role is deleted between validation and lookup, the error message is still ambiguous.

**Impact:** Medium — confusing error messages during concurrent operations.

---

## BUG-RP-007: Remove Role Does Not Check If User Actually Has the Role

**Severity:** Low

**Component:** `removeRoleFromUser()`

**Description:** The `removeRoleFromUser()` method does not verify the user actually has the role before attempting removal. The `removeRole()` method on Spatie is silently idempotent.

**Code Location:** `removeRoleFromUser()` method

**Impact:** Low — operation is idempotent and succeeds regardless. But it may give a false sense of action to the admin if the user never had the role.
