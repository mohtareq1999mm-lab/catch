# Wishlist Module — Frontend Jira Tasks

---

## Task 1: Wishlist Listing Page

**Priority:** High
**Component:** Frontend — Wishlist Page
**Story Points:** 8

**Description:** Build the main wishlist page that lists the current user's saved products.

**API Endpoint:**
- `GET /api/v1/my-wishlists` (paginated, standard `{ data, meta, links }` shape)
- `GET /api/v1/wishlists` (flat array fallback)

**Acceptance Criteria:**
- [ ] Product cards rendered from `data` (thumbnail, name, price, sale price)
- [ ] Pagination controls using `meta` (current_page, last_page, total) and `links`
- [ ] Variant info shown when a saved product has variations (via `variations[].attributes`)
- [ ] Heart icon filled on every listed item
- [ ] Remove action per item (Task 4)
- [ ] **Loading state:** Skeleton product cards
- [ ] **Empty state:** "No saved items yet" with "Browse products" CTA
- [ ] **Error state:** Toast with retry

---

## Task 2: Wishlist Heart Icon — Product Listing / Cards

**Priority:** High
**Component:** Frontend — Product Cards
**Story Points:** 3

**Description:** Heart icon on product cards that reflects wishlist state and toggles on tap.

**API Endpoint:**
- `POST /api/v1/wishlists/toggle`

**Acceptance Criteria:**
- [ ] Initial heart state read from `in_wishlist` field in product payload
- [ ] Tap toggles: optimistic fill/empty, then confirm from response
- [ ] On success: toast "Added to wishlist" / "Removed from wishlist", icon state updates
- [ ] On 401 (guest): redirect to login instead of toggling
- [ ] **Loading state:** Heart shows spinner, button disabled
- [ ] **Error state:** Revert icon, show toast

---

## Task 3: Add to Wishlist — Product Detail Page

**Priority:** High
**Component:** Frontend — Product Detail Page
**Story Points:** 5

**Description:** Add-to-wishlist action on the product detail page, including variant-aware validation.

**API Endpoints:**
- `GET /api/v1/wishlists/in_wishlist/{product_id}` (guest-safe initial state)
- `POST /api/v1/wishlists` (add)
- `POST /api/v1/wishlists/toggle` (alternative)

**Acceptance Criteria:**
- [ ] Heart state initialized via `in_wishlist` (guest-safe — no auth error for guests)
- [ ] For variable products, require a variant selection before add
- [ ] Body for simple product: `{ product_id }`
- [ ] Body for variable product: `{ product_id, product_variant_id }`
- [ ] On success: toast "Added to wishlist", heart fills
- [ ] On 400 duplicate: keep heart filled, toast "Already in wishlist"
- [ ] On 422: show inline validation error (e.g. "Please select a variant")
- [ ] **Loading state:** Heart spinner
- [ ] **Guest handling:** Tapping when guest triggers login redirect

---

## Task 4: Remove from Wishlist

**Priority:** Medium
**Component:** Frontend — Wishlist Page / Product Cards
**Story Points:** 2

**Description:** Remove a saved product from the wishlist.

**API Endpoints:**
- `DELETE /api/v1/wishlists/{product_id}` (simple products)
- `DELETE /api/v1/wishlists/{product_id}?product_variant_id={id}` (variant items)

**Acceptance Criteria:**
- [ ] Heart/trash icon on each wishlist item
- [ ] When removing a variant item, always send the matching `product_variant_id` (required — otherwise the variant entry is not removed and a 404 is returned)
- [ ] On success: item fades out, list count updates
- [ ] On 404: item not present — refresh list
- [ ] On error: toast with retry
- [ ] **Loading state:** Item shows spinner during delete
- [ ] **Empty state (last item removed):** Show empty wishlist view

---

## Task 5: Wishlist Count / Badge

**Priority:** Medium
**Component:** Frontend — Global Header
**Story Points:** 2

**Description:** Wishlist icon with count badge in the app header.

**API Endpoint:**
- `GET /api/v1/my-wishlists?limit=1` (use `meta.total` as the count)

**Acceptance Criteria:**
- [ ] Wishlist icon in header with count badge
- [ ] Count sourced from `meta.total` (cheap `limit=1` request)
- [ ] Count updates after add/remove/toggle actions (Tasks 2-4)
- [ ] Count hidden for guests
- [ ] **Loading state:** Badge shows "…" until loaded

---

## Task 6: Guest Handling & Auth Flow

**Priority:** High
**Component:** Frontend — Shared
**Story Points:** 3

**Description:** Consistent behavior for unauthenticated users across all wishlist touchpoints.

**API Endpoints:**
- All authenticated wishlist endpoints return 401 for guests
- `GET /api/v1/wishlists/in_wishlist/{product_id}` is public (returns `false`)

**Acceptance Criteria:**
- [ ] Guests always see an empty/unfilled heart (from `in_wishlist = false`)
- [ ] Tapping any add/toggle/remove action for a guest redirects to login and preserves the intended action
- [ ] After login, redirect back and continue the intended wishlist action
- [ ] On any 401 mid-session: clear stored token and redirect to login
- [ ] Header wishlist badge hidden for guests
