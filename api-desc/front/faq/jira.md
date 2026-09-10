# Jira - FAQ Feature

## Epic: FAQ Management

### Story Points Estimate: 8

---

## User Stories

### US-001: View FAQs (Public)
**As** a customer
**I want** to view frequently asked questions on the help page
**So that** I can find answers to common questions

**Acceptance Criteria:**
- `GET /api/v1/general/faqs` returns all active FAQs
- Returns translated title and description
- Ordered by sort order

---

### US-002: Admin CRUD - Create FAQ
**As** an admin user
**I want** to create a new FAQ entry
**So that** I can answer common customer questions

**Acceptance Criteria:**
- `POST /api/v1/faqs` with translatable title and description
- Required: faq_title (multi-language), faq_description (multi-language)
- Min 3, max 1000 characters per locale
- Unique translation validation
- Returns created FAQ

---

### US-003: Admin CRUD - Update/Delete FAQ
**As** an admin user
**I want** to update or remove FAQs
**So that** I can keep information current

**Acceptance Criteria:**
- `PUT /api/v1/faqs/{id}` partial update
- `DELETE /api/v1/faqs/{id}` soft deletes
- Status toggle supported

---

### US-004: Reorder FAQs
**As** an admin user
**I want** to reorder FAQs via drag-and-drop
**So that** I can prioritize important questions

**Acceptance Criteria:**
- `POST /api/v1/faqs/reorder` with array of IDs
- Uses Spatie Sortable `setNewOrder()`
- Requires `update-faq` permission

---

### US-005: View FAQs via GraphQL
**As** a GraphQL client
**I want** to query FAQs with pagination and filtering
**So that** I can integrate FAQs into any frontend

**Acceptance Criteria:**
- `faqs` query with pagination, search, orderBy, language filters
- `faq` query by ID or slug
- `createFaq`, `updateFaq`, `deleteFaq` mutations
- Role-based scoping via `FaqQuery@fetchFaqs`

---

## Tasks

| Task ID | Description | Estimate (h) | Dependencies |
|---------|-------------|-------------|--------------|
| T-001 | Create faqs table migration | 1 | None |
| T-002 | Create Faqs model with relationships | 2 | T-001 |
| T-003 | Create FaqsRepository | 2 | T-002 |
| T-004 | Create FaqsController (Marvel) with CRUD + reorder | 3 | T-003 |
| T-005 | Create FAQController (General/Public) | 1 | T-002 |
| T-006 | Create FormRequests (create, update) | 2 | T-002 |
| T-007 | Create API Resources (Admin + Public) | 1 | T-002 |
| T-008 | Create faqService | 1 | T-002 |
| T-009 | Create GraphQL schema, query, and mutator | 3 | T-002 |
| T-010 | Write translation keys | 1 | None |
| T-011 | Seed FAQ data | 1 | T-001 |
| T-012 | Write tests (9 test files) | 8 | T-001 to T-008 |

---

## Bug Tickets

| Ticket | Description | Priority | Severity |
|--------|-------------|----------|----------|
| BUG-001 | Missing English translation keys for FAQ messages | Medium | Medium |
| BUG-002 | No activity logging for FAQ CRUD operations | Low | Low |
| BUG-003 | Public endpoint has no pagination | Low | Low |
