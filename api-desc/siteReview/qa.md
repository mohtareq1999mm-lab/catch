# QA Test Cases — Site Reviews Module

## API Functionality

| ID | Test Case | Expected |
|----|-----------|----------|
| SF-01 | Public list approved reviews | 200, only `approved` reviews returned |
| SF-02 | Submit review (authenticated customer) | 201, review created as `pending` |
| SF-03 | Admin list all reviews | 200, paginated with flattened meta |
| SF-04 | Admin filter by status | Only matching status returned |
| SF-05 | Admin view review detail | 200, includes customer + moderator |
| SF-06 | Approve a pending review | 200, status → `approved`, moderator recorded |
| SF-07 | Reject a pending review | 200, status → `rejected`, moderator recorded |

## Validation

| ID | Test Case | Expected |
|----|-----------|----------|
| SV-01 | Submit without rating | 422 |
| SV-02 | Submit rating 0 / 6 / -1 / 'abc' | 422 |
| SV-03 | Submit without comment | 422 |
| SV-04 | Submit without title | 201 (title optional) |
| SV-05 | Submit title > 191 chars | 422 |
| SV-06 | Submit comment > 2000 chars | 422 |

## Authorization

| ID | Test Case | Expected |
|----|-----------|----------|
| SA-01 | Public list without token | 200 (public) |
| SA-02 | Submit review without token | 401 |
| SA-03 | Admin list without token | 401 |
| SA-04 | Admin list without `view-site-reviews` | 403 |
| SA-05 | Approve without `approve-site-reviews` | 403 |
| SA-06 | Reject without `reject-site-reviews` | 403 |
| SA-07 | Approve with `approve-site-reviews` only | 200 on approve, 403 on reject/list |

## Mass-Assignment Guards (Customer)

| ID | Test Case | Expected |
|----|-----------|----------|
| SG-01 | Submit with `status: approved` | Ignored → review stored as `pending` |
| SG-02 | Submit with `status: rejected` | Ignored → stored as `pending` |
| SG-03 | Submit with `moderated_by: 999` | Ignored → null |
| SG-04 | Submit with `moderated_at` | Ignored → null |

## Moderation Lifecycle

| ID | Test Case | Expected |
|----|-----------|----------|
| SM-01 | Approve pending review | status=approved, moderated_by=admin id, moderated_at set |
| SM-02 | Reject pending review | status=rejected, moderator recorded |
| SM-03 | Approve already-approved review | 404, no change |
| SM-04 | Reject already-rejected review | 404, no change |
| SM-05 | Approve then reject (revert) | 404, stays approved |
| SM-06 | Approve/reject missing review | 404 |
| SM-07 | Pending review shows moderator=null in admin response | null |
| SM-08 | Approved/rejected review shows moderator name | Real admin name |

## Public Response Structure

| ID | Test Case | Expected |
|----|-----------|----------|
| SR-01 | Approved review in public response | id, rating, title, comment, customer{id,name}, created_at |
| SR-02 | Pending/rejected NOT in public response | Absent |
| SR-03 | Public response has no status key | Absent |
| SR-04 | Public response has no moderator/moderated_* keys | Absent |
| SR-05 | Public response exposes customer name only | No email publicly |

## Edge Cases (Regression)

| ID | Test Case | Expected |
|----|-----------|----------|
| SE-01 | GET /site-reviews/abc | 404 (not 500) — BUG-SR-001 |
| SE-02 | PATCH /site-reviews/abc/approve | 404 (not 500) — BUG-SR-001 |
| SE-03 | PATCH /site-reviews/abc/reject | 404 (not 500) — BUG-SR-001 |
| SE-04 | GET /site-reviews?limit=-5 | 200 (not 409), per_page=1 — BUG-SR-002 |
| SE-05 | GET /site-reviews?limit=0 | 200, default applied |
| SE-06 | GET /site-reviews?limit=abc | 200, default applied |
| SE-07 | GET /site-reviews?limit=9999 | 200, per_page capped at 100 |
| SE-08 | Admin list 10 reviews (N+1 check) | ≤8 queries |
| SE-09 | GET /site-reviews/999999 | 404 |

## Performance

| ID | Test Case | Expected |
|----|-----------|----------|
| SP-01 | Public list cache | Cached under `site_reviews` tag, 4h TTL |
| SP-02 | Cache flush on approve/reject | Tag flushed so public list refreshes |
| SP-03 | Admin list eager loading | user + moderator eager loaded (no N+1) |

---

## Test Execution

```bash
vendor/bin/phpunit tests/Feature/SiteReviews
# OK (58 tests, 152 assertions)
```
