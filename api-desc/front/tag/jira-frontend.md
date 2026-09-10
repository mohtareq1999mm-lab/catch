# Tag Module — Frontend Jira Tasks

---

## Task 1: Tag Filter Chips — Product Listing Page

**Priority:** High
**Component:** Frontend — Product Filters
**Story Points:** 3

**Description:** Display tags as clickable filter chips on the product listing page. Selecting a tag filters the product results.

**API Endpoint:**
- `GET /api/v1/general/tags`

**Acceptance Criteria:**
- [ ] Fetch all tags on product listing page mount
- [ ] Display as horizontal row of clickable chips/badges
- [ ] Selected chip has active/highlighted state
- [ ] Multiple tags can be selected (OR logic)
- [ ] Tag selection triggers product re-fetch with tag filter param
- [ ] Clear all filters button
- [ ] **Loading state:** Skeleton chips (6-8 rounded rectangles)
- [ ] **Empty state:** Hide tag filter section
- [ ] **Error state:** Hide with console warning

---

## Task 2: Tag Cloud Page

**Priority:** Medium
**Component:** Frontend — Public Tag Cloud
**Story Points:** 3

**Description:** Build a tag cloud page showing all tags with varying sizes based on product count.

**API Endpoint:**
- `GET /api/v1/general/tags`

**Acceptance Criteria:**
- [ ] Display tags in a cloud/wrap layout
- [ ] Tag font size varies based on associated product count (if available)
- [ ] Each tag links to filtered product page (`/products?tag={slug}`)
- [ ] **Loading state:** Skeleton tag shapes with varying widths
- [ ] **Empty state:** "No tags yet" message
- [ ] **Error state:** Error message with retry

---

## Task 3: Tag Listing — Loading, Empty & Error States

**Priority:** High
**Component:** Frontend — State Handling
**Story Points:** 2

**Description:** Handle all non-happy-path states across tag components.

**Acceptance Criteria:**
- [ ] **Tag chips loading:** 6-8 skeleton chips (small rounded rectangles with shimmer)
- [ ] **Tag chips empty:** Section hidden, no layout shift
- [ ] **Tag chips error:** Hidden, console.warn
- [ ] **Tag cloud loading:** 15-20 skeleton tags with varying widths
- [ ] **Tag cloud empty:** "No tags available" centered message
- [ ] **Tag cloud error:** Error with retry button
- [ ] **Tag detail loading:** Skeleton tag detail page
- [ ] **Tag detail 404:** "Tag not found" page
- [ ] **Tag detail error:** Error with retry
