# JIRA Frontend Stories — Role & Permission

## Epic: Role & Permission Management UI

**Epic ID:** FE-RBAC-EPIC

**Description:** As a Super Admin, I want a complete UI to manage roles, permissions, and user-role/permission assignments so that I can control system access without using the API directly.

---

## Story FE-RBAC-001: Roles List Page
**As a** Super Admin
**I want to** view a paginated list of all roles
**So that** I can see what roles exist and manage them

**Acceptance Criteria:**
- [ ] Table/datatable with columns: ID, Name, Display Name, Guard, Permissions count, Created, Actions
- [ ] Pagination controls (default 15 per page)
- [ ] Search bar for name/display_name search
- [ ] Sortable columns
- [ ] "Create Role" button at top
- [ ] Each row has: View, Edit, Delete actions
- [ ] Loading skeleton during fetch
- [ ] Empty state: "No roles found. Create your first role."
- [ ] Error state with retry

**API:** `GET /api/v1/roles?page=&limit=&search=&orderBy=&sortedBy=&with=permissions`

**UI States:**
| State | Behavior |
|-------|----------|
| Loading | Skeleton rows (5 rows) |
| Empty | Illustration + "Create Role" CTA button |
| Error | Error message + Retry button |
| Success | Full table with data |

---

## Story FE-RBAC-002: Create Role Form
**As a** Super Admin
**I want to** create a new role with multi-language display name and optional permissions
**So that** I can define new access levels for the system

**Acceptance Criteria:**
- [ ] Form fields: Name (optional, auto-generated from display_name), Display Name EN (required), Display Name AR (optional), Guard Name (default: api), Permissions (multi-select checkboxes grouped by module)
- [ ] Name field auto-fills as user types the English display name (lowercase, spaces→underscores)
- [ ] Permission checkboxes grouped by module (Store, Products, Orders, Users, Roles, etc.)
- [ ] Select All / Deselect All toggle per module
- [ ] Real-time validation with inline errors
- [ ] Submit button disabled while loading, shows spinner
- [ ] Success toast "Role created successfully" + redirect to roles list
- [ ] Handle 422 with field-level errors
- [ ] Handle 403 with "not authorized" message

**API:** `POST /api/v1/roles`

**Form Layout:**
```
Role Information
┌─────────────────────────────────────┐
│ Name (optional)    [______________] │
│ Display Name (EN)* [______________] │
│ Display Name (AR)  [______________] │
│ Guard Name         [api        ▼]  │
└─────────────────────────────────────┘

Permissions
┌─────────────────────────────────────┐
│ ☐ Store                    [All]   │
│   ☑ View Store                     │
│   ☑ View Stores                    │
│   ☐ Create Store                   │
│   ☐ Update Store                   │
│   ☐ Delete Store                   │
├─────────────────────────────────────┤
│ ☐ Products                [All]   │
│   ☑ View Product                   │
│   ☐ Create Product                 │
│   ...                              │
└─────────────────────────────────────┘

[Cancel]  [Create Role]
```

**Edge Cases:**
- Submit with only English display name → name auto-generated, guard defaults to api
- Submit with empty permissions → role created with no permissions
- Submit with name that already exists (same guard) → 422 "name already exists"

---

## Story FE-RBAC-003: Edit Role Form
**As a** Super Admin
**I want to** update an existing role's name, display name, or permissions
**So that** I can keep role definitions current

**Acceptance Criteria:**
- [ ] Pre-populated form with existing role data
- [ ] Same layout as Create Role form
- [ ] Permissions checkboxes reflect currently assigned permissions
- [ ] On save, permissions are synced (replaced, not appended)
- [ ] Success toast "Role updated successfully"
- [ ] Handle 404 "Role not found" → redirect to roles list with error toast

**API:** `PUT /api/v1/roles/{id}`

---

## Story FE-RBAC-004: Role Detail Page
**As a** Super Admin
**I want to** view a role's details and its associated permissions
**So that** I can review what a role has access to

**Acceptance Criteria:**
- [ ] Role info card: ID, Name, Display Name (EN + AR), Guard, Created, Updated
- [ ] Permissions list grouped by module with checkmark indicators
- [ ] "Edit Role" button
- [ ] "Delete Role" button (with confirmation)
- [ ] If role is deletable: show delete button
- [ ] If role has assigned users: show warning "This role is assigned to X users" + disable delete
- [ ] Loading state
- [ ] 404 handling

**API:** `GET /api/v1/roles/{id}?with=permissions`

---

## Story FE-RBAC-005: Delete Role with Conflict Handling
**As a** Super Admin
**I want to** delete a role with proper guardrails
**So that** I don't accidentally break user permissions

**Acceptance Criteria:**
- [ ] Delete action triggers confirmation dialog: "Are you sure you want to delete [role name]?"
- [ ] If role has no assigned users → proceed with delete → success toast → list refresh
- [ ] If role has assigned users → API returns 409 → show warning dialog: "This role is assigned to X user(s). Remove all user associations first."
- [ ] After 409, show "View Users with this Role" link
- [ ] Handle 404 "Role not found"
- [ ] Optimistic UI removal on success

**API:** `DELETE /api/v1/roles/{id}`

**UI States:**
| Scenario | Dialog | Action |
|----------|--------|--------|
| No users assigned | "Delete [name]?" with warning text | Confirm deletes |
| Users assigned | 409 error displayed inline | View users button, Cancel |
| Self-deleting own role | Not applicable (cannot delete own role via UI) | N/A |

---

## Story FE-RBAC-006: Assign Role to User
**As a** Super Admin
**I want to** assign a role to a user from their profile
**So that** the user gains the associated permissions

**Acceptance Criteria:**
- [ ] Role selector dropdown on user detail/edit page (admin users only)
- [ ] Dropdown shows all available roles with display_name
- [ ] Current role is pre-selected
- [ ] On change, confirm dialog: "Assign [role name] to [user name]?"
- [ ] "This will replace their current role" warning text
- [ ] Show loading state on save
- [ ] Success toast "Role assigned successfully"
- [ ] Handle 403 "This action is only for users not for customers" → disable for customer-type
- [ ] Handle 404 for user or role
- [ ] Update user display to reflect new role

**API:** `POST /api/v1/users/{userId}/assign-role`

**Request:**
```json
{ "role_id": 2 }
```

---

## Story FE-RBAC-007: Remove Role from User
**As a** Super Admin
**I want to** remove a role from a user
**So that** the user loses the associated permissions

**Acceptance Criteria:**
- [ ] "Remove Role" button on user detail page (only if user has a role)
- [ ] Confirmation dialog: "Remove [role name] from [user name]?"
- [ ] Success toast "Role removed successfully"
- [ ] Handle 404 for user or role
- [ ] Update UI to show user has no role

**API:** `POST /api/v1/users/{userId}/remove-role`

**Request:**
```json
{ "role_id": 2 }
```

---

## Story FE-RBAC-008: Permissions List Page
**As a** Super Admin
**I want to** view all available permissions with their translated labels
**So that** I can see what permissions exist in the system

**Acceptance Criteria:**
- [ ] Table with columns: ID, Permission Name, Label (translated), Guard
- [ ] Search/filter by name or label
- [ ] Pagination
- [ ] Grouped by module visually (Store, Products, Orders, etc.)
- [ ] Read-only view (permissions are managed through role assignment)
- [ ] Loading state
- [ ] Empty state (unlikely but handled)

**API:** `GET /api/v1/permissions?page=&limit=&search=&orderBy=&sortedBy=`

**Note:** Only accessible by SUPER_ADMIN. Regular admins see 403.

---

## Story FE-RBAC-009: Role-Permission Assignment UI
**As a** Super Admin
**I want to** assign permissions to a role via a checkbox interface
**So that** I can control what each role can do

**Acceptance Criteria:**
- [ ] Embedded in Create/Edit Role form (Story FE-RBAC-002, FE-RBAC-003)
- [ ] All permissions displayed as checkboxes grouped by module
- [ ] Each module group has "Select All" / "Deselect All" toggle
- [ ] Currently assigned permissions are pre-checked (edit mode)
- [ ] Changes are saved when the form is submitted
- [ ] API auto-creates permissions if they don't exist

**API:** `POST /api/v1/roles/{roleId}/permissions`

**Request:**
```json
{ "permissions": ["view_products", "create_products"] }
```

---

## Story FE-RBAC-010: User Direct Permission Management
**As a** Super Admin
**I want to** manage a user's direct permissions (outside of roles)
**So that** I can grant individual permissions without changing their role

**Acceptance Criteria:**
- [ ] "Direct Permissions" section on user detail page
- [ ] Three operations:
  - **Add Permission**: multi-select dropdown, appends to existing
  - **Sync Permissions**: checkbox interface, replaces all direct permissions
  - **Remove Permission**: per-permission X button, removes specific permission
- [ ] Display current direct permissions as tags/badges
- [ ] Confirmation dialog for sync: "This will replace all existing direct permissions"
- [ ] Success toast for each operation
- [ ] Handle 404

**API:**
- `POST /api/v1/users/{userId}/permissions` — Add
- `PUT /api/v1/users/{userId}/permissions` — Sync
- `DELETE /api/v1/users/{userId}/permissions` — Remove

---

## Story FE-RBAC-011: Frontend Route Guards
**As a** developer
**I want to** protect frontend routes based on user permissions
**So that** unauthorized users cannot access admin features

**Acceptance Criteria:**
- [ ] Route guard component that checks current user's permissions
- [ ] If user lacks required permission → show "Access Denied" page or redirect
- [ ] Permission-to-route mapping (from `frontend.md`):

| Frontend Route/Feature | Required Permission |
|-----------------------|-------------------|
| `/roles` | `VIEW_ROLES` |
| `/roles/create` | `CREATE_ROLES` |
| `/roles/{id}` | `VIEW_ROLE` |
| `/roles/{id}/edit` | `UPDATE_ROLES` |
| Permission list | `SUPER_ADMIN` |
| User role assignment | `ASSIGN_ROLE` |
| User permission management | `SUPER_ADMIN` |

- [ ] If user has no roles at all → show "No access" message with contact admin prompt

---

## Story FE-RBAC-012: API Error Handling (All Endpoints)
**As a** developer
**I want to** ensure all RBAC forms handle API errors consistently
**So that** users see meaningful messages

**Acceptance Criteria:**
- [ ] 400 → display backend error message (e.g., "Role is assigned to users")
- [ ] 403 → "You don't have permission" with upgrade prompt
- [ ] 404 → "Not found" with navigation to list
- [ ] 409 → warning "Role is in use" with details
- [ ] 422 → field-level inline validation errors
- [ ] 500 → generic error with retry
- [ ] Network timeout → "Connection lost" toast
- [ ] All API calls wrapped in try/catch with consistent error format
