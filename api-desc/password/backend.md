# Password — Backend Implementation

## Controller Methods

| Endpoint | Method | File | Line |
|----------|--------|------|------|
| forget-password | `forgetPassword()` | `packages/marvel/src/Http/Controllers/UserController.php` | 762 |
| verify-forget-password-token | `verifyForgetPasswordToken()` | `packages/marvel/src/Http/Controllers/UserController.php` | 798 |
| reset-password | `resetPassword()` | `packages/marvel/src/Http/Controllers/UserController.php` | 824 |

## Controller
- **File**: `packages/marvel/src/Http/Controllers/UserController.php`
- **Extends**: `CoreController`
- **Traits**: `WalletsTrait`, `UsersTrait`, `ApiResponse`

## Dependencies

### Repository
| Repository | Method Used |
|-----------|-------------|
| `UserRepository` — `findByField()` | Lookup user by email |
| `UserRepository` — `sendResetEmail()` | Send password reset email |

### Models
| Model | Table |
|-------|-------|
| `Marvel\Database\Models\User` | `users` |
| `password_resets` | Direct DB facade (no model) |

### Mail
| Method | Description |
|--------|-------------|
| `UserRepository::sendResetEmail($email, $plainTextToken)` | Sends email with 6-char OTP |

### Query
| Query | Purpose |
|-------|---------|
| `DB::table('password_resets')` | Store, retrieve, and delete reset tokens |
| `$user->tokens()->delete()` | Revoke all Sanctum tokens on password reset |

## Key Implementation Details

### forgetPassword()
1. Uses `$this->repository->findByField('email', $request->email)` — returns collection
2. `count($user) < 1` check — **still queries DB but always returns 200** (no email enumeration)
3. Generates `Str::random(6)` — 6-character alphanumeric OTP
4. **`updateOrInsert()`** — atomic upsert (replaced manual insert/update, no race condition)
5. Stores `Hash::make($plainTextToken)` — never stores plaintext
6. Calls `$this->repository->sendResetEmail()` — uses `Mail::queue()` (queued on `high`)<｜end▁of▁thinking｜>

### verifyForgetPasswordToken()
1. **`$request->validate([email, otp])`** — explicit validation (was missing)
2. Fetches `password_resets` by email
3. `Hash::check($request->otp, $tokenData->token)` — verifies OTP
4. Checks expiry: `Carbon::parse(...)->addMinutes(config('auth.passwords.users.expire', 60))->isPast()`
5. Returns **JSON** `{success, message}` with HTTP 200/400 (not raw boolean)

### resetPassword()
1. **`$request->validate([password, password_confirmation, email, otp])` — outside try/catch** (so 422 errors work, not swallowed by catch)
2. Wraps everything in `DB::transaction`
3. Calls `verifyForgetPasswordToken()` for OTP verification
4. Finds user via `$this->repository->where('email', $request->email)->first()`
5. Updates password, deletes all tokens, deletes password_resets record

## Tests
- **Feature Test Files**: None found specifically for password reset flow
- Password reset assertions should be added to auth feature tests
