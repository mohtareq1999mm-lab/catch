# Frontend Integration — Role & Permission

---

## TypeScript Interfaces

### Role
```typescript
export interface Role {
  id: number;
  name: string;
  guard_name: string;
  display_name: string | Record<string, string>;
  created_at: string;
  updated_at: string;
  permissions?: Permission[];
}

export interface RoleFormData {
  name?: string;
  display_name: Record<string, string>;
  guard_name?: string;
  permissions?: string[];
}

export interface AssignRolePayload {
  role_id: number;
}
```

### Permission
```typescript
export interface Permission {
  id: number;
  name: string;
  guard_name: string;
  label: string;
  created_at: string;
  updated_at: string;
}

export interface PermissionFormData {
  permissions: string[];
}
```

### User Permission Operations
```typescript
export interface UserPermissionPayload {
  permissions: string[];
}

// POST  /users/{userId}/permissions  -> givePermission
// PUT   /users/{userId}/permissions  -> syncPermissions
// DELETE /users/{userId}/permissions -> removePermission
```

### API Response
```typescript
export interface ApiResponse<T> {
  data: T;
  success?: boolean;
  message?: string;
}

export interface PaginatedResponse<T> extends ApiResponse<T[]> {
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface MessageResponse {
  message: string;
}
```

---

## API Service Methods (Recommended)

```typescript
// Roles
getRoles(params?: PaginationParams): Promise<PaginatedResponse<Role>>
getRole(id: number): Promise<ApiResponse<Role>>
createRole(data: RoleFormData): Promise<ApiResponse<Role>>
updateRole(id: number, data: Partial<RoleFormData>): Promise<ApiResponse<Role>>
deleteRole(id: number): Promise<MessageResponse>

// User-Role Assignment
assignRoleToUser(userId: number, data: AssignRolePayload): Promise<MessageResponse>
removeRoleFromUser(userId: number, data: AssignRolePayload): Promise<MessageResponse>

// Permissions
getPermissions(params?: PaginationParams): Promise<PaginatedResponse<Permission>>
assignPermissionToRole(roleId: number, data: PermissionFormData): Promise<MessageResponse>

// User Permissions
givePermissionToUser(userId: number, data: PermissionFormData): Promise<MessageResponse>
syncUserPermissions(userId: number, data: PermissionFormData): Promise<MessageResponse>
removeUserPermission(userId: number, data: PermissionFormData): Promise<MessageResponse>
```

---

## Request Payload Examples

### Create Role
```json
{
  "name": "support_agent",
  "display_name": { "en": "Support Agent", "ar": "وكيل الدعم" },
  "guard_name": "api",
  "permissions": ["view_users", "view_orders"]
}
```

### Update Role
```json
{
  "display_name": { "en": "Updated Name", "ar": "الاسم المحدث" }
}
```

### Assign Permission to Role
```json
{
  "permissions": ["view_products", "create_products"]
}
```

### Sync User Permissions
```json
{
  "permissions": ["view_store", "view_orders"]
}
```

---

## Error Handling

```typescript
interface ValidationError {
  message: string;
  errors: Record<string, string[]>;
}

interface ForbiddenError {
  message: string;
}

interface NotFoundError {
  message: string;
}

interface ConflictError {
  message: string;
}
```

---

## Guard/Route Middleware (Frontend)

The following permissions guard frontend routes/features:

| Frontend Feature | Required Permission |
|-----------------|-------------------|
| View Roles List | `VIEW_ROLES` |
| View Role Detail | `VIEW_ROLE` |
| Create Role | `CREATE_ROLES` |
| Edit Role | `UPDATE_ROLES` |
| Delete Role | `DELETE_ROLES` |
| Assign Role to User | `ASSIGN_ROLE` |
| Remove Role from User | `REMOVE_ROLE` |
| View All Permissions | `SUPER_ADMIN` |
| Manage Role Permissions | `SUPER_ADMIN` |
| Manage User Permissions | `SUPER_ADMIN` |

---

## Pagination Params Type

```typescript
interface PaginationParams {
  page?: number;
  limit?: number;
  orderBy?: string;
  sortedBy?: 'asc' | 'desc';
  search?: string;
  with?: string;
}
```
