# Jira - Dashboard Feature (Frontend)

## Epic: Frontend Admin Dashboard

### Story Points Estimate: 8

---

## User Stories

### FE-US-001: Dashboard Home Page
**As** an admin
**I want** a dashboard homepage with KPI cards
**So that** I can see key metrics at a glance

**Acceptance Criteria:**
- Fetches `GET /api/v1/dashboard/overview` on mount
- Displays cards: Total Revenue, Today's Revenue, Orders, Products, Customers
- Loading skeleton, error state with retry
- Auto-refresh every 5 minutes

### FE-US-002: Analytics Charts
**As** an admin
**I want** visual charts for sales, orders, and customers
**So that** I can spot trends

**Acceptance Criteria:**
- Revenue chart (bar/line) from `/dashboard/revenue`
- Sales analytics from `/dashboard/sales`
- Order analytics from `/dashboard/orders`

---

## Frontend Jest Tests

### FE-TS-001: DashboardPage - Overview

**Test Suite:** `DashboardPage.spec.js`

| # | Test | Mock | Assertion |
|---|------|------|-----------|
| 1 | `displays all KPI cards` | 7 KPI fields | All rendered |
| 2 | `formats currency values` | revenue=1000.00 | "$1,000.00" |
| 3 | `handles 401` | 401 | Redirect to login |
| 4 | `handles 429 rate limit` | 429 | Retry after message |
| 5 | `loading skeleton` | Delayed response | Skeleton visible |

---

## Frontend Tasks

| ID | Description | h | Component |
|----|-------------|---|-----------|
| FE-T-001 | Create DashboardPage (KPI cards) | 6 | `DashboardPage.vue` |
| FE-T-002 | Create RevenueChart | 4 | `RevenueChart.vue` |
| FE-T-003 | Create SalesAnalyticsPanel | 4 | `SalesAnalyticsPanel.vue` |
| FE-T-004 | Create OrderStatsWidget | 3 | `OrderStatsWidget.vue` |
| FE-T-005 | Create RecentOrdersTable | 3 | `RecentOrdersTable.vue` |
| FE-T-006 | Create API service layer | 2 | `services/dashboardApi.js` |

## API Routes for Frontend

| Method | Endpoint | Rate Limit | Usage |
|--------|----------|-----------|-------|
| GET | `/api/v1/dashboard/overview` | 60/min | KPI cards |
| GET | `/api/v1/dashboard/revenue` | 60/min | Revenue chart |
| GET | `/api/v1/dashboard/sales` | 60/min | Sales analytics |
| GET | `/api/v1/dashboard/orders` | 60/min | Order analytics |
| GET | `/api/v1/dashboard/recent-orders` | 60/min | Orders table |
| GET | `/api/v1/dashboard/low-stock` | 60/min | Stock alerts |
