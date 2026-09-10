# OTP — Frontend Jira Stories

## Epic: OTP-FE-EPIC-1 — Phone OTP Login UI

### Story OTP-FE-1: Phone Login Page
**As a** user
**I want** to log in using my phone number and an OTP code
**So that** I don't need to remember a password

**Acceptance Criteria:**
- [ ] Phone number input with country code selector (e.g., +20 for Egypt)
- [ ] Validate phone number format client-side (11-15 digits)
- [ ] "Send OTP" button with loading state
- [ ] Tab/option to switch between phone and email OTP
- [ ] On success (Step 1) → save `data.otp_id` from response, show OTP input, auto-focus
- [ ] Disable resend button for 5-10 seconds (email is queued, not instant — avoid confusion)
- [ ] On 429: "Too many attempts. Please wait 1 minute."
- [ ] On 404: "No account found with this phone number"

### Story OTP-FE-2: OTP Verification Input
**As a** user who received an OTP
**I want** to enter the verification code
**So that** I can log in

**Acceptance Criteria:**
- [ ] 6-digit OTP input (single field or 6 individual boxes)
- [ ] Auto-submit when 6 characters entered
- [ ] Auto-advance between digit boxes on mobile
- [ ] Show remaining retry count (3 attempts before rate limit)
- [ ] "Resend OTP" link with cooldown timer (5-10s for queued email)
- [ ] On success: store `data.token`, redirect to home
- [ ] On error: show "Invalid verification code. X attempts remaining."
- [ ] After 3 failed attempts: show "Too many attempts. Please wait 1 minute."

### Story OTP-FE-3: Email OTP Login
**As a** user who prefers email
**I want** to receive an OTP via email and log in
**So that** I don't need a phone number

**Acceptance Criteria:**
- [ ] Tab-based toggle between "Phone" and "Email"
- [ ] Email input with client-side format validation
- [ ] Same OTP input UX as phone flow
- [ ] Email OTP is **queued** — brief delay before email arrives; do not show instant delivery message
- [ ] API response for email OTP includes `data.otp_id` (integer) — use for tracking verification session

### Story OTP-FE-4: Rate Limit Awareness
**As a** frontend developer
**I want** the UI to handle the strict 3/min rate limit
**So that** users don't get frustrated by unexpected blocks

**Acceptance Criteria:**
- [ ] Show remaining attempts counter (3 → 2 → 1 → blocked)
- [ ] On 429: show full-page message "Too many attempts. Please wait X seconds."
- [ ] Countdown timer showing seconds until next allowed request
- [ ] Disable all submit buttons during cooldown
- [ ] Auto-retry when cooldown expires (if user is on the page)

### Story OTP-FE-5: Token Handling
**As a** frontend developer
**I want** to store the Sanctum token after OTP login
**So that** the user is authenticated

**Acceptance Criteria:**
- [ ] Extract token from `data.token` (wrapped in array, consistent with `/token`)
- [ ] Save token to localStorage
- [ ] Call GET /api/v1/me to load user profile
- [ ] Redirect based on user role (admin → admin panel, customer → home)
- [ ] Same auth state management as email/password login

## Jest Test Cases

### OtpLoginPage
1. Renders phone input initially
2. Toggle to email input
3. Phone validation (too short → error)
4. Email validation (invalid format → error)
5. Submit phone → calls sendOtpCode API
6. Submit email → calls sendOtpCode API
7. 404 response → "No account found" message
8. 429 response → rate limit message with timer
9. After successful send → shows OTP input

### OtpVerificationInput
10. Auto-submits on 6 digits
11. Handles email OTP login response (`data.token` wrapped in object)
12. Handles phone OTP login response (`data.token` wrapped in object)
13. Shows error on invalid OTP (400)
14. Shows error on gateway error (422)
15. Resend OTP link works (cooldown 5-10s for queued email)
16. Resend cooldown timer counts down
17. All retries exhausted → shows block message

### Auth integration
18. Successful OTP login → token stored in localStorage
19. Successful OTP login → /me called, user state populated
20. OTP login token works for authenticated requests
