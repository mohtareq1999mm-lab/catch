# Jira - Shipping Feature (Frontend)

## Epic: Shipping Zone Management UI

### Story Points Estimate: 13

---

## User Stories

### FE-US-001: Countries Management
**As** an admin
**I want** a page to list, create, edit, and delete countries
**So that** I can define shipping zones

**Acceptance Criteria:**
- Data table with search, status filter, pagination
- Modal form for create/edit with EN/AR name fields
- Bulk status toggle (select rows → activate/deactivate)
- Delete with confirmation
- View governorates link per country

### FE-US-002: Governorates Management
**As** an admin
**I want** a page to manage governorates within a country
**So that** I can configure regional shipping

**Acceptance Criteria:**
- Filtered by country (dropdown or from parent page)
- Create/edit form with embedded shipping price fields
- Fast shipping toggle switch per row
- Bulk status toggle
- View cities link per governorate
- Delete blocked with tooltip if has cities

### FE-US-003: Cities Management
**As** an admin
**I want** a page to manage cities within a governorate
**So that** I can define the lowest shipping zones

---

## Frontend Tasks

| ID | Description | h | Component |
|----|-------------|---|-----------|
| FE-T-001 | Create CountriesListPage | 6 | `CountriesListPage.vue` |
| FE-T-002 | Create CountryFormModal | 4 | `CountryFormModal.vue` |
| FE-T-003 | Create GovernoratesListPage | 6 | `GovernoratesListPage.vue` |
| FE-T-004 | Create GovernorateFormModal | 6 | `GovernorateFormModal.vue` |
| FE-T-005 | Create CitiesListPage | 4 | `CitiesListPage.vue` |
| FE-T-006 | Create CityFormModal | 3 | `CityFormModal.vue` |
| FE-T-007 | Create API service | 2 | `services/shippingApi.js` |

## API Routes

| Method | Endpoint | Permission |
|--------|----------|-----------|
| GET | `/api/v1/countries` | view-country |
| POST | `/api/v1/countries` | create-country |
| GET/PUT/DELETE | `/api/v1/countries/{id}` | view/update/delete-country |
| GET | `/api/v1/countries/{id}/governorates` | view-country |
| POST | `/api/v1/countries/change-status` | update-country |
| GET | `/api/v1/governorates` | view-governorate |
| POST | `/api/v1/governorates` | create-governorate |
| GET/PUT/DELETE | `/api/v1/governorates/{id}` | view/update/delete-governorate |
| GET | `/api/v1/governorates/{id}/cities` | view-governorate |
| PUT | `/api/v1/governorates/change-status` | update-governorate |
| PUT | `/api/v1/governorates/{id}/fast-shipping` | update-governorate |
| GET | `/api/v1/cities` | view-city |
| POST | `/api/v1/cities` | create-city |
| GET/PUT/DELETE | `/api/v1/cities/{id}` | view/update/delete-city |
