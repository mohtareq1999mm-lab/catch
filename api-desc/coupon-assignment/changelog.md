# Coupon Assignment — Changelog

---

## Revision 1 — 2026-07-25

### Added

- **Controller:** `CouponAssignmentController` with 5 CRUD methods (index, store, show, update, destroy)
- **Repository:** `CouponAssignmentRepository` with transactional create, scoped find, guarded update, locked delete
- **Requests:** `CouponAssignmentRequest` (store) and `UpdateCouponAssignmentRequest` (update) with full validation
- **Resource:** `CouponAssignmentResource` with computed `remaining` and `is_expired` fields
- **Permissions:** 4 new enum constants in `Permission.php`
- **Routes:** 5 routes in `Routes.php` (lines 720-726) inside `super_admin` group
- **Config:** 7 constants in `constants.php` for response messages and errors
- **Translations:** 7 keys in `resources/lang/en/message.php` and `resources/lang/ar/message.php`
- **Tests:** 43 tests (151 assertions) in `tests/Feature/CouponAssignment/`

### Fixed

- **B1:** `used` field returning null after `create()` — added `fresh()` in repository
- **B2:** Validation error response format — wrapped in standard `{message, errors}` object
