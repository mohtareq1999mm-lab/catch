# QA Checklist — Admin Users

## Functional Testing

### GET /api/users
- [ ] Returns paginated response structure
- [ ] `active=true` returns only active users
- [ ] `in_active=true` returns only inactive users
- [ ] `trash=true` returns only soft-deleted users
- [ ] `users=true` returns only type='user'
- [ ] `admins=true` returns only type='admin'
- [ ] `search` matches partial name or email
- [ ] `order_by` and `sort` work correctly
- [ ] Custom `limit` works
- [ ] Page 9999 returns empty data
- [ ] Unauthenticated returns 401
- [ ] Regular user returns 403

### GET /api/users/{id}
- [ ] Returns user with correct fields
- [ ] Admin user loads roles + permissions
- [ ] Regular user loads address
- [ ] Non-existent ID returns 404
- [ ] Unauthenticated returns 401
- [ ] Without VIEW_USERS permission returns 403

### PUT /api/users/{id}
- [ ] SUPER_ADMIN can update any user
- [ ] Regular user can update self (only if they have `edit-user` permission)
- [ ] Regular user cannot update others
- [ ] Update with same email succeeds
- [ ] Update with duplicate email fails (422)
- [ ] Invalid image upload fails (422)
- [ ] Unauthenticated returns 401

### POST /api/admin-users/add
- [ ] Creates user with type='admin'
- [ ] Required: name, email, password, password_confirmation
- [ ] Missing name returns 422
- [ ] Invalid email returns 422
- [ ] Missing password returns 422
- [ ] Short password (< 6) returns 422
- [ ] Missing password_confirmation returns 422
- [ ] Mismatched passwords returns 422
- [ ] Duplicate email returns 422
- [ ] Duplicate phone_number returns 422
- [ ] Invalid role IDs returns 422
- [ ] Valid role IDs assign correctly (single, multiple)
- [ ] Duplicate role IDs deduplicated
- [ ] `is_active` defaults to 0 when not provided
- [ ] `is_active: 0` creates inactive user
- [ ] Without permission returns 403
- [ ] Unauthenticated returns 401

### PUT /api/admin-users/update-activation
- [ ] Toggles is_active (active→inactive, inactive→active)
- [ ] Missing user_id returns 422
- [ ] Non-existent user_id returns 422
- [ ] Cannot deactivate an active super_admin (400)
- [ ] Can deactivate an already-inactive super_admin (toggling back)
- [ ] Without permission returns 403
- [ ] Unauthenticated returns 401

### DELETE /api/admin-users/delete/{id}
- [ ] Soft-deletes regular user
- [ ] Cannot delete super_admin (400)
- [ ] Cannot delete self (400)
- [ ] Non-existent ID returns 404
- [ ] Without permission returns 403
- [ ] Unauthenticated returns 401

### PUT /api/admin-users/restore/{id}
- [ ] Restores soft-deleted user
- [ ] Non-trashed user returns 400
- [ ] Non-existent ID returns 404
- [ ] Without permission returns 403
- [ ] Unauthenticated returns 401

### DELETE /api/admin-users/delete-forever/{id}
- [ ] Permanently removes user
- [ ] Cannot delete super_admin (400)
- [ ] Cannot delete self (400)
- [ ] Non-existent ID returns 404
- [ ] Without permission returns 403
- [ ] Unauthenticated returns 401
- [ ] User must be soft-deleted first

### GET /api/me
- [ ] Returns authenticated user profile
- [ ] Returns role name
- [ ] Unauthenticated returns 401

## Security Testing
- [ ] SQL injection attempts in search parameter
- [ ] XSS in name/email fields
- [ ] Mass assignment protection (only fillable fields)
- [ ] IDOR: regular user cannot access other users via ID
- [ ] Rate limiting on auth routes (10/min)

## Response Structure
- [ ] All success responses have `status`, `message`, `success`
- [ ] List responses include full pagination meta
- [ ] Error responses have proper HTTP status codes
- [ ] Translation keys resolve to readable strings

## Activity Logging
- [ ] User creation logs activity
- [ ] User update logs activity (separates status change vs. other changes)
- [ ] User deletion logs activity
- [ ] User restore logs activity
- [ ] User force delete logs activity
- [ ] Role changes log activity via `UserRolesUpdated` event
