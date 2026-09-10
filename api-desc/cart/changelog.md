# Cart Module — Changelog

> Format: Changes · Reasons · Affected Files · Migration Notes · Regression Impact · Deployment Notes · Rollback Notes.

---

## [1.0.4] — 2026-08-04 — Documentation Audit (Revision 4)

### Changes
- Full API investigation of all 7 cart endpoints; re-synced all `api-desc/cart/` documentation with actual source code.
- Corrected documentation-only inaccuracies that did NOT match the code:
  - `GET /cart` reads `limit` (not `per_page`).
  - `PUT /update-item` requires `item.operation` (`increment`/`decrement`) — was undocumented.
  - Clear-cart coupon warning is **HTTP 200 + `success: true`** (was documented as 400).
  - `POST /bulk-items` returns **201** with `cart`, `skipped_product_ids`, `failed_items` (was documented as 200 with partial shape).
  - Cart routes live at `Routes.php` lines **149-157** (was documented as 160-168).
  - `coupon` response object matches `CouponResource` (`id, name, slug, code, image{desktop,mobile}, borderColor, borderless`).
  - `product` in cart item response is `{ id, name, slug, thumbnail }` (thumbnail, not an image object).
  - Cart is **one per user** (`carts.user_id` UNIQUE).
- Updated project state files: `docs/production-status.md`, `docs/feature-dependencies.md`, `docs/regression-matrix.md`, `docs/production-history.md`.

### Reasons
- Documentation had drifted from the implementation (routes, request fields, response codes, response shapes).
- Frontend teams depend on accurate contract documentation to build against.

### Affected Files (documentation only — no application code changed)
- `api-desc/cart/README.md`
- `api-desc/cart/api.md`
- `api-desc/cart/backend.md`
- `api-desc/cart/frontend.md`
- `api-desc/cart/flow.md`
- `api-desc/cart/database.md`
- `api-desc/cart/test-cases.md`
- `api-desc/cart/qa.md`
- `api-desc/cart/jira.md`
- `api-desc/cart/jira-frontend.md`
- `api-desc/cart/changelog.md`
- `api-desc/cart/bug-report.md`
- `docs/production-status.md`
- `docs/feature-dependencies.md`
- `docs/regression-matrix.md`
- `docs/production-history.md`

### Migration Notes
- None. No schema changes, no migrations, no application code changes.

### Regression Impact
- **None expected** — documentation only.
- Test re-run is **blocked** by a global test-bootstrap error (`Class "Role" not found` at `Routes.php:699`). Last recorded CartApiTest result: 61/65 (4 pre-existing failures). Must be re-verified after the bootstrap is fixed.

### Deployment Notes
- No deploy required for code (docs only). If docs are published to a portal, redeploy the docs site.

### Rollback Notes
- Revert `git` changes to the `api-desc/cart/` and `docs/` files. No data/state rollback required.

---

## [1.0.0] — 2026-07-28 — Initial Implementation

### Changes
- Cart API with 7 endpoints (list, add, show, update, delete-item, clear, bulk-add)
- `CartCreateRequest` and `CartUpdateRequest` Form Requests with validation
- `CartResource` and `CartItemResource` for API response transformation
- `CartInventoryService` with inventory reservation, release, finalize, and expiration
- Row-level locking (`lockForUpdate`) for inventory concurrency
- Rate limiting on cart endpoints (20 req/min per user)
- Cart expiration system with scheduled commands (TTL = 3 days)
- Shipping method separation: SCHEDULED and FAST items in dedicated sections
- Price snapshotting at reservation time via `ProductPricingService`
- Coupon support: apply, calculate discount, clear on last item delete
- Promotion support: eligibility check, discount application, gift items
- Bulk add with soft-deleted product filtering (`skipped_product_ids`)
- Comprehensive documentation (`api-desc/cart/`)

### Fixed
- Cart item `total_price` rounding to 2 decimal places in `CartItemResource` and `CartRepository` (SQL `ROUND()`)
- `ProductVariantResource` `price` and `current_price` rounding to 2 decimal places
- Cart soft-deleted product handling — product returns as null instead of 500 error
- Foreign key cascades changed from `cascadeOnDelete()` to `nullOnDelete()` for `user_id` and `product_id`

### Migration Notes
- `2026_07_17_000001_fix_cart_foreign_key_cascades.php` — changes FK cascades (skips SQLite).

### Regression Impact
- Cart, Checkout, Orders.

### Deployment Notes
- Run migrations; clear route cache; register scheduler for `carts:expire`.

### Rollback Notes
- Revert migration + code changes; restore previous FK behavior only if no cart data relies on nullOnDelete.

---

## Known Issues (unchanged, tracked in `bug-report.md`)

1. **BUG-CART-001 (Critical)** — Dual inventory: `CartInventoryService` reservation vs `OrderRepository::deductStock()` with no coordination; oversell risk.
2. **BUG-CART-002 (High)** — `finalizeItemsByShippingMethod()` deletes the non-finalized shipping group's items instead of preserving them.
3. **BUG-CART-003 (High)** — Price snapshotted at reservation, not re-validated at checkout.
4. **BUG-CART-005 (Low)** — `CART_TTL_DAYS = 3` hardcoded.
5. **BUG-CART-006 (Medium)** — Expire chunk query has no global lock.
6. **BUG-CART-007 (Medium)** — `expireCart()` lacks `status !== 'active'` guard.
7. **BUG-CART-008 (Low)** — Duplicate expire command (`ExpireAbandonedCarts`) unscheduled/orphan.
8. **BUG-CART-009 (Low)** — No max quantity validation on add/update requests.
