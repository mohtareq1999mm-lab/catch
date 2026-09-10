# Test Cases - Banner Feature

## Current Coverage

**No dedicated test files found for Banner CRUD.**

## Recommended Tests

| # | Test | Priority |
|---|------|----------|
| FT-001 | List returns paginated banners with products | High |
| FT-002 | List filters by active=true | High |
| FT-003 | List ordered by sortable order | Medium |
| FT-004 | Create banner with all fields | High |
| FT-005 | Create validates required title (EN + AR) | High |
| FT-006 | Create validates image_desktop/mobile required | High |
| FT-007 | Create validates image format (jpeg,png,jpg,gif) | High |
| FT-008 | Create validates image max size (2MB) | High |
| FT-009 | Create syncs products | High |
| FT-010 | Create rolls back on image upload failure | Medium |
| FT-011 | Show returns banner with products | High |
| FT-012 | Show returns 404 for non-existent | High |
| FT-013 | Update modifies banner fields | High |
| FT-014 | Update replaces images | High |
| FT-015 | Update syncs products | High |
| FT-016 | Destroy soft deletes | High |
| FT-017 | ChangeStatus toggles status | High |
| FT-018 | Reorder sets new order | High |
| FT-019 | Unauthenticated returns 401 | High |
| FT-020 | Missing permission returns 403 | High |
| FT-021 | Slug auto-generated from EN title | Medium |
| FT-022 | Translation fallback (missing locale) | Medium |
