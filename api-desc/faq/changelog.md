# FAQ Module — Changelog

## [1.0.0] — 2026-07-20

### Added
- FAQ CRUD: full create, read, update, delete (soft) with permissions
- Public API: list active FAQs
- Reorder endpoint for drag-and-drop sorting
- Translatable titles and descriptions (en/ar)
- Soft deletes support
- Spatie Sortable integration for order management
- 9 comprehensive test files (56+ tests)
- Comprehensive documentation (`api-desc/faq/`)

### Changed
- N/A (initial comprehensive documentation)

### Fixed
- N/A (initial comprehensive documentation)

## Known Issues

1. **Missing English translation keys** — `resources/lang/en/message.php` lacks FAQ messages (Arabic exists).
2. **GraphQL schema out of sync** — References columns (`shop_id`, `slug`, `faq_type`, `issued_by`, `language`, `translated_languages`) that no longer exist.
3. **Model relations without columns** — `user()` and `shop()` BelongsTo relations defined but columns not in migration.
4. **Public endpoint no pagination** — Returns all active FAQs in one response.
