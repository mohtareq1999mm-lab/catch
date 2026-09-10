# Request Flows — Role & Permission

---

## Flow 1: Create Role

```
Client                          Server
  │                                │
  │  POST /roles                   │
  │  Authorization: Bearer <token> │
  │  Content-Type: application/json│
  │  {                             │
  │    "display_name": {           │
  │      "en": "Support Agent",    │
  │      "ar": "وكيل الدعم"        │
  │    },                          │
  │    "permissions": [...]        │
  │  }                             │
  │────────────────────────────►   │
  │                                │
  │          [Middleware Pipeline] │
  │            ├─ auth:api         │
  │            └─ permission:CREATE_ROLES │
  │                                │
  │          [Controller.addRole]  │
  │            ├─ Validate:        │
  │            │  - display_name.en required │
  │            │  - guard_name defaults 'api'│
  │            │  - permissions optional arr │
  │            ├─ Generate name    │
  │            │  from display_name.en       │
  │            │  (lowercase, spaces→_)      │
  │            ├─ Role::create([   │
  │            │  'name',          │
  │            │  'guard_name',    │
  │            │  'display_name'   │
  │            │ ])                │
  │            ├─ If permissions:  │
  │            │  role->givePermissionTo(perms)│
  │            └─ Return RoleResource │
  │                                │
  │  201 { "data": { ... } }      │
  │◄────────────────────────────   │
```

---

## Flow 2: Assign Role To User

```
Client                          Server
  │                                │
  │  POST /users/5/assign-role     │
  │  Authorization: Bearer <token> │
  │  { "role_id": 2 }              │
  │────────────────────────────►   │
  │                                │
  │          [Middleware]           │
  │            ├─ auth:api         │
  │            └─ permission:ASSIGN_ROLE │
  │                                │
  │          [Controller.assignRole]│
  │            ├─ User::find(5)    │
  │            ├─ Check user->type │
  │            │  === 'user'       │
  │            │  (not 'customer') │
  │            ├─ Role::find(2)    │
  │            ├─ user->syncRoles(role) │
  │            ├─ Dispatch:        │
  │            │  ClearUserCacheById(5) │
  │            └─ Return message   │
  │                                │
  │  200 { "message": "Role assigned successfully" } │
  │◄────────────────────────────   │
```

---

## Flow 3: Delete Role

```
Client                          Server
  │                                │
  │  DELETE /roles/6               │
  │  Authorization: Bearer <token> │
  │────────────────────────────►   │
  │                                │
  │          [Middleware]           │
  │            ├─ auth:api         │
  │            └─ permission:DELETE_ROLES │
  │                                │
  │          [Controller.destroyRole] │
  │            ├─ Role::find(6)    │
  │            ├─ Check count:     │
  │            │  model_has_roles  │
  │            │  WHERE role_id=6  │
  │            ├─ If count > 0 →   │
  │            │  409 Conflict:    │
  │            │  "Role is assigned│
  │            │   to one or more  │
  │            │   users."         │
  │            ├─ Else:            │
  │            │  role->delete()   │
  │            │  (cascades pivots)│
  │            └─ Return message   │
  │                                │
  │  200 { "message": "Role deleted successfully" } │
  │◄────────────────────────────   │
```

---

## Flow 4: Assign Permission To Role

```
Client                          Server
  │                                │
  │  POST /roles/2/permissions     │
  │  Authorization: Bearer <token> │
  │  {                             │
  │    "permissions": [            │
  │      "view_products",          │
  │      "create_products"         │
  │    ]                           │
  │  }                             │
  │────────────────────────────►   │
  │                                │
  │          [Middleware]           │
  │            ├─ auth:api         │
  │            └─ permission:SUPER_ADMIN │
  │                                │
  │          [Controller.assignPermissionToRole] │
  │            ├─ Role::find(2)    │
  │            ├─ For each perm:   │
  │            │  Permission::     │
  │            │    firstOrCreate( │
  │            │    name,          │
  │            │    guard_name:api)│
  │            ├─ role->givePermissionTo(perm) │
  │            └─ Return message   │
  │                                │
  │  200 { "message": "Permission assigned to role successfully" } │
  │◄────────────────────────────   │
```

---

## Flow 5: Sync User Permissions

```
Client                          Server
  │                                │
  │  PUT /users/5/permissions      │
  │  Authorization: Bearer <token> │
  │  {                             │
  │    "permissions": [            │
  │      "view_store",             │
  │      "view_orders"             │
  │    ]                           │
  │  }                             │
  │────────────────────────────►   │
  │                                │
  │          [Middleware]           │
  │            ├─ auth:api         │
  │            └─ permission:SUPER_ADMIN │
  │                                │
  │          [Controller.syncPermissions] │
  │            ├─ User::find(5)    │
  │            ├─ For each perm:   │
  │            │  Permission::     │
  │            │    firstOrCreate  │
  │            ├─ Collect IDs      │
  │            ├─ user->syncPermissions([ids]) │
  │            └─ Return message   │
  │                                │
  │  200 { "message": "User permissions synced successfully" } │
  │◄────────────────────────────   │
```

---

## Flow 6: Remove Permission From User

```
Client                          Server
  │                                │
  │  DELETE /users/5/permissions   │
  │  Authorization: Bearer <token> │
  │  {                             │
  │    "permissions": ["view_store"]│
  │  }                             │
  │────────────────────────────►   │
  │                                │
  │          [Middleware]           │
  │            ├─ auth:api         │
  │            └─ permission:SUPER_ADMIN │
  │                                │
  │          [Controller.removePermission] │
  │            ├─ User::find(5)    │
  │            ├─ For each perm:   │
  │            │  Permission::     │
  │            │    findByName     │
  │            ├─ user->revokePermissionTo(perm) │
  │            └─ Return message   │
  │                                │
  │  200 { "message": "Permission removed from user successfully" } │
  │◄────────────────────────────   │
```
