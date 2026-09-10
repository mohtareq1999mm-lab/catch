# Role & Permission Management

## Overview

Comprehensive Role-Based Access Control (RBAC) system for managing roles, permissions, and user-role/permission assignments. Built on Spatie Laravel Permission with custom enhancements for translatable display names and multilingual permission labels.

## Route Table

| Method | Endpoint | Handler | Middleware |
|--------|----------|---------|------------|
| GET | `/roles` | `getAllRoles()` | `permission:VIEW_ROLES` |
| POST | `/roles` | `addRole()` | `permission:CREATE_ROLES` |
| GET | `/roles/{id}` | `showRole()` | `permission:VIEW_ROLE` |
| PUT | `/roles/{id}` | `updateRole()` | `permission:UPDATE_ROLES` |
| DELETE | `/roles/{id}` | `destroyRole()` | `permission:DELETE_ROLES` |
| POST | `/users/{userId}/assign-role` | `assignRole()` | `permission:ASSIGN_ROLE` |
| POST | `/users/{userId}/remove-role` | `removeRoleFromUser()` | `permission:REMOVE_ROLE` |
| GET | `/permissions` | `getAllPermissions()` | `permission:SUPER_ADMIN` |
| POST | `/roles/{roleId}/permissions` | `assignPermissionToRole()` | `permission:SUPER_ADMIN` |
| POST | `/users/{userId}/permissions` | `givePermission()` | `permission:SUPER_ADMIN` |
| PUT | `/users/{userId}/permissions` | `syncPermissions()` | `permission:SUPER_ADMIN` |
| DELETE | `/users/{userId}/permissions` | `removePermission()` | `permission:SUPER_ADMIN` |

## Key Files

| File | Purpose |
|------|---------|
| `packages/marvel/src/Http/Controllers/RoleAndPermissionController.php` | Controller logic |
| `packages/marvel/src/Database/Models/Role.php` | Role model (extends Spatie) |
| `packages/marvel/src/Database/Models/Permission.php` | Permission model (extends Spatie) |
| `packages/marvel/src/Enums/Role.php` | System role constants |
| `packages/marvel/src/Enums/Permission.php` | System permission constants |
| `packages/marvel/src/Http/Resources/RoleResource.php` | Role API resource |
| `packages/marvel/src/Http/Resources/PermissionResource.php` | Permission API resource |
| `packages/marvel/src/Rest/Routes.php` | Route definitions |
| `packages/marvel/config/constants.php` | System constants/messages |
| `resources/lang/en/permissions.php` | English permission labels |
| `resources/lang/ar/permissions.php` | Arabic permission labels |
| `tests/Feature/RoleAndPermissionTest.php` | Feature tests |

## Default Roles (Enum)

- `SUPER_ADMIN` (`super_admin`)
- `STORE_OWNER` (`store_owner`)
- `STAFF` (`staff`)
- `CUSTOMER` (`customer`)
- `EDITOR` (`editor`)

## Default Permissions (Enum)

- Store: `VIEW_STORE`, `VIEW_STORES`, `CREATE_STORE`, `UPDATE_STORE`, `DELETE_STORE`, `DELETE_RESTORE_STORE`
- Products: `VIEW_PRODUCT`, `VIEW_PRODUCTS`, `CREATE_PRODUCT`, `UPDATE_PRODUCT`, `DELETE_PRODUCT`
- Orders: `VIEW_ORDER`, `VIEW_ORDERS`, `CREATE_ORDER`, `UPDATE_ORDER`, `DELETE_ORDER`
- Coupons: `VIEW_COUPON`, `VIEW_COUPONS`, `CREATE_COUPON`, `UPDATE_COUPON`, `DELETE_COUPON`
- Tags: `VIEW_TAGS`, `CREATE_TAG`, `UPDATE_TAG`, `DELETE_TAG`
- Types: `VIEW_TYPE`, `VIEW_TYPES`, `CREATE_TYPE`, `UPDATE_TYPE`, `DELETE_TYPE`
- Profile: `VIEW_PROFILE`, `UPDATE_PROFILE`
- Reviews: `VIEW_REVIEWS`, `APPROVE_REVIEWS`, `DELETE_REVIEWS`
- Users: `VIEW_USERS`, `VIEW_USER`, `CREATE_USER`, `UPDATE_USER`, `DELETE_USER`
- Roles: `VIEW_ROLES`, `VIEW_ROLE`, `CREATE_ROLES`, `UPDATE_ROLES`, `DELETE_ROLES`, `ASSIGN_ROLE`, `REMOVE_ROLE`
- Shipping: `VIEW_SHIPPING`, `VIEW_SHIPPINGS`, `CREATE_SHIPPING`, `UPDATE_SHIPPING`, `DELETE_SHIPPING`
- Taxes: `VIEW_TAX`, `VIEW_TAXES`, `CREATE_TAX`, `UPDATE_TAX`, `DELETE_TAX`
- Orders (Status): `ORDER_STATUS`, `ORDER_CHECKOUT`, `ORDER_REFUND`
- Super: `SUPER_ADMIN`
