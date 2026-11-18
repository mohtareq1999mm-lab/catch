# Meem Commerce — Commerce Domain

Guest and customer commerce over a shared catalog: carts, eligibility, orders, payments, and fulfillment across web and mobile channels.

## Language

### Guests & credentials

**Guest**:
A customer without a user account who buys through the guest flow, known only by a Guest Identity and the contact details captured at checkout.
_Avoid_: guest account, logged-out customer, unregistered user

**Guest Identity**:
A server-managed anonymous identity for one browser or device. It owns guest carts and guest orders and exists until revoked or expired.
_Avoid_: guest account, anonymous user, device id

**Guest Credential**:
The opaque secret that proves ownership of a Guest Identity. It is issued once, stored only as a keyed hash, and transported per client type.
_Avoid_: token, API key, session id

**Browser Credential**:
The Guest Credential transport for web: an HttpOnly cookie, paired with a readable CSRF token for state-changing calls.
_Avoid_: session cookie, auth cookie

**Mobile Credential**:
The Guest Credential transport for native apps: an opaque Bearer token sent in the Authorization header; no cookies and no browser CSRF apply.
_Avoid_: mobile token, device token

**Guest Session**:
The live state of holding an unexpired, unrevoked Guest Credential. Created only by an explicit session-initiation call, which always creates a new Guest Identity and is never a resume of an existing one.
_Avoid_: login, sign-in, resume

**Credential Conflict**:
A request that presents two Guest Credentials resolving to different Guest Identities. Always rejected; never resolved by silent selection.
_Avoid_: mixed session, double login

**Rotation**:
Issuing a new Guest Credential for the same Guest Identity and invalidating the previous one. Explicit only, never silent.
_Avoid_: refresh, re-issue

**Revocation**:
Immediately ending a Guest Identity: every transport of its Guest Credential stops working, including an in-flight browser cookie.
_Avoid_: logout, invalidate

**Retention**:
The fixed inactivity window after which an unused Guest Identity and its Guest Cart expire. Renewed only by genuine cart mutations, never by reads.
_Avoid_: TTL, session length

**Guest Surface**:
A family of guest-facing endpoints (session, cart, checkout, tracking, cancellation, claiming) protected as one unit: its own usage limit, its own credential expectations, and a fail-closed rule when a limit cannot be evaluated.
_Avoid_: public endpoint, guest API, open route

### Cart & eligibility

**Guest Cart**:
Exactly one active cart owned by a Guest Identity. Ownership comes only from the verified Guest Credential, never from client-supplied cart or item ids.
_Avoid_: anonymous cart, temp cart

**Eligibility**:
The rules deciding whether a product or variant may enter a cart: activity, product type, channel, shipping method, variant ownership, and stock. Guest eligibility may be stricter than customer eligibility by explicit policy.
_Avoid_: validation, availability check

**Cart Line Availability**:
A presentational signal on an existing cart line saying whether it is still buyable. Display-only; checkout remains the authority.
_Avoid_: reserved, locked

**Reservation**:
Holding stock for an order. Happens at order creation only — never when adding to a cart.
_Avoid_: stock lock, cart hold

**Login Merge**:
Moving Guest Cart lines into the customer's cart after successful authentication. Unavailable lines are preserved and reported, not silently dropped.
_Avoid_: cart transfer, migration

### Orders & tracking

**Guest Order**:
An order created by the guest flow and owned by a Guest Identity. It carries the guest's contact details and is reachable only through the Guest Credential or its tokens.
_Avoid_: anonymous order, guest account order

**Order Transition**:
A single authorized change of an Order's lifecycle status, carrying its flow gate, payment authority, inventory reservation/restore, coupon and promotion consequences, status history, and lifecycle events. Named by intent at the call site; executed by one executor.
_Avoid_: status update, status change, status set

**Tracking Token**:
The capability secret behind the private tracking link: whoever holds it can view one specific guest order. Delivered to the guest's contact points after checkout, valid through the order lifecycle plus a fixed grace window, and never a proof for cancellation or account access.
_Avoid_: tracking id, order link, public token

### Cancellation & OTP

**Cancellation Token**:
The single-use capability credential that lets its holder cancel one specific guest order. Stored only as a hash and returned in full exactly once.
_Avoid_: cancel link, revoke token

**Cancellation OTP**:
A one-time possession proof sent to the phone number recorded on the order, required together with the Cancellation Token to execute a guest cancellation. The phone is unverified at capture; the Cancellation OTP is what verifies possession.
_Avoid_: verification code, SMS code, PIN

**Local OTP**:
The static-code path used when no SMS provider is available, for development and testing. Never available in production by default.
_Avoid_: mock OTP, test OTP, fake gateway

**Master OTP**:
A configured static code accepted as a universal OTP value when the Local OTP path is enabled; it overrides the default local code. It is a value, never a separate enable switch.
_Avoid_: admin OTP, super code, backdoor code
