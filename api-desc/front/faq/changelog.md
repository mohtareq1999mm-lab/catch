# Changelog - FAQ Feature

All notable changes to the FAQ feature should be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added
- Frequently Asked Questions management with translatable titles and descriptions
- `Faqs` model with translatable `faq_title` and `faq_description` (Spatie Translatable)
- Drag-and-drop reordering via Spatie Sortable
- Soft deletes for safe FAQ removal
- Role-based scoping (Super Admin, Store Owner, Staff)

### Public API (App Layer)
- `GET /api/v1/general/faqs` — List all active FAQs

### Admin API (Marvel Package)
- `GET /api/v1/faqs` — Paginated list with search, sort, role-based filtering
- `POST /api/v1/faqs` — Create FAQ (multi-language title and description)
- `GET /api/v1/faqs/{id}` — Single FAQ by ID
- `PUT /api/v1/faqs/{id}` — Update FAQ
- `DELETE /api/v1/faqs/{id}` — Soft delete FAQ
- `POST /api/v1/faqs/reorder` — Reorder FAQs

### GraphQL
- `faqs` query with pagination, search, orderBy, language, shop_id filters
- `faq` query by ID, slug, or language
- `createFaq` mutation (resolver: FaqMutator@storeFaq)
- `updateFaq` mutation (resolver: FaqMutator@updateFaq)
- `deleteFaq` mutation (resolver: FaqMutator@deleteFaq)

### Infrastructure
- `FaqsRepository` with create, update, reorder operations
- `faqService` for public API listing
- Permission enums: `view-faqs`, `create-faq`, `update-faq`, `delete-faq`
- FAQ seeder with 50 bilingual FAQs (app-level) + 21 FAQs (package-level)
- Permission translations (EN + AR)
- OpenAPI annotations in FaqsController

### Tests
- 9 test files covering CRUD, validation, auth, permissions, translations, soft deletes, resources, reorder, and regression

## [Unreleased - Technical Debt]

- [ ] Add missing English translation keys for FAQ messages
- [ ] Add activity logging (Observer + Job) for FAQ CRUD
- [ ] Add pagination to public FAQ endpoint
