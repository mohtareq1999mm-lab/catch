# Password — Database Schema

## Tables

### `password_resets`
| Column | Type | Notes |
|--------|------|-------|
| email | varchar(255) | INDEX, user's email address |
| token | varchar(255) | bcrypt hash of 6-char OTP |
| created_at | timestamp | Used for 5-minute expiry check |

### `users`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| email | varchar(255) | UNIQUE, used for lookup |
| password | varchar(255) | bcrypt hash, updated on reset |

### `personal_access_tokens`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| tokenable_id | bigint | FK to users.id — all deleted on password reset |
| token | varchar(64) | SHA-256 hash of plaintext token |

## Key Indexes
- `password_resets.email` — INDEX (no FK constraint, no model)
- `users.email` — UNIQUE INDEX

## Transaction Usage
`resetPassword()` wraps the following in `DB::transaction`:
1. OTP verification (read-only)
2. `UPDATE users SET password = ? WHERE id = ?`
3. `DELETE FROM personal_access_tokens WHERE tokenable_id = ?`
4. `DELETE FROM password_resets WHERE email = ?`

## No Migrations
The `password_resets` table is created by Laravel's default migration (`0000_00_00_000000_create_password_resets_table.php`) — no custom migration needed.
