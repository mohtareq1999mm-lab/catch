# Tag Module — QA Test Cases (Public API)

## Test Files

`tests/Feature/ProductTagTest.php` — contains 3 tests for public tags.

---

## API Functionality Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F1 | List tags | GET /general/tags | 200, array of tags |
| F2 | Get tag by slug | GET /general/tags/organic | 200, tag object |
| F3 | Get tag not found | GET /general/tags/nonexistent | 404 |
| F4 | Empty tag list | No tags in DB | 200, empty array |

---

## Response Structure Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| S1 | Response structure | status, message, success, data | Correct keys |
| S2 | Tag object | id, name, slug, details, image, icon, language, translated_languages, type | Correct types |
| S3 | Type object | type has id, name, slug | Object or null |

---

## N+1 Query Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| Q1 | List tags N+1 | 10 tags, count queries | ≤ 2 (tags + 1 type query if eager loaded) |

---

## Regression Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| R1 | Tag with products | Tag with associated products | Type loaded correctly |
| R2 | Tag without type | type_id is null | type is null |
| R3 | Translated language | Tag available in multiple languages | translated_languages populated |
