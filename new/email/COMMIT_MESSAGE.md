feat(auth): make email optional for REST API customers

Implement REST-only optional email feature allowing phone-only customer registration
and commerce flows while preserving all existing email-based workflows.

## Breaking Changes
- `users.email` column is now NULLABLE (requires migration)
- `orders.user_email` may now be NULL in checkout flows
- Invoice customer snapshot no longer requires 'email' field

## Database Changes
- New migration: make users.email nullable while preserving UNIQUE constraint
- MySQL allows multiple NULL values in UNIQUE indexes (correct behavior)
- Test schema updated to match production schema

## Registration & Authentication
- UserCreateRequest: email validation changed to nullable/sometimes
- UserController::register: guards sendOneTimePassword() with email null check
- Phone-only users skip OTP email sending, registration succeeds immediately
- Email verification middleware updated to bypass phone-only users
- Existing email users completely unaffected

## Commerce & Checkout
- OrderCreateRequest: user_email validation changed to nullable/sometimes
- FastCheckoutRequest: user_email validation changed to nullable/sometimes
- Order creation services already handle null email correctly
- Cart, coupon, and order lifecycle unaffected

## Invoice & Financial
- StructureValidator: removed 'email' from REQUIRED_CUSTOMER_KEYS
- Invoice snapshot continues to include email field (may be null)
- Identity remains user_id + name + phone

## Payment Gateway Integration
- New CustomerContactResolver service provides gateway-safe email fallback
- MyFatoorahGateway uses resolver for CustomerEmail field
- Fallback pattern: order-{id}@no-email.meem.local (deterministic, never persisted)
- Real email used when available, fallback only at gateway boundary

## Social Login
- SocialController updated to use provider identity (provider + provider_user_id)
- No longer relies on email-keyed firstOrCreate
- Handles null email from OAuth providers correctly
- Prevents duplicate NULL-email rows

## Testing
- UserFactory: added withoutEmail() state
- New comprehensive test suite: CustomerWithoutEmailTest
- Coverage: registration, login, checkout, invoice, payments, social login
- Database constraint tests: multiple nulls allowed, unique enforcement

## Files Changed (14 modified, 4 created)

### Modified
- app/Http/Middleware/VirifiyEmailMiddleware.php
- app/Services/Gateway/MyFatoorahGateway.php
- app/Services/Invoice/Validators/StructureValidator.php
- database/factories/UserFactory.php
- packages/marvel/src/Http/Controllers/SocialController.php
- packages/marvel/src/Http/Controllers/UserController.php
- packages/marvel/src/Http/Requests/FastCheckoutRequest.php
- packages/marvel/src/Http/Requests/OrderCreateRequest.php
- packages/marvel/src/Http/Requests/UserCreateRequest.php
- tests/Concerns/CreatesTestTables.php

### Created
- database/migrations/2026_09_06_114430_make_users_email_nullable.php
- app/Services/Payment/CustomerContactResolver.php
- tests/Feature/Rest/CustomerWithoutEmailTest.php
- IMPLEMENTATION_COMPLETE_REST_OPTIONAL_EMAIL.md

## Intentionally NOT Changed
- GraphQL schema (out of REST scope)
- Admin login flow (admin email + verification required)
- Marvel legacy payment classes (not used by REST checkout)
- Password reset (email-keyed by design)
- Newsletter/contact forms (email-specific features)

## Identity Model
```
userId        = primary identity (users.id)
phone_number  = primary login/contact (users.phone_number) - REQUIRED
email         = optional contact channel (users.email) - NULLABLE
```

## Security & Data Integrity
✓ No fake emails persisted to database
✓ Unique constraint preserved for non-null emails
✓ Payment gateway fallback only at boundary, never stored
✓ Social login uses provider identity, not email collision
✓ Phone number remains required (at-least-one-identifier rule)
✓ Existing email users completely unaffected

## Deployment
1. Run migration: php artisan migrate
2. Run tests: php artisan test --filter=CustomerWithoutEmailTest
3. Monitor logs for Mail::to(null) errors
4. Monitor payment gateway submission errors

Refs: new/email_vreifction.md (audit), Implementation Plan 2026-09-06

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
