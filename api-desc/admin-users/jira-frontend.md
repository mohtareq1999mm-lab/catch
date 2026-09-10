# JIRA Frontend Stories — Admin Users

## Story FE-ADMIN-USERS-001: User Listing Page with Filters
**As a** Super Admin
**I want to** view a paginated list of all users with filtering and search capabilities
**So that** I can easily find and manage users

**Acceptance Criteria:**
- [ ] Table/datatable displays users with columns: name, email, type, phone, status, actions
- [ ] Pagination controls (page size 15, prev/next, page numbers)
- [ ] Filter tabs: All, Active, Inactive, Trashed
- [ ] Type filter: All, Admins, Users
- [ ] Search bar with debounced name/email search
- [ ] Sortable columns (click header to sort by name, email, created_at)
- [ ] Loading skeleton/spinner during fetch
- [ ] Empty state when no users match filters
- [ ] Error state with retry button on API failure
- [ ] Responsive layout (mobile-friendly table)

**API:** `GET /api/v1/users?page=&limit=&active=&in_active=&trash=&users=&admins=&search=&order_by=&sort=`

**UI States:**
- Loading: Skeleton rows
- Empty: "No users found" illustration + reset filters button
- Error: Error message + retry button
- Edge: Long names/emails truncated with ellipsis, phone numbers formatted

---

## Story FE-ADMIN-USERS-002: Create Admin User Form
**As a** Super Admin
**I want to** create a new admin user with a form
**So that** I can grant admin access to new staff members

**Acceptance Criteria:**
- [ ] Form fields: name (required), email (required, email format), password (required, min 6, confirmation), phone_number (required, unique), roles (multi-select dropdown), is_active (toggle)
- [ ] Client-side validation before submit
- [ ] Show field-level error messages from API (422)
- [ ] Show success toast/notification on creation
- [ ] Reset form after successful creation
- [ ] Disable submit button while loading
- [ ] Handle 403 with "not authorized" message
- [ ] Image upload with preview for avatar

**API:** `POST /api/v1/admin-users/add`

**Validation Edge Cases:**
- Duplicate email → show inline "Email already taken"
- Duplicate phone → show inline "Phone already taken"
- Weak password → show strength indicator
- Empty required fields → highlight + error message

---

## Story FE-ADMIN-USERS-003: Toggle User Activation
**As a** Super Admin
**I want to** activate or deactivate a user from the listing
**So that** I can control who has access to the system

**Acceptance Criteria:**
- [ ] Toggle switch or "Activate/Deactivate" action button per user row
- [ ] Confirmation dialog before toggle ("Are you sure you want to deactivate X?")
- [ ] Optimistic UI update of the toggle state
- [ ] Rollback on API failure with error toast
- [ ] Disabled toggle for current user (self)
- [ ] Disabled toggle for super_admin users (cannot deactivate active super_admin)
- [ ] Show success toast on completion

**API:** `PUT /api/v1/admin-users/update-activation`

**UI States:**
- Active user: green badge + "Deactivate" action
- Inactive user: gray badge + "Activate" action
- Self row: tooltip "Cannot modify your own status"

---

## Story FE-ADMIN-USERS-004: Delete, Restore, and Force Delete Users
**As a** Super Admin
**I want to** soft-delete, restore, and permanently delete users
**So that** I can manage user lifecycles

**Acceptance Criteria:**
- [ ] "Delete" action on active user → soft delete → moves to trash
- [ ] "Restore" action on trashed user → restores to active
- [ ] "Delete Forever" action on trashed user → permanent delete
- [ ] Confirmation dialog for each action with user name
- [ ] "Delete Forever" shows warning: "This action cannot be undone"
- [ ] Disabled delete/force-delete for self and super_admin users with tooltip
- [ ] After delete, user row moves to trashed filter
- [ ] Success toast on each action
- [ ] Handle 400 error: "Cannot delete super admin or self"

**API:**
- `DELETE /api/v1/admin-users/delete/{id}`
- `PUT /api/v1/admin-users/restore/{id}`
- `DELETE /api/v1/admin-users/delete-forever/{id}`

**UI States:**
- Active user row: [Delete] [Edit]
- Trashed user row: [Restore] [Delete Forever] (styled with danger colors)
- Self row: all delete actions disabled with tooltip "Cannot delete yourself"
- Super admin row: all actions disabled (except for other super admins)

---

## Story FE-ADMIN-USERS-005: Edit User Profile Form
**As a** Super Admin
**I want to** edit user profile details
**So that** I can update user information

**Acceptance Criteria:**
- [ ] Pre-populated form with current user data
- [ ] Editable fields: name, email, avatar, profile, address
- [ ] Email field validates uniqueness ignoring current user (regression: BUG-3)
- [ ] Avatar upload with image preview and crop
- [ ] Address section with dynamic address fields
- [ ] Client-side validation
- [ ] Handle 422 with field-level errors
- [ ] Success toast on save

**API:** `PUT /api/v1/users/{id}`

**Edge Cases:**
- Submit with same email → should succeed (BUG-3 regression)
- Submit with another user's email → should fail with inline error
- Submit with no changes → should succeed (no-op)

---

## Story FE-ADMIN-USERS-006: User Detail Page
**As a** Super Admin
**I want to** view a single user's full details
**So that** I can see all information about a user

**Acceptance Criteria:**
- [ ] Profile card with: name, email, type, phone_number, status, created_at, updated_at
- [ ] Admin users show: assigned roles and permissions
- [ ] Regular users show: addresses
- [ ] All resource fields present: id, name, email, email_verified_at, is_active, type, phone_number (regression: BUG-7)
- [ ] Back button to user listing
- [ ] Quick actions: Edit, Delete, Toggle Activation
- [ ] Loading state
- [ ] 404 handling with "User not found"

**API:** `GET /api/v1/users/{id}`

---

## Story FE-ADMIN-USERS-007: "My Profile" Page
**As a** any authenticated user
**I want to** view and edit my own profile
**So that** I can manage my personal information

**Acceptance Criteria:**
- [ ] GET `/api/v1/me` loads current user data
- [ ] Display: name, email, role, profile, wallet, addresses
- [ ] Allow editing own name, email, avatar, password
- [ ] Show current role badge (customer, staff, etc.)
- [ ] Handle 401 → redirect to login

**API:** `GET /api/v1/me`, `PUT /api/v1/users/{id}`

---

## Story FE-ADMIN-USERS-008: Add Points to User Wallet
**As a** Super Admin
**I want to** add loyalty points to a user's wallet
**So that** I can reward customers

**Acceptance Criteria:**
- [ ] Input: customer_id (or search/select user), points amount
- [ ] Allow both positive and negative values
- [ ] Show current wallet balance before/after
- [ ] Success toast with new balance
- [ ] Handle 422 for missing fields

**API:** `POST /api/v1/add-points`

---

## Story FE-ADMIN-USERS-009: Ban/Activate User Actions
**As a** Super Admin
**I want to** ban or activate users from the listing
**So that** I can quickly manage user access

**Acceptance Criteria:**
- [ ] "Ban User" action that deactivates and revokes tokens
- [ ] "Activate User" action that re-enables
- [ ] Cannot ban/activate self (403 guard)
- [ ] Confirmation dialog
- [ ] Success toast + UI update

**API:** `POST /api/v1/users/block-user`, `POST /api/v1/users/active-user`

---

## Story FE-ADMIN-USERS-010: API Error Handling (Regression)
**As a** developer
**I want to** ensure all frontend forms handle API errors gracefully
**So that** users see meaningful error messages instead of crashes

**Acceptance Criteria:**
- [ ] 400 errors show the backend's error message (e.g., "Cannot delete super admin or self")
- [ ] 403 errors show "You don't have permission to perform this action"
- [ ] 404 errors show "Resource not found" with navigation back
- [ ] 422 errors show field-level validation messages next to each input
- [ ] 500 errors show "Something went wrong. Please try again." with retry
- [ ] Network errors show "Connection lost. Check your internet."
- [ ] All API calls have timeout handling (default 30s)

**Covered Endpoints:**
- All CRUD operations in stories FE-ADMIN-USERS-001 through FE-ADMIN-USERS-009
- All 5 regression bug scenarios (BUG-1, BUG-3, BUG-4, BUG-6, BUG-7)
