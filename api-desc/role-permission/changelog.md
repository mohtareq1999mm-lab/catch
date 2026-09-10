# Changelog — Role & Permission

---

## [1.0.0] — Initial Implementation

**Date:** Not specified (initial feature)

### Added

- `RoleAndPermissionController` with 12 endpoints
- Role CRUD (list, create, show, update, delete)
- User role assignment and removal
- Permission listing (paginated)
- Role-permission assignment
- User direct permission management (give, sync, remove)
- `RoleResource` and `PermissionResource` API resources
- Role model with translatable `display_name`
- Permission model with custom `users()` relation
- Permission labels via translation files (English + Arabic)
- Constants for all error/success messages
- Spatie Laravel Permission integration
- Middleware permission checks on all endpoints
- `ClearUserCacheById` job dispatched on role assignment/removal
- Role enum (`SUPER_ADMIN`, `STORE_OWNER`, `STAFF`, `CUSTOMER`, `EDITOR`)
- Permission enum (40+ system permissions)
- Feature tests (803 lines)

### Known Limitations

- No Service/Repository layer — logic in controller
- No Form Request classes — inline validation
- `syncRoles()` replaces all roles (single-role per user)
- No transaction wrapping for batch permission assignments
- No guard against removing last Super Admin
- No caching for role/permission lists
