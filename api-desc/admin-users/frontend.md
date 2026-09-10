# Frontend Integration — Admin Users

## Available Endpoints

### GET /api/me
Fetch the currently authenticated user.

```typescript
// Request
GET /api/me
Headers: { Authorization: 'Bearer <token>' }

// Response (200)
{
  status: 200,
  message: 'User profile retrieved successfully',
  success: true,
  data: UserResource
}
```

### GET /api/users
Paginated list of users with filters.

```typescript
// Query Parameters
interface UserListParams {
  page?: number;           // Default: 1
  limit?: number;          // Default: 15
  search?: string;         // Search name or email
  users?: 'true' | 'false'; // Filter by type=user
  admins?: 'true' | 'false'; // Filter by type=admin
  active?: 'true' | 'false'; // Filter by is_active=true
  in_active?: 'true' | 'false'; // Filter by is_active=false
  trash?: 'true' | 'false'; // Include soft-deleted users
  type?: 'user' | 'admin';   // Filter by exact type
  order_by?: string;       // Default: 'created_at'
  sort?: 'asc' | 'desc';   // Default: 'desc'
}
```

### POST /api/admin-users/add
Create a new admin user.

```typescript
interface AdminCreateUserPayload {
  name: string;
  email: string;
  password: string;           // min:6, max:50
  password_confirmation: string;
  phone_number?: string;      // unique, required
  roles?: number[];           // Array of role IDs
  image?: File;               // jpeg,png,jpg,webp
  is_active?: 0 | 1;
}
```

### PUT /api/admin-users/update-activation
Toggle user active/inactive status.

```typescript
interface ActivationPayload {
  user_id: number;
}
```

### DELETE /api/admin-users/delete/{id}
Soft-delete a user.
```typescript
// Response (200)
{ status: 200, message: 'User deleted successfully', success: true }
```

### PUT /api/admin-users/restore/{id}
Restore a soft-deleted user.
```typescript
// Response (200)
{ status: 200, message: 'User restored successfully', success: true }
```

### DELETE /api/admin-users/delete-forever/{id}
Permanently delete a user.

### PUT /api/users/{id}
Update user fields.

```typescript
interface UpdateUserPayload {
  name?: string;       // max:255
  email?: string;      // unique, ignores own
  avatar?: File;       // jpeg,png,jpg,gif,svg, max:2MB
  profile?: object;
  address?: object[];
}
```

### GET /api/users/{id}
Get single user details.

## UserResource Shape

```typescript
interface UserResource {
  id: number;
  name: string;
  email: string;
  email_verified_at: string | null;
  is_active: boolean;
  image: string | null;           // Media Library URL
  type: 'user' | 'admin';
  phone_number: string | null;
  created_at: string;
  updated_at: string;
  roles?: RoleResource[];         // When loaded
  permissions?: PermissionResource[]; // Via roles
  address?: AddressResource[];    // When loaded
}
```

## Error Handling

| HTTP Status | Meaning |
|-------------|---------|
| 200 | Success |
| 400 | Business rule violation (cannot delete self, cannot deactivate admin) |
| 401 | Unauthenticated |
| 403 | Forbidden (missing permission) |
| 404 | User not found |
| 422 | Validation error |
