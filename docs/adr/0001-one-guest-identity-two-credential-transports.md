# One Guest Identity, two credential transports (cookie + Bearer)

Guest credentials resolve to a single canonical `guest_identities` row through `GuestIdentityService::current()`, with two transports: browsers present an HttpOnly cookie (`token_hash` column, double-submit CSRF enforced), native mobile apps present an opaque `Authorization: Bearer` token (`mobile_token_hash` column, no CSRF, no Origin requirement). Both hashes are keyed HMAC-SHA256 with the raw token never persisted, and the mobile hash is domain-separated (`guest-mobile|` prefix) so a credential leaked between transports can never authenticate as the other. A request presenting credentials for two different identities is rejected (401 `credential_conflict`) rather than silently picking one.

## Considered Options

- **Sanctum tokens for guests** — rejected: couples guest commerce to the customer auth stack, changes Sanctum's meaning for customers, and drags in guard/provider machinery for a credential that only needs hashing and expiry.
- **Separate mobile identity table/cart logic** — rejected: duplicates every business rule; the whole point is one identity, one cart, one eligibility policy.
- **Single hash column serving both transports** — rejected: a mobile token stored in the cookie hash would authenticate through the browser path without CSRF, weakening the double-submit guarantee.

## Consequences

- One additive nullable column per transport; browser-only identities carry an unused random `token_hash` slot so the NOT NULL + UNIQUE contract holds.
- Bearer mode is the CSRF exemption boundary (`GuestCsrf` checks the resolved auth mode); cookie mode keeps the 419 contract unchanged.
- Extending this to a third transport means one new hash column and one resolver branch — no business-logic duplication.
