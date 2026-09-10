# Coupon Assignment — Admin CRUD

## Overview

Coupon Assignment allows administrators to limit a coupon's usage to specific users. Without any assignments, a coupon is **public** (usable by anyone). Once one or more assignments exist, the coupon becomes **restricted** — only assigned users can consume it.

## Files

| File | Purpose |
|------|---------|
| `api.md` | Full API reference for all 5 endpoints |
| `backend.md` | Implementation details (controller, repository, services) |
| `database.md` | Schema, relationships, migrations |
| `test-cases.md` | Test coverage summary |
| `changelog.md` | Revision history |
| `bug-report.md` | Known issues and resolved bugs |
| `README.md` | This file |

## Quick Links

- **Controller:** `packages/marvel/src/Http/Controllers/CouponAssignmentController.php`
- **Repository:** `packages/marvel/src/Database/Repositories/CouponAssignmentRepository.php`
- **Model:** `packages/marvel/src/Database/Models/CouponAssignment.php`
- **Routes:** `packages/marvel/src/Rest/Routes.php` (lines 720-726)
- **Permissions:** `packages/marvel/src/Enums/Permission.php` (4 constants)
- **Tests:** `tests/Feature/CouponAssignment/`
