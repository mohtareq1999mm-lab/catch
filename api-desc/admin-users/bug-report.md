# Bug Report — Admin Users Feature

## BUG-1: Missing UserType import in `makeOrRevokeAdmin`

- **File**: `UserController.php` (line ~1227)
- **Issue**: `makeOrRevokeAdmin()` uses `UserType::ADMIN` but `App\Enums\UserType` was not imported.
- **Fix**: Added `use App\Enums\UserType;` to imports.
- **Regression Test**: `test_make_admin_does_not_crash()` in `UserCrudTest.php`

## BUG-3: `UserUpdateRequest` email unique rule ignores current user

- **File**: `UserUpdateRequest.php`
- **Issue**: `'email' => 'email|unique:users,email'` — updating user with same email fails because it checks uniqueness against all users including the same user.
- **Fix**: Changed to `'email' => ['email', 'unique:users,email,' . $this->route('id')]`
- **Regression Test**: `test_update_user_with_same_email_works()` in `UserCrudTest.php`

## BUG-4: `storeUser()` missing `phone_number`

- **File**: `UserRepository.php` — `storeUser()` method
- **Issue**: The `storeUser()` method was not persisting `phone_number` to the database.
- **Fix**: Added `'phone_number' => $request->phone_number` to the `create()` call.
- **Regression Test**: `test_store_user_persists_phone_number()` in `UserCrudTest.php`

## BUG-6: `destroy()` missing self/super_admin guard

- **File**: `UserController.php` — `destroy()` method
- **Issue**: The `destroy()` method (and `adminDeleteUsers`, `adminDeleteUsersForever`) allowed deleting oneself or a super_admin user.
- **Fix**: Added guard: `if ($user->hasRole('super_admin') || $user->id === auth()->id())`, returns 400 error.
- **Regression Tests**:
  - `test_destroy_fails_for_self_delete()`
  - `test_destroy_fails_for_super_admin_delete()`

## BUG-7: `UserResource` missing fields

- **File**: `UserResource.php`
- **Issue**: Resource was missing `type` and `phone_number` fields.
- **Fix**: Added `'type' => $this->type` and `'phone_number' => $this->phone_number` to `toArray()`.
- **Regression Test**: `test_show_user_returns_all_resource_fields()` in `UserCrudTest.php`
