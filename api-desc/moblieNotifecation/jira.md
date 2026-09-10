# Jira — Backend Tasks (Mobile Notification / FCM)

---

## FCM-001: Place production service-account JSONs
**Priority:** Blocker · **Status:** OPEN (external)

Copy the two downloaded files into `storage/app/firebase/` using the exact filenames referenced by `.env`:
- `meem-client-a-firebase-adminsdk-fbsvc-4bfc6be112.json`
- `meem-client-b-firebase-adminsdk-fbsvc-e88cd2fd8b.json`

Never commit. `storage/app/firebase/*` is gitignored.

**Accept:** `php artisan tinker --execute="app(App\Services\Firebase\FirebaseProjectResolver::class)->credentialsPath('client_a');"` returns an existing path for both clients.

## FCM-002: Run migration on MySQL
**Status:** OPEN (external — MySQL host was refusing connections)

`php artisan migrate --force` then verify: unique `token`, unique `uuid`, FK cascade, index `(user_id, client)`.

## FCM-003: Verify meem-medium worker runtime
**Status:** OPEN (external)

Config exists at `deploy/supervisor/laravel-worker-meem-medium.conf`. On server:
```
supervisorctl reread && supervisorctl update && supervisorctl status
```
Confirm a worker process shows the queue group containing `meem-medium`.

## FCM-004: Real device smoke test — Client A & B
**Status:** OPEN (needs one real registration token per app)

1. Register token via `POST /general/device-tokens` with matching `client`.
2. Trigger any notification event.
3. Verify Firebase response `success_count ≥ 1` and tray delivery on device.
4. Repeat for the second project. Confirm no cross-project send.

## DONE
- ✅ Device-token API + lifecycle (register/reassign/multi-device/delete-scope)
- ✅ Signed view/download PDF routes (10-min TTL) + invalid-token cleanup design
- ✅ mPDF engine (Arabic shaping) replacing DomPDF; verify block removed from PDF
- ✅ Channel registered in AppServiceProvider (`via('fcm')` resolves)
- ✅ Payload single-source enforced (`toDatabase()` only, zero `toFcm()`)
- ✅ 25-notification coverage audit (24 FCM + VerifyEmail documented exclusion)