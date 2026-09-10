# Coupon Assignment — Implementation Plan

## Steps (independently testable)

| Step | Description | Files | Test |
|------|-------------|-------|------|
| 1 | Add 4 permission constants to `Permission.php` enum | `Permission.php` | Enum values exist |
| 2 | Create `CouponAssignmentRepository` | `CouponAssignmentRepository.php` | Unit test CRUD |
| 3 | Create `CouponAssignmentRequest` + `UpdateCouponAssignmentRequest` | 2 Form Request files | Validation rules |
| 4 | Create package-level `CouponAssignmentResource` | `CouponAssignmentResource.php` | Serialization |
| 5 | Create `CouponAssignmentController` | `CouponAssignmentController.php` | HTTP endpoint test |
| 6 | Register 5 routes in `Routes.php` | `Routes.php` | Route resolution |
| 7 | Add constants to `constants.php` | `constants.php` | Constant exists |
| 8 | Add translation keys to `message.php` (en + ar) | `message.php` | Translation loads |
| 9 | Remove old app-level resource | `app/.../CouponAssignmentResource.php` | Cleanup |
| 10 | Create feature tests (35 tests) | 2 test files | `phpunit` passes |

## Architecture

```
Controller (Marvel package)
  → Repository (Marvel package, extends BaseRepository)
    → Model (CouponAssignment)
  → Resource (Marvel package, extends Resource)

Validation: FormRequests (store + update)
Permissions: 4 new constants in Permission enum
Routes: Inside super_admin group, scope-checked via repository
```
