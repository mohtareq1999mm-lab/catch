# Dashboard & Analytics Guide
Version: 1.0

---

# Purpose

هذا المستند يشرح بالتفصيل تصميم وتنفيذ Dashboard و Analytics الخاصة بالموقع اعتمادًا على البيانات الموجودة داخل النظام الحالي فقط.

## Important Rules

- يمنع إنشاء بيانات وهمية (Mock Data).
- يمنع تخمين أي قيمة.
- جميع الإحصائيات تعتمد على Database الفعلية.
- في حالة عدم وجود بيانات يتم إظهار Empty State.
- في حالة فشل API يتم إظهار Error State.
- أي Widget يجب أن يمر بعملية Validation قبل اعتباره صحيحًا.

---

# Dashboard Architecture

```
                    Dashboard
                         │
─────────────────────────┼─────────────────────────
                         │
        KPI Cards (Overview Statistics)
                         │
─────────────────────────┼─────────────────────────
                         │
      Revenue Analytics      Orders Analytics
                         │
─────────────────────────┼─────────────────────────
                         │
     Product Analytics      Customer Analytics
                         │
─────────────────────────┼─────────────────────────
                         │
 Recent Orders        Top Products      Activities
```

Dashboard يجب أن يعطي المدير نظرة عامة خلال أقل من 10 ثوان.

---

# Dashboard Layout

```
+--------------------------------------------------------+

 Date Filter

----------------------------------------------------------

 Revenue
 Orders
 Products
 Categories
 Customers
 Visitors
 Profit
 Refunds

----------------------------------------------------------

 Revenue Chart

----------------------------------------------------------

 Orders Chart

 Product Distribution

----------------------------------------------------------

 Top Products

 Recent Orders

----------------------------------------------------------

 Recent Activities

 Low Stock

----------------------------------------------------------

```

---

# KPI Cards

الصف الأول يحتوي على أهم مؤشرات الأداء.

```
 Revenue

 Total Revenue

 Today Revenue

 Growth %

----------------------------

 Orders

 Total Orders

 Today Orders

 Growth %

----------------------------

 Products

 Total Products

 Active Products

 Out Of Stock

----------------------------

 Categories

 Total Categories

----------------------------

 Customers

 Total Customers

 New Customers

----------------------------

 Visitors

 Total Visitors

 Online Visitors

----------------------------

 Profit

 Gross Profit

 Net Profit

----------------------------

 Refunds

 Refund Count

 Refund Amount
```

---

# Revenue Card

```
                 Revenue Card
                      │
        ┌─────────────┼─────────────┐
        │             │             │
 Today's Revenue   Monthly Revenue  Growth
```

## Database

يعتمد على

```
Orders

Payments

Refunds

Coupons

Taxes
```

## Calculation

```
Revenue

=

Paid Orders

-

Refunds

+

Shipping

-

Discounts
```

## API

```
GET

/dashboard/revenue
```

Response

```json
{
  "todayRevenue":0,
  "monthlyRevenue":0,
  "growth":0
}
```

Validation

```
Database

↓

API

↓

Frontend

↓

Card

↓

PASS
```

إذا اختلفت أي قيمة

```
FAIL

Print Report

Retry
```

---

# Orders Card

```
Orders

↓

Total Orders

↓

Today's Orders

↓

Pending

↓

Completed

↓

Cancelled

↓

Returned
```

Database

```
orders
```

API

```
GET

/dashboard/orders
```

Validation

```
COUNT(Database)

==

API

==

Frontend
```

---

# Products Card

```
Products

↓

Total Products

↓

Published

↓

Draft

↓

Archived

↓

Out Of Stock
```

Database

```
products
```

Validation

```
Products Count

↓

API

↓

Card

↓

PASS
```

---

# Categories Card

```
Categories

↓

Total Categories

↓

Visible

↓

Hidden
```

---

# Customers Card

```
Customers

↓

All Customers

↓

New

↓

Returning

↓

VIP
```

Database

```
users

customers
```

---

# Visitors Card

```
Visitors

↓

Today

↓

This Week

↓

This Month

↓

Online Now
```

لو النظام لا يدعم Visitors

اعرض

```
Not Available
```

ولا تقم بإنشاء بيانات.

---

# Profit Card

```
Revenue

-

Cost

=

Profit
```

```
Profit

↓

Gross Profit

↓

Net Profit
```

---

# Refund Card

```
Refund Requests

↓

Approved

↓

Rejected

↓

Refund Amount
```

---

# Charts Section

```
Dashboard

│

├── Revenue Chart

├── Orders Chart

├── Product Chart

├── Category Chart

├── Order Status

├── Visitors Chart
```

---

# Revenue Chart

الغرض

إظهار تطور المبيعات.

```
Revenue

25K ┤

20K ┤          ●

15K ┤      ●

10K ┤   ●

 5K ┤ ●

    └────────────────────────

      Jan Feb Mar Apr May
```

نوع الرسم

```
Line Chart
```

يعرض

```
Daily

Weekly

Monthly

Yearly
```

API

```
/dashboard/revenue/chart
```

Validation

```
Database

↓

Group By Date

↓

API

↓

Chart

↓

PASS
```

---

# Orders Chart

```
Orders

120 ┤

100 ┤

 80 ┤

 60 ┤

 40 ┤

 20 ┤

    └──────────────
```

نوع الرسم

```
Bar Chart
```

---

# Product Distribution

```
          Products

Electronics 40%

Fashion 25%

Home 20%

Gaming 15%
```

نوع الرسم

```
Doughnut Chart
```

البيانات

```
Products

Group By Category
```

---

# Order Status

```
Pending

Processing

Completed

Cancelled

Returned
```

نوع الرسم

```
Pie Chart
```

```
Pending

20%

Completed

60%

Cancelled

10%

Returned

10%
```

---

# Loading State

قبل وصول البيانات

```
██████████

██████████

██████████
```

يستخدم Skeleton.

---

# Empty State

إذا لم توجد بيانات

```
No Data Available
```

مع زر

```
Refresh
```

---

# Error State

إذا فشل API

```
Unable To Load Data

Retry
```

---

# Refresh Flow

```
Refresh

↓

Call API

↓

Validate

↓

Update UI

↓

Done
```

---

# Dashboard Filters

```
Today

Yesterday

Last 7 Days

Last Month

Last Year

Custom Range
```

كل Widgets تعتمد على نفس الفلتر.

---

# نهاية الجزء الأول

في الجزء الثاني سيتم شرح:

- Analytics بالكامل
- Customer Analytics
- Product Analytics
- Inventory Analytics
- Financial Analytics
- Traffic Analytics
- Conversion Funnel
- Heatmap
- User Journey
- Activity Timeline
- API Mapping الكامل
- Database Mapping الكامل
- Validation Matrix
- Acceptance Criteria
- Testing Checklist

# ==========================================
# Analytics System
# ==========================================

الغرض من صفحة Analytics ليس عرض أرقام فقط، بل تحليل أداء النظام بالكامل ومساعدة الإدارة على اتخاذ القرارات.

يجب أن تعتمد جميع البيانات على قاعدة البيانات الحالية فقط.

لا تستخدم أي Mock Data.

---

# Analytics Architecture

```
                           Analytics
                               │
───────────────────────────────┼──────────────────────────────
                               │
         Sales Analytics        Customer Analytics
                               │
───────────────────────────────┼──────────────────────────────
                               │
        Product Analytics       Inventory Analytics
                               │
───────────────────────────────┼──────────────────────────────
                               │
       Traffic Analytics        Financial Analytics
                               │
───────────────────────────────┼──────────────────────────────
                               │
      Conversion Analytics      Activity Analytics
```

---

# Analytics Layout

```
+--------------------------------------------------------------+

Date Filter

---------------------------------------------------------------

Overview Cards

---------------------------------------------------------------

Revenue Chart

Orders Chart

---------------------------------------------------------------

Product Analytics

Customer Analytics

---------------------------------------------------------------

Traffic Analytics

Inventory Analytics

---------------------------------------------------------------

Payment Analytics

Refund Analytics

---------------------------------------------------------------

Conversion Funnel

User Journey

---------------------------------------------------------------

Activity Timeline

Logs

---------------------------------------------------------------

```

---

# Overview Section

```
Revenue

Orders

Customers

Visitors

Products

Categories

Profit

Refunds

Coupons

Taxes
```

كل Card يجب أن تعرض:

```
Current Value

Previous Period

Growth %

Trend

Last Updated
```

---

# Sales Analytics

الغرض

تحليل المبيعات.

```
Revenue

↓

Daily

↓

Weekly

↓

Monthly

↓

Yearly
```

يعتمد على

```
Orders

Payments

Refunds
```

API

```
GET

/analytics/sales
```

---

# Revenue Trend

```
Revenue

30K ┤

25K ┤

20K ┤

15K ┤

10K ┤

 5K ┤

    └──────────────────────────

      Jan Feb Mar Apr May
```

يعرض

```
Revenue

Growth

Average Revenue

Peak Revenue
```

---

# Average Order Value

```
Revenue

/

Orders

=

Average Order Value
```

API

```
GET

/analytics/aov
```

---

# Product Analytics

```
Products

↓

Views

↓

Add To Cart

↓

Purchased

↓

Revenue
```

---

# Top Products

```
Product A

120 Orders

----------------

Product B

95 Orders

----------------

Product C

75 Orders
```

API

```
GET

/products/top
```

---

# Worst Products

```
Lowest Sales

Lowest Views

Never Purchased
```

الغرض

معرفة المنتجات التي تحتاج تحسين أو حذف.

---

# Product Conversion

```
Viewed

↓

Added To Cart

↓

Purchased
```

مثال

```
1000 Views

↓

350 Cart

↓

120 Orders
```

---

# Category Analytics

```
Category

↓

Products

↓

Orders

↓

Revenue
```

Chart

```
Doughnut
```

---

# Inventory Analytics

```
Inventory

↓

Available

↓

Reserved

↓

Sold

↓

Out Of Stock

↓

Low Stock
```

---

# Low Stock

```
Remaining

< 10
```

يعرض

```
Product Name

Current Stock

Minimum Stock

Supplier
```

---

# Out Of Stock

```
Stock

=

0
```

يجب أن يظهر تنبيه.

---

# Customer Analytics

```
Customers

↓

New

↓

Returning

↓

VIP

↓

Inactive
```

---

# New Customers

```
Created Today

Created This Week

Created This Month
```

---

# Returning Customers

```
Purchased Before

↓

Purchased Again
```

---

# Customer Lifetime Value

```
Total Purchases

-

Refunds

=

Customer Value
```

---

# Traffic Analytics

إذا كان النظام يدعم الزيارات.

```
Visitors

↓

Sessions

↓

Unique Visitors

↓

Bounce Rate

↓

Session Duration
```

---

# Traffic Sources

```
Google

Facebook

Instagram

Direct

Referral

Email
```

Chart

```
Pie
```

---

# Devices

```
Desktop

Mobile

Tablet
```

---

# Browsers

```
Chrome

Safari

Firefox

Edge
```

---

# Countries

```
Egypt

Saudi Arabia

UAE

USA

Germany
```

Chart

```
Bar
```

أو

```
Map
```

---

# Conversion Funnel

```
Visitors

↓

Viewed Product

↓

Added To Cart

↓

Checkout

↓

Payment

↓

Completed
```

مثال

```
10000

↓

4500

↓

1700

↓

1200

↓

980
```

الغرض

معرفة أين يغادر المستخدم.

---

# User Journey

```
Home

↓

Category

↓

Product

↓

Cart

↓

Checkout

↓

Success
```

---

# Payment Analytics

```
Cash

Visa

Mastercard

PayPal

Apple Pay
```

Chart

```
Pie
```

---

# Refund Analytics

```
Refunds

↓

Requested

↓

Approved

↓

Rejected
```

ويحسب

```
Refund Rate
```

---

# Coupon Analytics

```
Coupons

↓

Used

↓

Expired

↓

Revenue Saved
```

---

# Financial Analytics

```
Revenue

↓

Expenses

↓

Taxes

↓

Shipping

↓

Discounts

↓

Profit
```

---

# Profit Calculation

```
Revenue

-

Discounts

-

Refunds

-

Expenses

=

Net Profit
```

---

# Recent Activities

```
Ahmed

Added Product

----------------

Sara

Updated Order

----------------

Ali

Deleted Category
```

API

```
GET

/activities
```

---

# System Logs

```
Login

Logout

Delete

Create

Update

Export
```

---

# Database Mapping

```
Orders
        │
        ├── Revenue
        ├── Orders
        ├── AOV
        └── Conversion

Products
        │
        ├── Inventory
        ├── Categories
        ├── Top Products
        └── Low Stock

Users
        │
        ├── Customers
        ├── Visitors
        └── Activity

Payments
        │
        ├── Revenue
        ├── Refunds
        └── Profit
```

---

# API Mapping

```
Dashboard

↓

/dashboard

Revenue

↓

/dashboard/revenue

Orders

↓

/dashboard/orders

Products

↓

/dashboard/products

Customers

↓

/dashboard/customers

Analytics

↓

/analytics

Activities

↓

/activities
```

---

# Validation Flow

كل Widget يجب أن يمر بالمراحل التالية.

```
Database

↓

Business Logic

↓

API

↓

Frontend

↓

Chart

↓

Validation

↓

PASS
```

إذا حدث اختلاف

```
FAIL

↓

Difference Report

↓

Fix

↓

Retest
```

---

# Data Validation Matrix

```
Revenue

Database

↓

API

↓

UI

↓

PASS

-----------------------

Orders

Database

↓

API

↓

UI

↓

PASS

-----------------------

Products

Database

↓

API

↓

UI

↓

PASS
```

---

# Performance Rules

```
Response Time

< 1 Second
```

```
Chart Render

< 500 ms
```

```
API Timeout

Retry Once
```

---

# Empty State Rules

إذا كانت البيانات فارغة

```
No Data Available
```

مع

```
Refresh
```

ولا تعرض

```
0

إذا كانت القيمة Unknown.
```

---

# Error State Rules

```
API Error

↓

Retry

↓

Still Failed

↓

Show Error

↓

Log Error
```

---

# Refresh Rules

```
User Click Refresh

↓

Fetch Data

↓

Validate

↓

Update Cards

↓

Update Charts

↓

Update Tables

↓

Done
```

---

# Acceptance Criteria

أي Widget يعتبر مكتمل فقط إذا تحقق الآتي.

```
✓ API تعمل.

✓ البيانات صحيحة.

✓ Database = API.

✓ API = Frontend.

✓ Chart يعرض البيانات الصحيحة.

✓ Responsive.

✓ Loading موجود.

✓ Empty State موجود.

✓ Error State موجود.

✓ Dark Mode يعمل.

✓ Performance جيد.

✓ لا يوجد Console Errors.
```

---

# Testing Checklist

لكل Widget يتم اختبار:

```
Positive Cases

Negative Cases

Null Values

Large Dataset

Empty Dataset

Wrong Response

Slow API

Unauthorized

Network Failure

Duplicate Records

Deleted Records

Pagination

Currency

Timezone

Responsive

Dark Mode

Accessibility
```

---

# Final Dashboard Workflow

```
Database
      │
      ▼
Business Logic
      │
      ▼
API
      │
      ▼
Validation
      │
      ▼
Frontend
      │
      ▼
Cards
      │
      ▼
Charts
      │
      ▼
User
      │
      ▼
Review
      │
      ▼
Testing
      │
      ▼
PASS
```

---

# End of Dashboard Analytics Guide

المستند القادم:

```
MASTER_PROMPT.md
```

وسيتضمن Workflow كامل للوكيل، وتقسيم المشروع إلى TODO، مع قواعد تمنع التخمين، وآلية مراجعة واختبار ومقارنة **Database → API → Frontend** قبل اعتبار أي مهمة مكتملة.