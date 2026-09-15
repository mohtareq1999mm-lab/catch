# Real Pusher E2E Test

This test verifies **L4 connectivity** (real client receives real events from real Pusher) for all six Import/Export operations.

## Prerequisites

1. Laravel application running locally (http://localhost:80)
2. Queue worker running: `php artisan queue:work --queue=catch-medium --tries=2 --timeout=1300`
3. Valid Bearer token for authentication
4. Sample XLSX files for import operations

## Configuration

Edit `.env` in this directory:

```env
API_BASE_URL=http://localhost:80/api/v1
PUSHER_APP_KEY=b253bacbb615d39f2956
PUSHER_APP_CLUSTER=eu
TEST_BEARER_TOKEN=your-token-here
TEST_USER_ID=1
```

## Get Bearer Token

```bash
# Login via API to get token
curl -X POST http://localhost:80/api/v1/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}'

# Extract token from response and set in .env
```

## Sample Files

Create sample XLSX files in this directory:
- `product-sample.xlsx` - at least 1 row with SKU, name, price
- `category-sample.xlsx` - at least 1 row with name (EN), name (AR)
- `brand-sample.xlsx` - at least 1 row with name

## Run Test

```bash
node ../tests/pusher-e2e-test.js
```

## What This Test Proves

For each of the six operations (product/category/brand import/export), the test:

1. ✅ Connects to real Pusher service
2. ✅ Subscribes to `private-users.{userId}` channel
3. ✅ Starts the operation through real API endpoint
4. ✅ Receives `*.queued` event from real Pusher
5. ✅ Receives `*.progress` events from real Pusher
6. ✅ Receives terminal event (`*.completed` / `*.failed`) from real Pusher
7. ✅ Reconciles event state with DB state via GET status endpoint
8. ✅ Verifies `download_available` flag accuracy

## Expected Output

```
# REAL PUSHER + IMPORT/EXPORT CERTIFICATION

Overall Status: PASS

## Pusher
Connection: PASS
Private Channel: PASS

## Operations

product-import: PASS
  API Start: PASS
  Queued Event: PASS
  Progress Event: PASS
  Terminal Event: PASS
  DB Match: PASS
  Events: product.import.queued, product.import.progress, product.import.completed
  DB State: completed

[... same for all 6 operations ...]

## Final Certification: PASS
```

## Troubleshooting

### "Pusher connection failed"
- Check PUSHER_APP_KEY and PUSHER_APP_CLUSTER in .env
- Verify Laravel `.env` has correct Pusher credentials
- Check `BROADCAST_DRIVER=pusher` in Laravel .env

### "Channel subscription failed" (403)
- Invalid Bearer token
- Token user ID doesn't match TEST_USER_ID
- Check `/broadcasting/auth` route authorization

### "Timeout waiting for terminal event"
- Queue worker not running
- Job failed (check `laravel.log`)
- Import file invalid
- DB connection issue

### "Event state != DB state"
- Race condition (rare)
- Re-run test
- Check terminal broadcast logic in Jobs

## Non-L4 Tests (Lower Level)

This E2E test is the **only** L4 proof. Other tests in the suite prove lower levels:

- **L1** (Event created): `FileOperationBroadcastTestCase` + all broadcast tests
- **L2** (Broadcaster invoked): RecordingPusher swapped into PusherBroadcaster
- **L3** (Pusher accepted): `CategoryImportProgressRealPusherTest` connectivity check

L1-L3 are necessary but not sufficient. L4 is mandatory for certification.
