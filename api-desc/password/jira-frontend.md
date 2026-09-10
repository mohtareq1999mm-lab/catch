# Password — Frontend Jira Stories

## Epic: PWD-FE-EPIC-1 — Password Reset UI

### Story PWD-FE-1: Forget Password Page (Step 1)
**As a** user who forgot their password
**I want** an email input form
**So that** I can request a reset OTP

**Acceptance Criteria:**
- [ ] Single input: email (validated client-side for format)
- [ ] Submit button with loading spinner
- [ ] **Always shows success message** "If this email is registered, check your inbox" — never shows "No account found" (prevents email enumeration)
- [ ] Email is **queued** — show brief delay before enabling resend; do not promise instant delivery
- [ ] On 429: show "Too many attempts. Please wait 1 minute."
- [ ] On 500: show "Something went wrong. Please try again."
- [ ] "Back to login" link
- [ ] Rate limit: disable submit button after 5 attempts, show countdown timer

### Story PWD-FE-2: OTP Verification Page (Step 2)
**As a** user who received an OTP
**I want** to enter the 6-digit code
**So that** I can prove I own the email

**Acceptance Criteria:**
- [ ] 6-character OTP input (single field or 6 individual digit boxes)
- [ ] Auto-advance on mobile digit entry
- [ ] Auto-submit when 6 characters entered
- [ ] Display email being verified (grayed out, read-only)
- [ ] ✓ Handle JSON `{success, message}` response (was raw boolean — now standard JSON)
- [ ] On `success: true`: advance to Step 3
- [ ] On `success: false`: show "Invalid or expired OTP. Please try again."
- [ ] Show countdown timer for OTP expiry (60 minutes, configurable)
- [ ] "Resend OTP" link → returns to Step 1
- [ ] On 429: rate limit notice

### Story PWD-FE-3: New Password Page (Step 3)
**As a** user with a verified OTP
**I want** to set a new password
**So that** I can regain access to my account

**Acceptance Criteria:**
- [ ] Two fields: password, confirm password
- [ ] Password visibility toggle (eye icon)
- [ ] Minimum 8 characters validation
- [ ] Password confirmation must match
- [ ] Password strength indicator (optional enhancement)
- [ ] On 200: show "Password reset successful!" → redirect to login page
- [ ] On 400 (INVALID_TOKEN): show "OTP expired or invalid. Start again." → redirect to Step 1
- [ ] On 422: show field-level errors (validation moved outside try/catch — now works correctly)
- [ ] On 429: rate limit notice

### Story PWD-FE-4: Auth State After Reset
**As a** user who reset their password
**I want** all old sessions to be invalidated
**So that** I know only I can access my account with the new password

**Acceptance Criteria:**
- [ ] After reset, if user was logged in, redirect to login
- [ ] Old tokens no longer work
- [ ] User must log in with new password

### Story PWD-FE-5: Password Reset Error Handling Component
**As a** frontend developer
**I want** a reusable error display component
**So that** all 3 steps show consistent error messages

**Acceptance Criteria:**
- [ ] Component accepts: message, type (error/success/info), optional action button
- [ ] Shows server errors inline
- [ ] Shows rate limit errors with auto-dismissing timer
- [ ] Shows validation errors near the relevant input field

## Jest Test Cases

### PasswordResetPage (3-step wizard)
1. Step 1 renders email input
2. Step 1 submit with valid email → calls forgetPassword API
3. Step 1 submit with invalid email → client-side validation error
4. Step 1 404 response → "No account found" message
5. Step 2 renders after successful Step 1
6. Step 2 auto-submits on 6 characters
7. Step 2 handles `{success: true}` → advances
8. Step 2 handles `{success: false}` → shows error
9. Step 3 renders after successful Step 2
10. Step 3 validates password match
11. Step 3 validates min length
12. Step 3 submits to resetPassword API
13. Step 3 success → redirects to login
14. Step 3 400 error → redirects to Step 1
15. Rate limit (429) shows message on all steps
16. OTP expiry countdown (60 min) reaches 0 → shows expired message
17. "Resend OTP" → returns to Step 1 with email pre-filled
18. "Back to login" link present on all steps
