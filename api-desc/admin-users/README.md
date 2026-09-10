# Admin Users Feature

## Overview

The Admin Users feature provides administrative CRUD operations on user accounts. It includes endpoints for listing, viewing, creating, updating, soft-deleting, restoring, force-deleting, and toggling activation of users.

## Routes

| Method | Endpoint | Description | Auth | Permission |
|--------|----------|-------------|------|------------|
| GET | `/api/me` | Get current authenticated user | sanctum | None |
| GET | `/api/users` | List users (paginated, filterable) | sanctum | `view-users` |
| GET | `/api/users/{id}` | Show single user | sanctum | `view-users` |
| PUT | `/api/users/{id}` | Update user | sanctum | `edit-user` |
| POST | `/api/admin-users/add` | Create admin user | sanctum | `create-user` |
| PUT | `/api/admin-users/update-activation` | Toggle user activation | sanctum | `edit-user` |
| DELETE | `/api/admin-users/delete/{id}` | Soft delete user | sanctum | `delete-user` |
| PUT | `/api/admin-users/restore/{id}` | Restore soft-deleted user | sanctum | `restore-user` |
| DELETE | `/api/admin-users/delete-forever/{id}` | Force delete user | sanctum | `delete-user` |

## Key Files

| Layer | File |
|-------|------|
| Controller | `packages/marvel/src/Http/Controllers/UserController.php` |
| Repository | `packages/marvel/src/Database/Repositories/UserRepository.php` |
| Model | `packages/marvel/src/Database/Models/User.php` |
| Resource | `packages/marvel/src/Http/Resources/UserResource.php` |
| Requests | `AdminCreateUserRequest.php`, `UserUpdateRequest.php` |
| Routes | `packages/marvel/src/Rest/Routes.php` |
| Permissions | `packages/marvel/src/Enums/Permission.php` |
| Events | `app/Events/UserRolesUpdated.php` |
| Listener | `app/Listeners/LogUserRolesUpdated.php` |
| Observer | `app/Observers/UserObserver.php` |

## Dependencies

- **Spatie Permission** (`HasRoles` trait) — role/permission assignment
- **Spatie Media Library** (`InteractsWithMedia`) — avatar image management
- **Spatie OneTimePasswords** (`HasOneTimePasswords`) — OTP login
- **Laravel Sanctum** (`HasApiTokens`) — API token authentication
- **Laravel SoftDeletes** — soft delete support
