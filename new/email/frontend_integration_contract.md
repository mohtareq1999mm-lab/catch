# REST Optional Email — Frontend Integration Contract

## 1. Purpose

The REST API customer lifecycle has been updated so that **customer email is optional**.

This document defines exactly how the frontend/mobile application must integrate with the new backend behavior.

This is an **integration contract**, not an implementation proposal.

The backend is already implemented and verified. The frontend must adapt to the actual REST behavior described below.

---

## 2. Core Business Rule

The most important rule is:

```text
EMAIL IS OPTIONAL
PHONE IS REQUIRED FOR NORMAL CUSTOMER REGISTRATION
```

A valid customer can now exist as:

```json
{
  "id": 123,
  "phone_number": "010xxxxxxxx",
  "email": null,
  "email_verified_at": null
}
```

This is a completely valid customer.

The frontend MUST NOT consider:

```text
email === null
```

to mean:

```text
invalid account
incomplete registration
unverified account
authentication failure
```

It means:

```text
The customer chose / registered without an email.
```

---

## 3. Customer Identity Model

Frontend must understand the following identity model:

```text
                    users.id
                       │
                 Customer Identity
                       │
             ┌─────────┴─────────┐
             │                   │
       phone_number            email
          REQUIRED             OPTIONAL
             │                   │
       authentication       contact channel
```

Social login has a separate identity:

```text
provider + provider_user_id
```

Email is NOT the canonical identity.

---

## 4. IMPORTANT FRONTEND RULE

Never write frontend logic like:

```js
if (!user.email) {
    // user is invalid
}
```

or:

```js
if (!user.email) {
    // force email registration
}
```

unless the specific screen/business feature explicitly requires an email.

Instead:

```js
if (user.email) {
    // email available
} else {
    // phone-only customer
}
```

---

## 5. REGISTER FLOW

## Endpoint

```text
POST /api/v1/register
```

Normal registration now follows:

```text
Register Screen
      │
      ├── Phone Number REQUIRED
      ├── Password REQUIRED
      ├── Name / required fields
      └── Email OPTIONAL
              │
              ▼
        POST /register
              │
        ┌─────┴─────┐
        │           │
     Email       No Email
     provided      provided
        │           │
        ▼           ▼
   User created  User created
   with email    email = null
        │           │
        ▼           ▼
 Email-specific  No email OTP
 OTP/behavior    is attempted
        │           │
        └─────┬─────┘
              ▼
        Registration
          successful
```

---

## 6. REGISTER UI

Email field must no longer be displayed as mandatory.

Instead:

```text
Phone Number *
Email
Password *
Confirm Password *
...
```

Email should be visually marked as optional if the UI convention supports it:

```text
Email (Optional)
```

Do NOT put:

```text
*
```

on the email field.

---

## 7. REGISTER REQUEST

### With email

```json
{
  "phone_number": "01012345678",
  "email": "customer@gmail.com",
  "password": "password"
}
```

Backend stores:

```text
users.email = customer@gmail.com
```

### Without email

Preferred request:

```json
{
  "phone_number": "01012345678",
  "password": "password"
}
```

The frontend does NOT need to send:

```json
"email": null
```

unless the existing request abstraction always serializes optional fields.

Both behaviors are supported.

---

## 8. REGISTER VALIDATION

Frontend validation:

```text
phone_number → REQUIRED
password → REQUIRED
email → OPTIONAL
```

If email is supplied:

```text
must be a valid email
```

If email is omitted:

```text
DO NOT show an email validation error
```

If backend returns:

```text
422
email validation error
```

only then show the email validation error.

Do not preemptively force an email.

---

## 9. REGISTRATION SUCCESS

After registration, the frontend should use the actual returned user state.

For a phone-only user:

```json
{
  "email": null,
  "email_verified_at": null,
  "phone_number": "01012345678"
}
```

This is valid.

Do NOT redirect the customer to:

```text
"Verify your email"
```

if:

```text
email === null
```

---

## 10. EMAIL VERIFICATION FLOW

There are now two distinct states.

## State A — User has email

```text
email != null
email_verified_at == null
```

Meaning:

```text
Email exists but is not verified.
```

The application may show the existing email verification UX where applicable.

---

## State B — User has no email

```text
email == null
email_verified_at == null
```

Meaning:

```text
There is no email to verify.
```

This is NOT an unverified-email state.

Frontend MUST NOT show:

```text
Please verify your email
```

for this state.

---

## 11. AUTHENTICATION FLOW

The login endpoint supports customer authentication using phone/email according to the backend contract.

For phone-only customers:

```text
Phone
+
Password
      │
      ▼
POST /api/v1/token
      │
      ▼
Authentication successful
      │
      ▼
Receive token
```

The response envelope contains the token under the API's existing `data` object.

Conceptually:

```json
{
  "status": "...",
  "message": "...",
  "success": true,
  "data": {
    "token": "..."
  }
}
```

Frontend must read the token according to the existing API response abstraction.

Do NOT assume:

```js
response.token
```

if the application's API wrapper already uses:

```js
response.data.token
```

---

## 12. /ME FLOW

After authentication:

```text
GET /api/v1/me
```

A phone-only customer can return:

```json
{
  "email": null,
  "email_verified_at": null,
  "phone_number": "01012345678"
}
```

Frontend state management must allow:

```text
email: null
```

without throwing.

---

## 13. USER MODEL

Frontend TypeScript / Dart / Kotlin / Swift / etc. models MUST treat email as nullable.

Conceptually:

```ts
type User = {
    id: number;
    phone_number: string;
    email: string | null;
    email_verified_at: string | null;
}
```

NOT:

```ts
email: string;
```

and NOT:

```ts
email?: string;
```

if the API explicitly returns:

```json
"email": null
```

The correct semantic type is:

```text
string | null
```

---

## 14. OTP FLOW

OTP now has two possible customer channels.

```text
                    OTP
                     │
              ┌──────┴──────┐
              │             │
            Email          Phone
              │             │
       email exists      phone exists
              │             │
              ▼             ▼
        Email OTP       Phone OTP
```

---

## 15. PHONE-ONLY OTP

For:

```text
email = null
```

the frontend must use the phone OTP path.

Conceptually:

```text
Phone-only customer
       │
       ▼
Send OTP
       │
       ▼
Phone number
       │
       ▼
OTP entered
       │
       ▼
Verify phone OTP
       │
       ▼
Authenticated / next flow
```

Do NOT attempt:

```text
Mail OTP
email OTP
email verification
```

for a phone-only customer.

---

## 16. OTP UI DECISION

The frontend should decide the OTP channel based on the actual available identifier.

Conceptually:

```js
if (user.email) {
    // email-based flow where required
} else {
    // phone-based flow
}
```

Do NOT use:

```js
if (!user.email) {
    throw new Error("Email required");
}
```

---

## 17. CART FLOW

Email has no role in cart identity.

The flow is:

```text
Authenticated User
       │
       ▼
     Cart
       │
       ▼
cart.user_id
```

A phone-only customer can:

```text
add product
update quantity
remove product
read cart
```

without an email.

Frontend does not need to send email to identify the cart.

---

## 18. CHECKOUT

There are two checkout flows:

```text
Normal Checkout
Fast Checkout
```

Both support:

```text
user_email = null
```

The frontend MUST NOT force email before checkout.

---

## 19. NORMAL CHECKOUT FLOW

Conceptually:

```text
Cart
 │
 ▼
Checkout
 │
 ├── Address
 ├── Governorate
 ├── Delivery/Pickup
 ├── Shipping
 ├── Payment Method
 └── Email OPTIONAL
          │
          ▼
      Create Order
          │
          ▼
      Order Created
```

For phone-only customer:

```text
users.email = null
orders.user_email = null
```

This is valid.

---

## 20. CHECKOUT EMAIL FIELD

If the checkout screen has:

```text
Email
```

it must be optional.

Do NOT block:

```text
Continue
Place Order
Create Order
```

only because:

```text
email === null
```

unless a specific payment provider explicitly requires an email and the backend contract says that provider cannot use the fallback.

The backend currently handles the REST online gateway boundary.

---

## 21. ORDER MODEL

Frontend order models must allow:

```ts
user_email: string | null;
```

Do not assume:

```ts
user_email: string;
```

Example:

```json
{
  "id": 123,
  "user_id": 45,
  "user_email": null
}
```

is valid.

---

## 22. COD FLOW

Phone-only customers can use COD where the existing business rules allow COD.

Example:

```text
Phone-only customer
       │
       ▼
Cart
       │
       ▼
Checkout
       │
       ▼
Delivery
       │
       ▼
COD
       │
       ▼
Order Created
```

Email is not required for COD.

Do not add frontend email validation specifically for COD.

---

## 23. PICKUP BUSINESS RULE

Do not change existing payment/fulfillment business rules.

For example, if:

```text
COD + Pickup
```

is not allowed by the backend, the frontend must continue handling the backend's existing error.

Do NOT interpret that error as an email problem.

---

## 24. COUPONS

Coupons are tied to:

```text
user_id
```

not email.

Therefore:

```text
phone-only user
      │
      ▼
Coupon
      │
      ▼
Checkout
      │
      ▼
Order
```

works normally.

Both:

```text
normal coupon
assigned coupon
```

must be supported.

Frontend MUST NOT disable coupon functionality because:

```text
email === null
```

---

## 25. INVOICE

Invoice customer data may contain:

```json
{
  "id": 123,
  "name": "Customer",
  "phone": "01012345678",
  "email": null
}
```

Frontend must render this safely.

Example:

```text
Customer
Phone: 01012345678
Email: —
```

or simply omit the email line.

Never render:

```text
undefined
null
```

to the user.

---

## 26. PDF / INVOICE DISPLAY

The backend invoice/PDF flow accepts:

```text
email = null
```

The frontend does not need to generate a fake email.

Do NOT display:

```text
customer-123@no-email...
```

The gateway fallback email is backend-only.

---

## 27. PAYMENT — VERY IMPORTANT

There are two different concepts:

```text
Customer Email
        ≠
Gateway Contact Email
```

The customer can have:

```text
email = null
```

while an external payment gateway may still receive a technical fallback email.

Example:

```text
Customer:

email = null

Backend → MyFatoorah:

CustomerEmail =
order-123@no-email.meem.local
```

That fallback is:

```text
gateway-only
temporary/derived
not customer identity
not stored in users.email
not stored in orders.user_email
```

---

## 28. FRONTEND MUST NOT USE GATEWAY FALLBACK

The frontend MUST NOT:

```text
generate the fallback
store the fallback
display the fallback
send the fallback as the customer's email
```

This is entirely backend responsibility.

Frontend sends the actual customer email if one exists.

---

## 29. PAYMENT FLOW

For online payment:

```text
Frontend
   │
   ▼
Checkout
   │
   ▼
Create/Process Order
   │
   ▼
Backend Payment Gateway
   │
   ├── real customer email exists
   │        ↓
   │     use real email
   │
   └── email is null
            ↓
       backend creates
       gateway fallback
```

Frontend does not need to know which branch happened.

---

## 30. SOCIAL LOGIN

Social login must NOT depend on provider email being present.

There are two valid cases.

### Provider returns email

```text
Google
 ↓
provider_user_id = X
email = user@gmail.com
 ↓
Backend links/creates user
```

### Provider does not return email

```text
Google
 ↓
provider_user_id = X
email = null
 ↓
Backend creates/links phone/email-less account
```

The backend's canonical social identity is:

```text
provider + provider_user_id
```

NOT:

```text
email
```

---

## 31. SOCIAL LOGIN FRONTEND RULE

Do NOT implement:

```js
if (!socialUser.email) {
    rejectSocialLogin();
}
```

unless a specific provider/platform requirement independently requires it.

The REST backend supports a social account without provider email.

---

## 32. REPEAT SOCIAL LOGIN

For the same:

```text
provider
+
provider_user_id
```

the backend returns the same customer.

Frontend should simply treat the response as a normal successful login.

Do not create a new local account because:

```text
email == null
```

---

## 33. PASSWORD RESET

Email password reset remains email-based.

Therefore:

```text
phone-only customer
```

does not magically gain email password reset.

Do NOT manufacture an email.

If the product provides a phone OTP password-reset flow, use that flow.

If not, display the existing product behavior explaining that email reset requires an email.

Do not tell the user:

```text
Your email is unverified
```

when the actual state is:

```text
email = null
```

---

## 34. FRONTEND STATE MACHINE

The frontend should conceptually model the customer like this:

```text
                    Customer
                       │
             ┌─────────┴─────────┐
             │                   │
          Has Email           No Email
             │                   │
             │                   │
      email != null         email == null
             │                   │
      ┌──────┴──────┐            │
      │             │            │
   Verified      Unverified      │
      │             │            │
      ▼             ▼            ▼
 Normal Email    Verify      Phone-only
 Customer        Email       Customer
```

Important:

```text
No Email
```

is a valid state.

It is NOT an error state.

---

## 35. RECOMMENDED FRONTEND USER FLAGS

Do not persist unnecessary duplicated flags.

Derive state from the backend model.

Conceptually:

```ts
const hasEmail = !!user.email;

const hasUnverifiedEmail =
    user.email !== null &&
    user.email_verified_at === null;

const isPhoneOnlyUser =
    user.email === null;
```

Then:

```ts
if (isPhoneOnlyUser) {
    // phone-oriented UX
}

if (hasUnverifiedEmail) {
    // email verification UX
}
```

---

## 36. GLOBAL NULL-SAFETY REQUIREMENT

Search the frontend for every direct email assumption:

```text
user.email.length
user.email.toLowerCase()
user.email.trim()
user.email!
user.email ?? ...
user_email.length
customer.email.length
```

Any code that assumes email is always a string must be reviewed.

Examples:

BAD:

```js
user.email.toLowerCase()
```

GOOD:

```js
user.email?.toLowerCase()
```

or:

```js
if (user.email) {
    user.email.toLowerCase();
}
```

---

## 37. FORMS

Any form validation schema such as:

```text
required().email()
```

must be changed only for customer email fields affected by this REST feature.

Correct:

```text
email → optional + valid email when supplied
```

Do NOT globally make every email in the application optional.

Admin/email-specific forms remain unchanged.

---

## 38. API ERROR HANDLING

The frontend must continue treating backend validation as authoritative.

If backend returns:

```text
422
```

display the appropriate validation message.

Do not convert:

```text
email = null
```

into a frontend validation error before submitting.

---

## 39. DO NOT SEND FAKE EMAILS

Absolutely DO NOT do this:

```js
email: `${phone}@example.com`
```

or:

```js
email: `${user.id}@no-email.com`
```

or:

```js
email: "customer@demo.com"
```

The backend intentionally preserves:

```text
email = null
```

---

## 40. DO NOT AUTO-FILL EMAIL FROM PHONE

Never transform:

```text
01012345678
```

into:

```text
01012345678@example.com
```

Phone and email are separate customer fields.

---

## 41. COMPLETE PHONE-ONLY CUSTOMER FLOW

The main flow should now be:

```text
                    REGISTER
                       │
                       ▼
              Phone + Password
                       │
                  Email optional
                       │
                       ▼
                Account Created
                       │
                       ▼
                 email = null
                       │
                       ▼
                  Login by Phone
                       │
                       ▼
                     Token
                       │
                       ▼
                    /me
                       │
                       ▼
                    Cart
                       │
                       ▼
                  Checkout
                       │
            ┌──────────┴──────────┐
            │                     │
          Coupon              No Coupon
            │                     │
            └──────────┬──────────┘
                       ▼
                    Order
                       │
             ┌─────────┴─────────┐
             │                   │
            COD               Online
             │                   │
             │             Backend handles
             │             gateway email
             │                   │
             └─────────┬─────────┘
                       ▼
                    Invoice
                       │
                       ▼
                  Customer sees
                  normal order
                  lifecycle
```

At no point should the frontend say:

```text
EMAIL REQUIRED
```

unless the user is entering a genuinely email-specific feature.

---

## 42. COMPLETE EMAIL USER FLOW

Existing customers with email continue to work:

```text
Register
   │
   ▼
Email + Phone
   │
   ▼
Account
   │
   ▼
Email verification where applicable
   │
   ▼
Login
   │
   ▼
Cart
   │
   ▼
Checkout
   │
   ▼
Payment
   │
   ▼
Invoice
```

The optional-email change must NOT break this path.

---

## 43. UX RULES

### Phone-only customer

Show:

```text
Phone: 010xxxxxxxx
Email: Not provided
```

or simply omit the email field.

Do not show:

```text
Email verification required
```

Do not show:

```text
Account incomplete
```

Do not show:

```text
Add email before checkout
```

unless that is a future explicit product requirement.

---

## 44. PROFILE / ACCOUNT

If profile screen contains email:

```text
Email (Optional)
```

If the customer has no email:

```text
Email
Not provided
```

If the application later supports adding email, that can be a separate feature.

Do NOT implement an email-update workflow as part of this integration unless a separate API already exists.

---

## 45. FRONTEND API CONTRACT SUMMARY

| Field                  | Old assumption             | New behavior                  |
| ---------------------- | -------------------------- | ----------------------------- |
| `users.email`          | Required                   | `string \| null`              |
| `email_verified_at`    | Required for customer flow | `string \| null`              |
| `phone_number`         | Required                   | Required                      |
| `orders.user_email`    | Required assumption        | `string \| null`              |
| Invoice customer email | Required assumption        | Optional/null                 |
| Social provider email  | Required assumption        | Optional                      |
| Gateway email          | Customer email             | Backend may derive fallback   |
| Cart identity          | May appear email-related   | `user_id`                     |
| Coupon identity        | May appear email-related   | `user_id`                     |
| Social identity        | Email                      | `provider + provider_user_id` |

---

## 46. WHAT FRONTEND MUST CHANGE

## Required

1. Change customer email model to nullable.
2. Remove required validation from customer registration email.
3. Mark registration email optional.
4. Remove frontend email-verification redirect for `email === null`.
5. Support phone-only OTP flow.
6. Make checkout email optional.
7. Make order `user_email` nullable in models.
8. Make invoice/customer email nullable.
9. Handle `null` safely in all UI components.
10. Remove any logic that rejects social login because provider email is missing.
11. Do not generate fake emails.
12. Do not expect gateway fallback email from the API as customer data.

---

## 47. WHAT FRONTEND MUST NOT CHANGE

Do NOT change:

```text
GraphQL
Admin authentication
Admin email verification
Email-specific password reset architecture
Backend payment fallback logic
Backend social identity architecture
Database identity
```

Do not create frontend workarounds for backend behavior that is already handled server-side.

---

## 48. FRONTEND TEST MATRIX

Before considering the frontend integration complete, test:

### Registration

```text
[ ] Register without email
[ ] Register with email
[ ] Invalid email
[ ] Duplicate email
[ ] Missing phone
```

### Authentication

```text
[ ] Phone + password login
[ ] Existing email user login
[ ] /me with email
[ ] /me with email=null
```

### OTP

```text
[ ] Phone OTP
[ ] Email OTP for email-bearing user
[ ] Correct OTP UI channel
```

### Cart

```text
[ ] Phone-only add to cart
[ ] Read cart
```

### Checkout

```text
[ ] Phone-only checkout
[ ] Fast checkout
[ ] COD
[ ] Online payment
[ ] Coupon
[ ] Assigned coupon
```

### Orders

```text
[ ] Order with user_email=null
[ ] Order with user_email populated
```

### Invoice

```text
[ ] Invoice with email
[ ] Invoice without email
[ ] No "null" / "undefined" visible
```

### Social

```text
[ ] Social login with provider email
[ ] Social login without provider email
[ ] Repeat social login
```

---

## 49. ACCEPTANCE CRITERIA

Frontend integration is complete only when:

```text
A phone-only customer can:

REGISTER
  ↓
LOGIN
  ↓
GET /me
  ↓
SEND/VERIFY PHONE OTP where applicable
  ↓
ADD TO CART
  ↓
CHECKOUT
  ↓
USE COUPON
  ↓
CREATE ORDER
  ↓
PAY
  ↓
VIEW INVOICE
```

without being forced to provide an email.

At the same time:

```text
Existing email customers
```

must continue to experience the existing email-enabled flow.

---

## 50. MOST IMPORTANT RULES FOR THE FRONTEND TEAM

Remember these 10 rules:

1. `email` is now nullable.
2. `phone_number` remains required for normal customer registration.
3. `email=null` is a valid customer state.
4. `email=null` does NOT mean email is unverified.
5. Phone-only users use phone-based authentication/OTP paths.
6. Cart/order/coupon identity is based on `user_id`, not email.
7. Social identity is `provider + provider_user_id`, not email.
8. MyFatoorah fallback email is backend-only.
9. Never generate or persist fake customer emails in frontend.
10. Existing users with real emails must continue working normally.

---

## 51. IMPLEMENTATION DIRECTIVE

Before changing frontend code:

1. Inspect the existing API client.
2. Inspect authentication state management.
3. Inspect User/Customer models.
4. Inspect registration form.
5. Inspect OTP flow.
6. Inspect email verification guards.
7. Inspect checkout form.
8. Inspect order model.
9. Inspect invoice/customer components.
10. Inspect social login.
11. Search the entire frontend for hard email assumptions.

Then implement the minimum required changes.

Do NOT redesign the frontend architecture.

Do NOT create duplicate authentication flows.

Do NOT create fake email addresses.

Do NOT add business rules that do not exist in the backend.

The backend REST API is the source of truth.

---

## 52. FINAL FRONTEND DELIVERABLE

After implementation, provide:

```text
1. Files changed
2. Why each file changed
3. New nullable fields/models
4. Registration flow
5. Login flow
6. OTP flow
7. Checkout flow
8. Payment flow
9. Social login flow
10. Null-safety changes
11. Tests executed
12. Remaining frontend limitations
```

The final implementation must make the frontend correctly support:

```text
Email Customer
+
Phone-only Customer
```

as two valid customer states.
