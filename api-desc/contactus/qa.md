# Contacts — QA Test Plan

## Positive Test Cases

| # | Test Case | Steps | Expected Result |
|---|-----------|-------|-----------------|
| P1 | Public user submits contact | POST /api/v1/contact-us with valid name, email, subject, message | 201, contact created with is_read=false, is_replay=false |
| P2 | Public user submits contact via /contacts | POST /api/v1/contacts with valid data | 201, contact created |
| P3 | Admin lists contacts | GET /api/v1/contacts with valid token and view-contacts permission | 200, paginated contact array |
| P4 | Admin views contact detail | GET /api/v1/contacts/1 | 200, contact returned with is_read=true |
| P5 | Admin replies to contact | POST /api/v1/contacts/1/replay with valid subject and message | 200, new reply record with is_read=true, is_replay=true |
| P6 | Admin deletes single contact | DELETE /api/v1/contacts/1 | 200, contact soft-deleted |
| P7 | Admin deletes all contacts | DELETE /api/v1/contacts/delete-all | 200, all contacts soft-deleted |
| P8 | Admin deletes all read contacts | DELETE /api/v1/contacts/delete-all-read | 200, only read contacts soft-deleted |
| P9 | Filter contacts by read | GET /api/v1/contacts?read=true | 200, only contacts with is_read=true |
| P10 | Filter contacts by unread | GET /api/v1/contacts?unread=true | 200, only contacts with is_read=false |
| P11 | Filter contacts by replay | GET /api/v1/contacts?replay=true | 200, only contacts with is_replay=true |
| P12 | Search contacts | GET /api/v1/contacts?search=test@example.com | 200, matching contacts |
| P13 | Paginate contacts | GET /api/v1/contacts?page=1&limit=5 | 200, 5 items per page with pagination links |
| P14 | Reply creates new record | Count contacts before and after reply | Count increases by 1 |

## Validation Test Cases

| # | Test Case | Payload | Expected Result |
|---|-----------|---------|-----------------|
| V1 | Create without name | `{email, subject, message}` | 422, name required error |
| V2 | Create without email | `{name, subject, message}` | 422, email required error |
| V3 | Create with invalid email | `{name, email: "not-email", subject, message}` | 422, email format error |
| V4 | Create without subject | `{name, email, message}` | 422, subject required error |
| V5 | Create without message | `{name, email, subject}` | 422, message required error |
| V6 | Create with short message | `{name, email, subject, message: "AB"}` | 422, message min 3 |
| V7 | Create name exceeding 255 chars | Name = 256 chars string | 422, name max error |
| V8 | Create email exceeding 255 chars | Email = 256 chars string | 422, email max error |
| V9 | Create subject exceeding 255 chars | Subject = 256 chars string | 422, subject max error |
| V10 | Create message exceeding 5000 chars | Message = 5001 chars string | 422, message max error |
| V11 | Reply without subject | POST replay with only message | 422, subject required |
| V12 | Reply without message | POST replay with only subject | 422, message required |
| V13 | Reply with short message | POST replay with message: "AB" | 422, message min 3 |

## Authorization Test Cases

| # | Test Case | Token | Permission | Expected Result |
|---|-----------|-------|------------|-----------------|
| A1 | Unauthenticated creates contact | None | Public | 201 |
| A2 | Unauthenticated lists contacts | None | — | 401 |
| A3 | Unauthenticated shows contact | None | — | 401 |
| A4 | Unauthenticated deletes contact | None | — | 401 |
| A5 | Unauthenticated deletes all | None | — | 401 |
| A6 | Unauthenticated deletes read | None | — | 401 |
| A7 | Unauthenticated sends reply | None | — | 401 |
| A8 | User with view-contacts lists | Valid | view-contacts | 200 |
| A9 | User without view-contacts lists | Valid | none | 403 |
| A10 | User with update-contact shows | Valid | update-contact | 200 |
| A11 | User without update-contact shows | Valid | none | 403 |
| A12 | User with update-contact replies | Valid | update-contact | 200 |
| A13 | User without update-contact replies | Valid | none | 403 |
| A14 | User with delete-contact deletes | Valid | delete-contact | 200 |
| A15 | User without delete-contact deletes | Valid | none | 403 |
| A16 | User with delete-contact deletes all | Valid | delete-contact | 200 |
| A17 | User with delete-read-contacts deletes read | Valid | delete-read-contacts | 200 |

## Edge Cases

| # | Test Case | Steps | Expected Result |
|---|-----------|-------|-----------------|
| E1 | read + unread both true | GET /api/v1/contacts?read=true&unread=true | 200, empty results array |
| E2 | Empty contact list | GET /api/v1/contacts when no contacts exist | 200, empty data.data array |
| E3 | Reply to soft-deleted contact | Soft-delete contact, then POST /contacts/{id}/replay | 404 |
| E4 | Show soft-deleted contact | Soft-delete contact, then GET /contacts/{id} | 404 |
| E5 | Message with HTML content | POST with `<script>alert('xss')</script>` in message | 201, stored as-is |
| E6 | Message with special characters | POST with emojis and Unicode | 201, stored correctly |
| E7 | Rapid submissions (rate limit) | POST /contact-us 6 times in 1 minute | 6th request = 429 |
| E8 | Bulk delete-all when no contacts exist | DELETE /delete-all with empty table | 200, success |
| E9 | Bulk delete-all-read when no read contacts | All contacts unread, call delete-all-read | 200, success, no change |
| E10 | Empty string fields | POST with empty strings | 422 for each required field |
| E11 | Whitespace-only fields | POST with " " for all fields | 422 for each required field |
| E12 | Reply to already-replied contact | Reply twice to same original contact | Both succeed, 2 new records created |

## Regression Cases

| # | Test Case | Bug | Expected Result |
|---|-----------|-----|-----------------|
| R1 | Contact creation triggers admin notification | Bug 1 | Verification that listener dispatches notification |
| R2 | Bulk delete-all does not hard-delete | Bug 2 | Records remain in database after delete-all |
| R3 | Bulk delete-all-read does not hard-delete | Bug 2 | Read and unread records both remain in database |
| R4 | Create without name returns 422 not 500 | Bug 4 | 422 with name validation error |
| R5 | Soft-deleted contacts excluded from list | General | GET /contacts excludes trashed |
| R6 | Original contact unmodified after reply | General | Original is_read and is_replay unchanged |

## Test Data

### Contact Record
```json
{
  "name": "Test User",
  "email": "test@example.com",
  "subject": "Test Subject",
  "message": "This is a test message body for QA testing purposes."
}
```

### Reply Record
```json
{
  "subject": "RE: Test Subject",
  "message": "Thank you for your message. We will get back to you shortly."
}
```

### Super Admin User (for test setup)
```json
{
  "name": "Super Admin",
  "email": "admin@example.com",
  "password": "password",
  "is_active": true,
  "type": "admin",
  "permissions": ["view-contacts", "update-contact", "delete-contact", "delete-read-contacts"]
}
```

## Test Execution Order

1. Run authentication tests first (A1-A7)
2. Run validation tests (V1-V13)
3. Run positive tests (P1-P14)
4. Run authorization tests (A8-A17)
5. Run edge cases (E1-E12)
6. Run regression tests (R1-R6)

## Expected Test Results Summary

| Type | Total | Expected Pass |
|------|-------|---------------|
| Positive | 14 | 14 |
| Validation | 13 | 13 |
| Authorization | 17 | 17 |
| Edge | 12 | 12 |
| Regression | 6 | 6 |
| **Total** | **62** | **62** |

## Existing Test Coverage

The project has 8 contact test files with 59 tests and 120 assertions. All pass.

| Test File | Tests | Coverage |
|-----------|-------|----------|
| ContactAuthenticationTest | 7 | Auth scenarios |
| ContactAuthorizationTest | 10 | Permission scenarios |
| ContactCrudTest | 6 | CRUD operations |
| ContactRegressionTest | 10 | Regression scenarios |
| ContactReplyTest | 5 | Reply scenarios |
| ContactResourceTest | 7 | Response structure |
| ContactSoftDeleteTest | 7 | Soft delete scenarios |
| ContactValidationTest | 7 | Validation scenarios |
| **Total** | **59** | |
