# FAQ Module — Session Continuation Prompt

Copy the block below into a new opencode session to continue the FAQ pipeline with the full context:

---

```
You are continuing work on the meem-commerce Laravel project at D:\meem-commerce.

## Module: FAQs (faq)

## What Has Been Done (13 Documentation Files)
All files in api-desc/faq/ are complete:
- README.md, backend.md, api.md, database.md, flow.md
- bug-report.md, changelog.md, frontend.md
- jira.md, jira-frontend.md, qa.md, test-cases.md
- CONTINUE.md

## Architecture Summary
- Standard CRUD module: Controller → Repository → Model
- Translatable: faq_title + faq_description (en/ar via Spatie HasTranslations)
- Soft deletes via Laravel SoftDeletes trait
- Sortable via Spatie SortableTrait (order column)
- No events, listeners, jobs, observers, or media uploads
- Authorization via permission-based middleware only (no Policy)
- ~56+ tests across 9 test files

## Key Files
- Admin Controller: packages/marvel/src/Http/Controllers/FaqsController.php
- Public Controller: app/Http/Controllers/Api/General/FAQController.php
- Model: packages/marvel/src/Database/Models/Faqs.php (HasTranslations, SoftDeletes, SortableTrait)
- Repository: packages/marvel/src/Database/Repositories/FaqsRepository.php
- Service: app/Services/General/faqService.php
- Create Request: packages/marvel/src/Http/Requests/CreateFaqsRequest.php
- Update Request: packages/marvel/src/Http/Requests/UpdateFaqsRequest.php
- Admin Resource: packages/marvel/src/Http/Resources/FaqResource.php
- Public Resource: app/Http/Resources/Faqs/FaqResource.php
- Routes Admin: packages/marvel/src/Rest/Routes.php (lines 229, 393, 535, 616-619, 672)
- Routes Public: routes/api.php (line 66)
- Tests: tests/Feature/Faqs/ (9 files)

## Known Bugs (api-desc/faq/bug-report.md)
- BUG-FAQ-001: English FAQ translation keys missing (OPEN)
- BUG-FAQ-002: GraphQL schema out of sync with migration (OPEN)
- BUG-FAQ-003: User/Shop relations defined but columns missing (OPEN)
- BUG-FAQ-004: Public endpoint missing search/pagination (OPEN)
- BUG-FAQ-005: Index vs show response format differs (BY DESIGN)

## Backend Jira Tasks (api-desc/faq/jira.md)
1. Add English FAQ translation keys (OPEN)
2. Sync GraphQL schema with current migration (OPEN)
3. Document user/shop relationship inconsistency (OPEN)
4. Comprehensive test suite (DONE)
5. Verify all FAQ endpoints work end-to-end (OPEN)
6. Add search to public FAQ endpoint (OPEN)
7. Add pagination to public FAQ endpoint (OPEN)

## Frontend Jira Tasks (api-desc/faq/jira-frontend.md)
1. Admin FAQ listing table with reorder
2. Admin create/edit form
3. Drag-and-drop reorder
4. Public FAQ accordion page
5. Delete confirmation dialog
6. Loading/empty/error states
7. Multilingual translatable fields

## Next Actions (Recommended Order)
1. Add missing English translation keys: resources/lang/en/message.php
2. Sync GraphQL schema: packages/marvel/src/GraphQL/Schema/models/faqs.graphql
3. Fix model relations or add migration columns
4. Add search + pagination to public endpoint
5. Run full test suite: php vendor/bin/phpunit --filter "Faq"
6. Frontend implementation

## Key Technical Constraints
- PREFIX = /api/v1
- Classmap autoloading for packages/marvel
- SQLite in-memory for tests (phpunit.xml)
- PHPUnit 10.0.13, PHP 8.2.28
- Translation constants in packages/marvel/config/constants.php use APP_NOTICE_DOMAIN . 'MESSAGE.*' pattern
- Permission enum values in Permission.php must match middleware strings exactly
```
