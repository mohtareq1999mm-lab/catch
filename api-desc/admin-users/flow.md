# Request Flows — Admin Users

## Flow 1: List Users with Filters

```
Client → GET /api/users?active=true&search=john&page=1&limit=15
         ↓
    [auth:sanctum] middleware → authenticate token
         ↓
    [permission:view-users] middleware → check Spatie permission
         ↓
    UserController@index()
         ↓
    Apply filters:
      - trash=true → onlyTrashed()
      - users=true → where('type', 'user')
      - admins=true → where('type', 'admin')
      - active=true → where('is_active', true)
      - in_active=true → where('is_active', false)
      - search → where name LIKE '%search%' OR email LIKE '%search%'
      - order_by / sort → orderBy($orderBy, $sort)
         ↓
    UserRepository::with(['permissions']) → paginate($limit)
         ↓
    UserResource::collection($users) → transform each user
         ↓
    Return: { status, message, success, data, pagination_meta }
```

## Flow 2: Create Admin User

```
Client → POST /api/admin-users/add { name, email, password, roles, ... }
         ↓
    [auth:sanctum] middleware
         ↓
    [permission:create-user] middleware
         ↓
    AdminCreateUserRequest → validation rules:
      - name: required
      - email: required, email, unique:users
      - password: required, min:6, confirmed
      - phone_number: required, unique:users
      - roles.*: integer, exists:roles,id
      - is_active: in:0,1
         ↓
    Fail? → 422 with field errors
         ↓
    UserController@adminAddUsers()
         ↓
    UserRepository@addUserWithRole($request)
         ↓
    User::create({ name, email, password: Hash, email_verified_at: now, type: 'admin', phone_number, is_active })
         ↓
    If image file → uploadSingleImage()
         ↓
    If roles provided → Role::whereIn('id', $request->roles) → $user->assignRole($roles)
           ↓
           User model assignRole() dispatches UserRolesUpdated event
           ↓
           LogUserRolesUpdated listener dispatches LogActivityJob
         ↓
    $user->load(['roles'])
         ↓
    UserResource::make($user)
         ↓
    Return: { status:200, message, success:true, data }
```

## Flow 3: Update User

```
Client → PUT /api/users/{id} { name, email, avatar, address, ... }
         ↓
    [auth:sanctum] middleware
         ↓
    [permission:edit-user] middleware
         ↓
    UserUpdateRequest → validation
         ↓
    UserController@update($request, $id)
         ↓
    Check: is user a SUPER_ADMIN?
      ├─ Yes → find user by $id, proceed
      └─ No → check if $request->user()->id == $id
           ├─ Yes → proceed
           └─ No → throw AuthorizationException (403)
         ↓
    UserRepository@updateUser($request, $user)
         ↓
    If address[] provided → upsert Address records
    If image file → updateSingleImage()
    $user->update($request->only(['name', 'email', 'shop_id']))
         ↓
    UserObserver@updated() → dispatches LogActivityJob
         ↓
    UserResource::make($user)
         ↓
    Return: { status:200, message, success:true, data }
```

## Flow 4: Toggle User Activation

```
Client → PUT /api/admin-users/update-activation { user_id: 5 }
         ↓
    [auth:sanctum] → [permission:edit-user]
         ↓
    Inline validation: user_id required|integer|exists:users,id
         ↓
    UserController@adminUpdateActivationUsers()
         ↓
    Find user by user_id
         ↓
    If user hasRole('super_admin') AND user->is_active === true AND user->id !== auth()->id()
      → return 400 "User cannot be updated"
         ↓
    $user->is_active = !$user->is_active
    $user->save()
         ↓
    UserObserver@updated() detects is_active change
      → dispatches LogActivityJob with 'user_activated' or 'user_deactivated'
         ↓
    Return: { status:200, message, success:true }
```

## Flow 5: Delete / Restore / Force Delete

### Soft Delete
```
DELETE /api/admin-users/delete/{id}
    → Check hasRole('super_admin') || self? → 400
    → $user->tokens()->delete()
    → $user->delete() (sets deleted_at)
    → Observer dispatches 'user_deleted' activity log
```

### Restore
```
PUT /api/admin-users/restore/{id}
    → User::withTrashed()->findOrFail($id)
    → Check $user->trashed()? → else 400
    → $user->restore()
    → Observer dispatches 'user_restored' activity log
```

### Force Delete
```
DELETE /api/admin-users/delete-forever/{id}
    → User::withTrashed()->findOrFail($id)
    → Check hasRole('super_admin') || self? → 400
    → $user->forceDelete()
    → Observer dispatches 'user_force_deleted' activity log
```
