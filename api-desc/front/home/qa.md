# QA - Home Feature

## Test Matrix

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-HM-001 | Home page returns all sections | 200, all data keys present |
| TC-HM-002 | Filter sections via query param | Only requested sections |
| TC-HM-003 | Filter with invalid section key | 200, ignored |
| TC-HM-004 | Filter with empty sections param | All sections returned |
| TC-HM-005 | Home page with parent_category_id | Scoped correctly |
| TC-HM-006 | Nav data returns category tree | Nested structure |
| TC-HM-007 | Nav data with level=1 | Only root categories |
| TC-HM-008 | Nav data with level=5 (deep) | Full depth if exists |
| TC-HM-009 | Channel header home excludes fast products | Filter applied |
| TC-HM-010 | Unauthenticated access | 200 (public) |

## Manual Test Checklist

- [ ] Verify home page loads with all sections
- [ ] Verify section filtering works
- [ ] Verify category tree renders correctly
- [ ] Verify channel header changes home content
