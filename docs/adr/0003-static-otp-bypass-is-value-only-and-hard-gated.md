# Static OTP bypass is value-only and hard-gated

Guest cancellation (and login) OTP can be exercised without an SMS provider via a static code. The task spec proposed enabling that bypass when `LOCAL_OTP_ENABLED=true` **or** `MASTER_OTP` is set. Decision: the static bypass is gated **only** on `local_otp_enabled`, is hard-blocked in production regardless of environment values, and `MASTER_OTP` is a **value override** (falling back to `local_otp_code`), never an enable switch.

Why: `.env.example` ships `MASTER_OTP=123456` unconditionally. Under the spec's OR-gate, a production deploy copied from the example would silently accept a publicly-known code to cancel any order. Gating on `local_otp_enabled` plus a production hard-block makes that misconfiguration harmless.

## Considered Options

- **Spec-literal OR-gating** (`LOCAL_OTP_ENABLED=true` OR `MASTER_OTP` set) — rejected: turns a leftover example value into a production bypass switch.
- **App-side cache-stored codes** instead of provider-managed verification — rejected: a second OTP mechanism divergent from login, with self-owned storage, expiry, replay and attempt surfaces.
- **Chosen**: reuse the provider-managed `OtpInterface` gateway stack (`startVerification` / `checkVerification`), with the committed, unit-tested `LocalGateway` as the dev path, and one universal static-bypass check owned by the cancellation OTP service (so the bypass works on any selected gateway, since dev machines may have `ACTIVE_OTP_GATEWAY=twilio` with no credentials).

## Consequences

- Production can never bypass, even when the environment is misconfigured.
- Development/testing always can, when `local_otp_enabled=true`.
- A leaked master code is harmless in production.
- Config hygiene: `master_otp` should default to `null` (not `'123456'`) so an unset value is distinguishable from an explicitly configured one; the accepted value resolves `MASTER_OTP` if non-empty, else `local_otp_code`.
