# Changelog — Admin Users

## [Unreleased]

### Fixed
- **BUG-1**: Missing `UserType` import in `UserController::makeOrRevokeAdmin()` — would crash with class-not-found error.
- **BUG-3**: `UserUpdateRequest` email unique validation ignored current user ID — updating user with same email returned 422.
- **BUG-4**: `UserRepository::storeUser()` did not persist `phone_number` — phone number was silently dropped.
- **BUG-6**: `UserController::destroy()` and related delete methods allowed deleting super_admin users and self — added guard returning 400.
- **BUG-7**: `UserResource` was missing `type` and `phone_number` fields in API response.

### Added
- Test coverage for all admin user CRUD operations in `UserCrudTest.php` and `UserControllerTest.php`.
- Full validation test suite for `POST /admin-users/add` (14 validation scenarios).
- Pagination, search, and filter test coverage for `GET /users`.
- Event assertion tests for `UserRolesUpdated` dispatch on admin toggle.
- Regression tests for all fixed bugs (BUG-1, BUG-3, BUG-4, BUG-6, BUG-7).
