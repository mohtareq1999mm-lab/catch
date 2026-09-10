# Bug Report - Home Feature

## Issue 1: No Dedicated Home Feature Test File

- **Description:** All home-related tests are scattered across ChannelContextTest, FastShippingControllerTest, etc. No focused HomeTest.php exists.
- **Impact:** Low — functional but hard to maintain.

## Issue 2: Minimal Home Endpoint Assertion

- **Test:** `home_endpoint_returns_sections()` (FastShippingControllerTest line 958)
- **Description:** Only asserts 200 OK. Does NOT verify structure, section content, or filtering.
- **Impact:** Low — no regression detection for data structure changes.

## Issue 3: Section Key Mismatch

- **Description:** `availableSections()` returns keys like `active_sliders` but response uses `sliders`. API consumers must translate between filter and response keys.
- **Impact:** Low — confusing but documented.

## Issue 4: Hard-coded Parent Category ID

- **Description:** `getCategoryTree()` defaults to id=1 if no parent_category_id provided. If category 1 is missing or not a root, returns empty.
- **Impact:** Low — works if seed data has category 1 as root.
