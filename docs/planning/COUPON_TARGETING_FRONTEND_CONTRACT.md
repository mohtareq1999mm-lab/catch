# COUPON TARGETING & ELIGIBILITY ENGINE
## FRONTEND CONTRACT

**Version:** 1.0.0  
**Date:** 2026-09-08  
**Audience:** Frontend Developers

---

## PURPOSE

This document defines what the Frontend MUST and MUST NOT do when integrating with the new Coupon Targeting & Eligibility system.

**Backend is the authority.** Frontend displays what the backend tells it.

---

## FRONTEND MUST DO

### 1. Consume Targeting-Aware Coupon APIs

**GET /api/v1/general/coupons** now returns additional fields:

```json
{
  "data": [
    {
      "id": 1,
      "code": "SUMMER50",
      "name": "Summer Sale",
      "targeting": {
        "mode": "dynamic_rules",
        "max_claims": 100,
        "claims_remaining": 23,
        "user_eligible": true,
        "user_claimed": false,
        "eligibility_reason": null
      }
    }
  ]
}
```

**Frontend MUST:**
- Display only coupons returned by the API
- Show claim button if `user_eligible=true AND user_claimed=false AND max_claims > 0`
- Show "Claimed" badge if `user_claimed=true`
- Show "Limit Reached" if `claims_remaining=0`

### 2. Handle Claim Flow

**When user clicks "Claim Coupon":**

```javascript
POST /api/v1/general/coupons/{id}/claim
Headers: { Authorization: Bearer {token} }

// Success response:
{
  "success": true,
  "message": "Coupon claimed successfully",
  "data": { claim_id, coupon_id, claimed_at, expires_at }
}

// Frontend MUST:
- Update UI to show "Claimed" state
- Enable "Apply" button
- Do NOT assume claim succeeded until 200 response received
```

**Error handling:**

```javascript
// Limit reached:
{ "success": false, "message": "Coupon claim limit has been reached" }
→ Show error message, disable claim button

// Not eligible:
{ "success": false, "message": "You don't meet the requirements for this coupon" }
→ Show eligibility requirements (from targeting.eligibility_reason if provided)

// Already claimed:
{ "success": true, "message": "Coupon already claimed" }
→ Update UI to claimed state (idempotent success)
```

### 3. Apply Coupon (Existing Flow Extended)

**POST /api/v1/general/coupons/apply** may now return:

```json
{
  "success": false,
  "message": "You must claim this coupon before applying it",
  "errors": { "coupon": ["Coupon must be claimed first"] }
}
```

**Frontend MUST:**
- Check `user_claimed=true` before showing "Apply" button for limited coupons
- Show "Claim First" button if not claimed
- Handle "must claim first" error gracefully

### 4. Refresh State After Login/Logout

**After user logs in:**
- Re-fetch `/api/v1/general/coupons` (eligibility may change based on user history)

**After user logs out:**
- Clear coupon eligibility state
- Re-fetch public coupons (guest view)

### 5. Display Eligibility Information

**If API provides eligibility explanation:**

```json
{
  "targeting": {
    "user_eligible": false,
    "eligibility_reason": "Minimum spend of 10,000 SAR required"
  }
}
```

**Frontend MUST:**
- Display the reason to help user understand why they don't qualify
- Do NOT make up reasons (only display what API provides)

### 6. Handle Loading States

**While claiming:**
- Disable claim button
- Show loading spinner
- Do NOT allow multiple simultaneous claim requests

**While applying:**
- Existing loading behavior (no changes)

---

## FRONTEND MUST NOT DO

### 1. ❌ Do NOT Calculate Eligibility Locally

**WRONG:**
```javascript
// ❌ BAD
if (user.totalSpend >= 10000) {
  showCoupon(coupon);
}
```

**RIGHT:**
```javascript
// ✅ GOOD
const coupons = await fetch('/api/v1/general/coupons');
coupons.forEach(displayCoupon);
```

**Reason:** Eligibility rules are complex (AND/OR/NOT, refunds, payment methods, geography). Backend is the authority.

### 2. ❌ Do NOT Calculate Spend Locally

**WRONG:**
```javascript
// ❌ BAD
const userSpend = orders.reduce((sum, o) => sum + o.total, 0);
if (userSpend >= 10000) { /* ... */ }
```

**RIGHT:**
```javascript
// ✅ GOOD
// Server already filtered coupons based on spend
const coupons = await fetch('/api/v1/general/coupons');
```

**Reason:** Spend calculations involve refunds, completed orders only, payment status. Backend has the source of truth.

### 3. ❌ Do NOT Count Claims Locally

**WRONG:**
```javascript
// ❌ BAD
let claimsRemaining = 100 - claimedUsers.length;
if (claimsRemaining > 0) { showClaimButton(); }
```

**RIGHT:**
```javascript
// ✅ GOOD
if (coupon.targeting.claims_remaining > 0 && !coupon.targeting.user_claimed) {
  showClaimButton();
}
```

**Reason:** Claim counting is concurrency-sensitive. Backend handles atomic allocation.

### 4. ❌ Do NOT Trust Hidden Fields

**WRONG:**
```javascript
// ❌ BAD
<input type="hidden" name="eligible" value="true">
// User can modify this in DevTools
```

**RIGHT:**
```javascript
// ✅ GOOD
// Server validates eligibility on POST /coupons/apply
// Frontend just displays what server returned
```

### 5. ❌ Do NOT Store Authoritative Eligibility in localStorage

**WRONG:**
```javascript
// ❌ BAD
localStorage.setItem('eligible_coupons', JSON.stringify(coupons));
// Eligibility can change (new order, refund, etc.)
```

**RIGHT:**
```javascript
// ✅ GOOD
// Cache for UX (avoid refetch on navigation), but re-validate on critical actions
const cached = sessionStorage.getItem('coupons');
if (cached && !isCriticalAction) {
  display(JSON.parse(cached));
} else {
  refetch();
}
```

### 6. ❌ Do NOT Treat Reservation as Claim

**WRONG:**
```javascript
// ❌ BAD
// Reservation happens during checkout (30min TTL)
// Claim is persistent (lasts until coupon expires)
// These are NOT the same
```

**RIGHT:**
```javascript
// ✅ GOOD
// Claim: User explicitly clicked "Claim Coupon" → POST /claim
// Reservation: Automatic during checkout → backend handles
// Frontend doesn't conflate these concepts
```

### 7. ❌ Do NOT Bypass Existing Coupon Validation

**WRONG:**
```javascript
// ❌ BAD
if (userHasClaim) {
  applyCouponDirectly(); // Skip other validation
}
```

**RIGHT:**
```javascript
// ✅ GOOD
// Always call POST /coupons/apply
// Backend checks: eligibility → claim → assignment → static validation → reservation
```

### 8. ❌ Do NOT Send Arbitrary Rule Definitions

**WRONG:**
```javascript
// ❌ BAD
POST /coupons/123/targeting
{
  "rules": { "type": "malicious_rule", "sql": "DROP TABLE users" }
}
```

**RIGHT:**
```javascript
// ✅ GOOD
// Only admin API can update targeting
// Public API only consumes targeting results
// Normal users cannot modify rules
```

---

## API CONTRACT SUMMARY

| Endpoint | Method | Auth | Purpose | Frontend Action |
|----------|--------|------|---------|----------------|
| `/general/coupons` | GET | Optional | List eligible coupons | Display coupons, show eligibility state |
| `/general/coupons/{id}/claim` | POST | Required | Claim limited coupon | Handle claim button click |
| `/general/coupons/apply` | POST | Required | Apply to cart | Existing flow, handle new "must claim" error |

---

## ERROR HANDLING

**Frontend MUST handle these new error cases:**

```javascript
// Not eligible
{ success: false, message: "You don't meet the requirements" }
→ Display message, hide apply button

// Claim limit reached
{ success: false, message: "Coupon claim limit has been reached" }
→ Display "No longer available", disable claim button

// Must claim first
{ success: false, message: "You must claim this coupon before applying it" }
→ Show "Claim Coupon" button

// Already claimed (idempotent success)
{ success: true, message: "Coupon already claimed" }
→ Update UI to claimed state, enable apply button
```

---

## STATE MACHINE

```
Coupon States (from Frontend perspective):

NOT_ELIGIBLE
  → User doesn't meet targeting rules
  → UI: Show eligibility reason, disable all buttons

ELIGIBLE_NOT_CLAIMED
  → User meets rules, has not claimed yet, slots available
  → UI: Show "Claim Coupon" button

CLAIMED
  → User has claimed the coupon
  → UI: Show "Claimed" badge, show "Apply" button

APPLIED
  → Coupon applied to cart (existing state)
  → UI: Show in cart summary, allow removal

RESERVED
  → Coupon reserved during checkout (existing state)
  → UI: Show in checkout summary

REDEEMED
  → Coupon consumed by completed order (existing state)
  → UI: Show in order history
```

**Transitions:**
```
NOT_ELIGIBLE → (cannot transition, user must change behavior)
ELIGIBLE_NOT_CLAIMED → CLAIMED (via POST /claim)
CLAIMED → APPLIED (via POST /apply)
APPLIED → RESERVED (via POST /checkout, automatic)
RESERVED → REDEEMED (via payment success, automatic)
```

---

## TESTING CHECKLIST

Frontend developers MUST test:

- [ ] Display coupons with `user_eligible=true`
- [ ] Hide coupons with `user_eligible=false` (or show with disabled state)
- [ ] Show "Claim" button for `max_claims > 0 AND user_claimed=false`
- [ ] Handle successful claim (button → "Claimed" badge)
- [ ] Handle "limit reached" error (disable button, show message)
- [ ] Handle "not eligible" error (explain why)
- [ ] Handle "already claimed" idempotent success
- [ ] Disable apply button until claimed (for limited coupons)
- [ ] Re-fetch coupons after login/logout
- [ ] Show loading states during claim API call
- [ ] Prevent double-click on claim button

---

## QUESTIONS FOR BACKEND

If any of these are unclear:

1. What should UI show for `eligibility_reason=null` when `user_eligible=false`?
2. Should we cache eligibility state, and for how long?
3. Should we poll `/general/coupons` to detect when claims_remaining changes?
4. What analytics events should we fire (claim attempt, claim success, claim failure)?

**Contact:** Backend team via [communication channel]

---

## VERSION HISTORY

| Version | Date | Changes |
|---------|------|---------|
| 1.0.0 | 2026-09-08 | Initial frontend contract for targeting system |

