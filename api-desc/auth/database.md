# Auth — Database Schema

## Tables Involved

### `users`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| name | varchar(255) | |
| email | varchar(255) | UNIQUE, nullable — optional for REST customers (may be `NULL`; `nullable\|sometimes\|email\|unique:users,email\|email:rfc,dns` per `packages/marvel/src/Http/Requests/UserCreateRequest.php:30-38`) |
| phone_number | varchar(255) | UNIQUE, nullable (DB) but **required** for REST registration (`required` per `UserCreateRequest`; `POST /api/v1/register` accepts `email` omitted or `email: null`) |
| password | varchar(255) | bcrypt hash |
| type | varchar(255) | 'user' or 'admin' |
| is_active | tinyint(1) | default true |
| email_verified_at | timestamp | nullable (`null` for phone-only customers; `email = null` is valid state, not an error) |
| shop_id | bigint | nullable, FK to shops |
| created_at | timestamp | |
| updated_at | timestamp | |
| deleted_at | timestamp | nullable (SoftDeletes) |

> **REST optional email:** Backend makes `users.email` optional for REST — `POST /api/v1/register` accepts `email` omitted or `email: null` and persists `users.email = NULL` (`phone_number` is required). `email = null` with `email_verified_at = null` is a valid customer state, not an error (see `api.md` / `authentication.md` for phone-only vs with-email request/response examples and `backend.md` for the `if ($user->email)` guard around `sendOneTimePassword()` at `packages/marvel/src/Http/Controllers/UserController.php:668-686`). `UNIQUE` on `email` allows multiple `NULL`s; non-null duplicates are rejected. `POST /api/v1/token` supports `phone_number + password` via `required_without` (`packages/marvel/src/Http/Requests/UserAuthEmailAndPasswordRequest.php`). Admin creation and GraphQL `RegisterInput.email: String!` remain email-required (unchanged).

### `password_resets`
| Column | Type | Notes |
|--------|------|-------|
| email | varchar(255) | INDEX |
| token | varchar(255) | bcrypt hash of 6-char OTP |
| created_at | timestamp | Used for 5-min expiry check |

### `personal_access_tokens`
Sanctum token storage — stores the plaintext token hash, abilities, and expiration.

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| tokenable_type | varchar(255) | 'Marvel\Database\Models\User' |
| tokenable_id | bigint | FK to users.id |
| name | varchar(255) | 'auth_token' |
| token | varchar(64) | SHA-256 hash |
| abilities | text | nullable |
| last_used_at | timestamp | nullable |
| expires_at | timestamp | nullable |
| created_at | timestamp | |
| updated_at | timestamp | |

### `user_providers` (social login)
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| user_id | bigint | FK to users.id |
| provider | varchar(255) | 'google' or 'facebook' |
| provider_user_id | varchar(255) | Provider's user ID |
| created_at | timestamp | |
| updated_at | timestamp | |

### `model_has_roles` (Spatie Permission)
| Column | Type | Notes |
|--------|------|-------|
| role_id | bigint | FK to roles |
| model_type | varchar(255) | 'Marvel\Database\Models\User' |
| model_id | bigint | FK to users.id |

### `model_has_permissions` (Spatie Permission)
| Column | Type | Notes |
|--------|------|-------|
| permission_id | bigint | FK to permissions |
| model_type | varchar(255) | |
| model_id | bigint | |

### `media` (Spatie MediaLibrary — avatar)
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| model_type | varchar(255) | 'Marvel\Database\Models\User' |
| model_id | bigint | FK to users.id |
| collection_name | varchar(255) | 'avatar' |
| file_name | varchar(255) | |
| ... | ... | Standard Spatie media columns |

## Key Indexes
- `users.email` — UNIQUE
- `users.phone_number` — UNIQUE
- `password_resets.email` — INDEX
- `personal_access_tokens.tokenable_type, tokenable_id` — INDEX
- `user_providers.user_id` — FK INDEX

## Transaction Usage
- `register()` — wrapped in DB::transaction for user creation + role assignment + media upload
- `resetPassword()` — wrapped in DB::transaction for token verification + password update + token deletion + password_resets cleanup
