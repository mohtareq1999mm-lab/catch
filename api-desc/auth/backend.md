# Auth — Backend Implementation

## Controller
- **File**: `packages/marvel/src/Http/Controllers/UserController.php`
- **Extends**: `CoreController`
- **Traits**: `WalletsTrait`, `UsersTrait`, `ApiResponse`

## Dependencies

### Form Requests
| Endpoint | Request Class | File |
|----------|--------------|------|
| POST /api/v1/register | `UserCreateRequest` | `packages/marvel/src/Http/Requests/UserCreateRequest.php` |
| POST /api/v1/token | `UserAuthEmailAndPasswordRequest` | `packages/marvel/src/Http/Requests/UserAuthEmailAndPasswordRequest.php` |
| POST /api/v1/admin-login | `UserAuthEmailAndPasswordRequest` | (same) |

### Repository
| Repository | File |
|-----------|------|
| `UserRepository` | `packages/marvel/src/Database/Repositories/UserRepository.php` |

### Services / Traits
| Trait | File | Used In |
|-------|------|---------|
| `WalletsTrait` | `packages/marvel/src/Traits/WalletsTrait.php` | register (gives signup points via `giveSignupPointsToCustomer`) — currently commented out |
| `UsersTrait` | `packages/marvel/src/Traits/UsersTrait.php` | |
| `ApiResponse` | `packages/marvel/src/Traits/ApiResponse.php` | All endpoints |

### Events
| Event | Dispatched In |
|-------|---------------|
| `App\Events\AdminLoggedIn` | token(), adminToken() — fires after successful login |

### Models
| Model | File |
|-------|------|
| `Marvel\Database\Models\User` | `packages/marvel/src/Database/Models/User.php` |

### Mail
| Mailable | Used In |
|----------|---------|
| `Marvel\Mail\ContactAdmin` | (not used in auth) |
| `User::sendOneTimePassword()` | register(), sendUserOtp() — sends OTP via email |
| `UserRepository::sendResetEmail()` | forgetPassword() — sends password reset OTP |

### OTP Gateways
| Gateway | Class |
|---------|-------|
| Twilio | `Marvel\Otp\Gateways\TwilioGateway` |
| Local (fallback) | `Marvel\Otp\Gateways\LocalGateway` |
| Active gateway configured via | `config('auth.active_otp_gateway')` |

## Key Implementation Details

### Registration Flow
1. `UserCreateRequest` validates input — `email: ['nullable','sometimes','email','unique:users,email','email:rfc,dns']` (optional for REST; omit or `null`; validated only when present), `phone_number: ['required',...,'unique:users,phone_number']` (`packages/marvel/src/Http/Requests/UserCreateRequest.php:30-38`). `POST /api/v1/register` accepts `email` omitted or `email: null`; `email = null` is a valid customer state, not an error. Admin/GraphQL registration remains email-required.
2. `UserRepository::create()` creates the user with type='user', is_active=true (persists `email = NULL` for phone-only customers)
3. `assignRole('customer')` — wrapped in try/catch for seed race condition
4. Avatar upload via Spatie MediaLibrary (optional)
5. `if ($user->email) { $user->sendOneTimePassword() }` — sends OTP email only when email present (`packages/marvel/src/Http/Controllers/UserController.php:668-686` guards the call); phone-only registration skips the email OTP and returns `{"status":200,"message":"User registered successfully","success":true,"data":{"otp_status":true}}` (no email-side effect)
6. If OTP fails (email path only), returns `{"status":201,"message":"Account created but OTP failed","success":true,"data":{"requires_resend":true,"email":"...","phone_number":"...","otp_status":false}}`

### Login Flow
- `token()` supports login by `email + password` OR `phone_number + password` (`required_without` via `UserAuthEmailAndPasswordRequest` — `email: required_without:phone_number|email`, `phone_number: required_without:email|string|max:15|min:8`; `packages/marvel/src/Http/Requests/UserAuthEmailAndPasswordRequest.php`) — `User::where('email',...)->orWhere('phone_number',...)` (phone-only customers log in by `phone_number + password`)
- `adminToken()` requires `type === 'admin'` AND `hasVerifiedEmail()` — admin login remains email-required with verified email
- Both dispatch `AdminLoggedIn` event

### Password Reset Flow
1. `forgetPassword()` — generates 6-char random string, stores hashed in `password_resets`, sends email
2. `verifyForgetPasswordToken()` — checks hash and 5-minute expiry, returns raw boolean
3. `resetPassword()` — verifies OTP, updates password, deletes all tokens, cleans up `password_resets`

### Social Login
- Uses Laravel Socialite (Google, Facebook)
- `firstOrCreate` by email
- Creates `user_providers` record via `updateOrCreate`
- Auto-verifies email

### OTP Authentication (Disabled)
- `sendUserOtp()` — sends OTP via SMS gateway or email
- `otpLogin()` — verifies OTP and returns token
- `verifyOtp()` — delegates to configured OTP gateway
- `getOtpGateway()` — resolves gateway class from config, falls back to LocalGateway

## Tests
- **Feature Test File**: None found specifically for auth endpoints
- Login tests may exist in general test suites under `tests/Feature/`
