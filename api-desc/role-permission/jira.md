# User Stories — Role & Permission

---

## Epic: Role-Based Access Control System

**Epic ID:** RBAC-EPIC

**Description:** As a Super Admin, I want to manage roles and permissions so that I can control access to system features for different user types.

---

## Stories

### RBAC-001: List All Roles
**As a** Super Admin
**I want to** view a paginated list of all roles
**So that** I can see what roles exist in the system

**Acceptance Criteria:**
- GET /roles returns paginated results
- Response includes role name, display_name, guard_name
- Supports search, sort, and pagination
- Can optionally include permissions relation

---

### RBAC-002: Create Role
**As a** Super Admin
**I want to** create a new role with a display name
**So that** I can define new access levels

**Acceptance Criteria:**
- POST /roles creates a new role
- `display_name` is required (JSON with at least `en` key)
- `name` is auto-generated if not provided
- Can optionally assign permissions during creation
- Duplicate name+guard returns validation error

---

### RBAC-003: View Single Role
**As a** Super Admin
**I want to** view details of a specific role
**So that** I can see its configuration and associated permissions

**Acceptance Criteria:**
- GET /roles/{id} returns role details
- Response includes associated permissions
- Returns 404 if role not found

---

### RBAC-004: Update Role
**As a** Super Admin
**I want to** update an existing role's name, display name, or permissions
**So that** I can keep roles current

**Acceptance Criteria:**
- PUT /roles/{id} updates the role
- Supports partial updates (name, display_name, guard_name)
- Permissions can be synced via the `permissions` array
- Returns 404 if role not found

---

### RBAC-005: Delete Role
**As a** Super Admin
**I want to** delete a role that is no longer needed
**So that** I can keep the system clean

**Acceptance Criteria:**
- DELETE /roles/{id} deletes the role
- Returns 409 Conflict if role is assigned to any user
- Returns 404 if role not found
- Deletion cascades from pivot tables

---

### RBAC-006: Assign Role to User
**As a** Super Admin
**I want to** assign a role to a user
**So that** the user gains the permissions associated with that role

**Acceptance Criteria:**
- POST /users/{userId}/assign-role assigns role to user
- Only works for users with `type === 'user'`
- Returns 403 if target is a customer
- Replaces all current roles with the new role
- Clears user cache after assignment

---

### RBAC-007: Remove Role from User
**As a** Super Admin
**I want to** remove a role from a user
**So that** the user loses the associated permissions

**Acceptance Criteria:**
- POST /users/{userId}/remove-role removes the role
- Returns 404 if user or role not found
- Clears user cache after removal

---

### RBAC-008: List All Permissions
**As a** Super Admin
**I want to** view a paginated list of all permissions
**So that** I can see what permissions are available

**Acceptance Criteria:**
- GET /permissions returns paginated results
- Response includes translated labels
- Only accessible with SUPER_ADMIN permission

---

### RBAC-009: Assign Permission to Role
**As a** Super Admin
**I want to** assign permissions to a role
**So that** users with that role inherit those permissions

**Acceptance Criteria:**
- POST /roles/{roleId}/permissions assigns permissions
- Permissions are auto-created if they don't exist
- Only accessible with SUPER_ADMIN permission

---

### RBAC-010: Give Permission to User
**As a** Super Admin
**I want to** directly grant a permission to a user
**So that** the user has that permission outside of their role

**Acceptance Criteria:**
- POST /users/{userId}/permissions grants permission
- Permissions are auto-created if they don't exist
- Appends to existing permissions (does not replace)

---

### RBAC-011: Sync User Permissions
**As a** Super Admin
**I want to** replace all direct permissions of a user
**So that** I can reset their permission set

**Acceptance Criteria:**
- PUT /users/{userId}/permissions syncs permissions
- Replaces ALL existing direct permissions
- Does not affect role-based permissions

---

### RBAC-012: Remove Permission from User
**As a** Super Admin
**I want to** revoke a specific permission from a user
**So that** the user loses that direct permission

**Acceptance Criteria:**
- DELETE /users/{userId}/permissions removes permission
- Only removes specified permissions
- Does not affect role-based permissions
