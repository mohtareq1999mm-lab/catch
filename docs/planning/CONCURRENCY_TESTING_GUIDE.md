# CONCURRENCY TESTING GUIDE

## Overview

The concurrency tests verify the FOR UPDATE locking mechanism and UNIQUE constraint behavior under actual concurrent load. These tests **MUST** be run against a real MySQL database (not SQLite :memory:).

## Prerequisites

1. **MySQL 8.4.3** (or compatible version)
2. **Running MySQL server** on localhost:3306
3. **Test database**: `marvel_laravel_test`
4. **Database user**: root (or update phpunit.concurrency.xml)

## Setup

### 1. Create Test Database

```bash
# Using MySQL CLI
mysql -u root -p -e "DROP DATABASE IF EXISTS marvel_laravel_test; CREATE DATABASE marvel_laravel_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# OR using PHP script
php setup_test_db.php
```

### 2. Run Migrations

```bash
php artisan migrate --database=mysql --env=testing
```

### 3. Verify Database Connection

```bash
php artisan tinker
>>> DB::connection('mysql')->getPdo();
>>> DB::connection('mysql')->select('SELECT VERSION()');
```

## Running Concurrency Tests

### Full Concurrency Test Suite

```bash
php artisan test --configuration=phpunit.concurrency.xml
```

### Individual Test Scenarios

```bash
# Test A: Single slot with multiple users
php artisan test --configuration=phpunit.concurrency.xml --filter=test_single_slot_with_multiple_concurrent_users

# Test B: Same user, multiple concurrent attempts
php artisan test --configuration=phpunit.concurrency.xml --filter=test_same_user_concurrent_attempts

# Test C: Multiple slots enforcement
php artisan test --configuration=phpunit.concurrency.xml --filter=test_multiple_slots_enforcement

# Test D: Different coupons (no global serialization)
php artisan test --configuration=phpunit.concurrency.xml --filter=test_different_coupons_no_global_serialization

# Test E: Transaction rollback behavior
php artisan test --configuration=phpunit.concurrency.xml --filter=test_rollback_behavior

# Test F: UNIQUE constraint handling
php artisan test --configuration=phpunit.concurrency.xml --filter=test_unique_constraint_duplicate_key_handling

# Test G: FOR UPDATE lock serialization
php artisan test --configuration=phpunit.concurrency.xml --filter=test_for_update_lock_serialization
```

## Test Scenarios Explained

### Test A: max_claims_per_user = 1, Multiple Users
- **Setup**: 10 concurrent users claiming same coupon (max 1 claim each)
- **Expected**: Each user gets at most 1 claim
- **Verifies**: Per-user limit enforcement

### Test B: Same User, Concurrent Attempts
- **Setup**: Same user attempts claim 5 times concurrently
- **Expected**: Exactly 1 successful claim
- **Verifies**: UNIQUE(coupon_id, user_id) constraint + duplicate detection

### Test C: Multiple Slots (max_claims = 5)
- **Setup**: 5 users, each attempting 10 claims
- **Expected**: Each user gets at most 5 claims
- **Verifies**: Max claims limit enforcement

### Test D: Different Coupons
- **Setup**: 5 users claiming 3 different coupons concurrently
- **Expected**: No blocking between different coupons
- **Verifies**: Per-coupon locking (no global serialization)

### Test E: Rollback Behavior
- **Setup**: Create claim then force transaction rollback
- **Expected**: No residual claim records
- **Verifies**: Transaction integrity

### Test F: UNIQUE Constraint Race
- **Setup**: Create claim, then attempt duplicate
- **Expected**: QueryException on duplicate
- **Verifies**: Database constraint enforcement

### Test G: FOR UPDATE Serialization
- **Setup**: 20 users claiming same coupon (high limit)
- **Expected**: All succeed, exactly 1 claim each
- **Verifies**: Lock serializes access, preventing duplicate key errors

## Expected Results

All tests should **PASS** with:
- 7 tests
- 0 failures
- 0 errors
- Multiple assertions per test

## What These Tests Prove

✅ **Atomicity**: UNIQUE constraint prevents duplicate claims
✅ **Serialization**: FOR UPDATE lock serializes concurrent access
✅ **Isolation**: Different coupons don't block each other
✅ **Rollback Safety**: Failed transactions leave no residue
✅ **Limit Enforcement**: max_claims_per_user is atomic
✅ **Duplicate Detection**: Same user cannot claim twice

## Limitations of Current Implementation

These tests simulate concurrency using **sequential API requests** with different users. This tests the application logic but **does not** fully stress-test true parallel execution with:

- Multiple separate PHP processes
- Multiple independent database connections
- Synchronized start times (barrier pattern)
- True concurrent transaction execution

### For True Multi-Process Testing

Consider creating a dedicated harness:

1. **Multi-process PHP script** using `pcntl_fork()` or separate `php` invocations
2. **Barrier synchronization** (file-based or Redis-based)
3. **Independent PDO connections** per process
4. **Statistical verification** of race conditions

Example structure:
```bash
# Master process spawns N workers
for i in {1..50}; do
  php concurrency_worker.php $COUPON_ID $USER_ID &
done
wait
# Verify results in database
```

## Troubleshooting

### MySQL Connection Refused
```
Error: SQLSTATE[HY000] [2002] No connection could be made
```
**Solution**: Start MySQL server
```bash
# Windows (XAMPP)
xampp-control.exe

# Linux/Mac
sudo systemctl start mysql
# OR
brew services start mysql
```

### Database Permission Denied
```
Error: SQLSTATE[HY000] [1044] Access denied for user
```
**Solution**: Grant privileges
```sql
GRANT ALL PRIVILEGES ON marvel_laravel_test.* TO 'root'@'localhost';
FLUSH PRIVILEGES;
```

### Tests Skipped
```
Tests:  7 skipped
```
**Solution**: Verify phpunit.concurrency.xml has correct DB credentials and MySQL is running.

### Migration Errors
```
SQLSTATE[42S01]: Base table or view already exists
```
**Solution**: Fresh database
```bash
php artisan migrate:fresh --database=mysql --env=testing
```

## CI/CD Integration

For production-grade verification, integrate concurrency tests into CI/CD:

```yaml
# .github/workflows/concurrency-tests.yml
name: Concurrency Tests

on: [push, pull_request]

jobs:
  concurrency:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.4.3
        env:
          MYSQL_ROOT_PASSWORD: secret
          MYSQL_DATABASE: marvel_laravel_test
        options: >-
          --health-cmd="mysqladmin ping"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=3
    steps:
      - uses: actions/checkout@v3
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.2
      - name: Install Dependencies
        run: composer install
      - name: Run Concurrency Tests
        run: php artisan test --configuration=phpunit.concurrency.xml
```

## Verification Checklist

Before declaring production-ready:

- [ ] MySQL server running
- [ ] Test database created
- [ ] Migrations executed
- [ ] All 7 concurrency tests passing
- [ ] No deadlocks observed
- [ ] No duplicate claims in database
- [ ] Logs reviewed for lock timeouts
- [ ] Performance acceptable (<2s per test)

## Next Steps After Tests Pass

1. ✅ Update FINAL_PRODUCTION_GATE_REPORT.md with actual results
2. ✅ Mark concurrency verification as COMPLETE
3. ✅ Document observed lock behavior
4. ✅ Declare PRODUCTION READY (if all gates pass)
