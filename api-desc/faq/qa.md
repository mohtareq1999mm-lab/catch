# QA Test Cases — FAQ Module

## API Functionality

| ID | Test Case | Expected |
|----|-----------|----------|
| AF-01 | List FAQs with pagination | Returns paginated results with meta |
| AF-02 | Create FAQ with all valid fields | Returns 201 with FAQ data |
| AF-03 | Get FAQ by ID | Returns 200 with FAQ data |
| AF-04 | Update FAQ | Returns 200 with updated data |
| AF-05 | Delete FAQ (soft delete) | Returns 200, FAQ hidden from index |
| AF-06 | Reorder FAQs | Returns 200, order column updated |
| AF-07 | Public list active FAQs | Returns only status=1 FAQs |

## Validation

| ID | Test Case | Expected |
|----|-----------|----------|
| AV-01 | Create without faq_title | 422, faq_title required |
| AV-02 | Create without faq_description | 422, faq_description required |
| AV-03 | Create with faq_title.* too short (< 3) | 422, min:3 |
| AV-04 | Create with faq_title.* too long (> 1000) | 422, max:1000 |
| AV-05 | Create with duplicate faq_title | 422, title already taken |
| AV-06 | Update with partial data (only status) | 200, only status changed |
| AV-07 | Reorder with invalid faq ID | 422, ID must exist |
| AV-08 | Update with same title (self) | 200, unique ignores self |

## Authorization

| ID | Test Case | Expected |
|----|-----------|----------|
| AA-01 | List FAQs without token | 401 |
| AA-02 | List FAQs without permission | 403 |
| AA-03 | Create FAQ without permission | 403 |
| AA-04 | Update FAQ without permission | 403 |
| AA-05 | Delete FAQ without permission | 403 |
| AA-06 | Public list without auth | 200 (public) |
| AA-07 | User with view-only can index and show | 200 on GET, 403 on POST/PUT/DELETE |
| AA-08 | User with no FAQ permissions | 403 on all |

## Soft Delete

| ID | Test Case | Expected |
|----|-----------|----------|
| AS-01 | Delete FAQ → not in index | Hidden from index |
| AS-02 | Show deleted FAQ | 404 |
| AS-03 | Update deleted FAQ | 404 |
| AS-04 | Multiple soft deletes work | Each subsequent delete succeeds |
| AS-05 | Force delete removes permanently | Record gone from DB |

## Translation

| ID | Test Case | Expected |
|----|-----------|----------|
| AT-01 | Create FAQ with en + ar translations | Both stored |
| AT-02 | Index returns translated title in current locale | en when locale=en, ar when locale=ar |
| AT-03 | Show returns raw JSON with all locales | Full JSON object |

## Reorder

| ID | Test Case | Expected |
|----|-----------|----------|
| AR-01 | Reorder 3 FAQs to reverse order | order column values updated |
| AR-02 | Reorder with single FAQ | OK |
| AR-03 | Reorder with missing faqs field | 422 |
| AR-04 | Reorder with empty array | 422 |

## Response Structure

| ID | Test Case | Expected |
|----|-----------|----------|
| ARS-01 | Index response has correct envelope | success, message, status, data |
| ARS-02 | Index returns translated string (not raw JSON) | "How to return?" not `{"en":"How to return?"}` |
| ARS-03 | Show response includes id, faq_title, faq_description | All fields present |
| ARS-04 | Response type is JSON | Content-Type: application/json |

## Edge Cases

| ID | Test Case | Expected |
|----|-----------|----------|
| AE-01 | Create with empty faq_title.* | 422 |
| AE-02 | Update with empty request body | 200, no changes |
| AE-03 | Access soft-deleted FAQ directly via ID | 404 |
| AE-04 | Reorder with already-existing order values | New order applied correctly |
| AE-05 | Create 100+ FAQs | All created, orders auto-assigned |
