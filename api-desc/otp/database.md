# OTP — Database Schema

## Tables

### `users`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| email | varchar(255) | UNIQUE, nullable — used for email OTP flow |
| phone_number | varchar(255) | UNIQUE, nullable — used for SMS OTP flow |
| is_active | tinyint(1) | Must be true for OTP login to work |

### `personal_access_tokens`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | Created on successful otpLogin |
| tokenable_id | bigint | FK to users.id |
| name | varchar(255) | 'auth_token' |
| token | varchar(64) | SHA-256 hash of plaintext token |

## OTP Storage

### Email OTP (via Notification)
- Uses Laravel's notification system
- No persistent database storage — OTP is generated, sent, and validated in-memory/notification

### Phone OTP (via Gateway)
- No local storage — OTP is managed entirely by the third-party gateway (Twilio, etc.)
- The gateway returns a `verification_sid` (otp_id) used to check the code
- No `otp_codes` table in the database

## Key Points
- No dedicated OTP table in the database
- Email OTPs are transient (notification-based)
- Phone OTPs are managed by external gateway
- Only persistent record is the Sanctum token created on successful OTP login
