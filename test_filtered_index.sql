-- Test filtered/partial unique index support
-- This script tests whether the database supports WHERE clause in unique indexes

-- Create test table matching Phase 2 coupon_claims schema
DROP TABLE IF EXISTS test_coupon_claims;

CREATE TABLE test_coupon_claims (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coupon_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active', 'expired', 'redeemed')),
    claimed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    redeemed_at TIMESTAMP NULL
);

-- Attempt to create filtered unique index (MySQL 8.0.13+ / SQLite 3.8.0+ syntax)
-- This should enforce: ONE active claim per (coupon_id, user_id)
CREATE UNIQUE INDEX idx_active_claim
ON test_coupon_claims(coupon_id, user_id)
WHERE status = 'active';

-- Test 1: Insert first active claim (should succeed)
INSERT INTO test_coupon_claims (coupon_id, user_id, status) VALUES (1, 100, 'active');

-- Test 2: Insert second active claim same coupon/user (should FAIL)
INSERT INTO test_coupon_claims (coupon_id, user_id, status) VALUES (1, 100, 'active');

-- Test 3: Insert expired claim same coupon/user (should SUCCEED)
INSERT INTO test_coupon_claims (coupon_id, user_id, status) VALUES (1, 100, 'expired');

-- Test 4: Insert redeemed claim same coupon/user (should SUCCEED)
INSERT INTO test_coupon_claims (coupon_id, user_id, status) VALUES (1, 100, 'redeemed');

-- Test 5: Insert another expired claim (should SUCCEED - no unique constraint on expired)
INSERT INTO test_coupon_claims (coupon_id, user_id, status) VALUES (1, 100, 'expired');

-- Verify final state
SELECT id, coupon_id, user_id, status FROM test_coupon_claims ORDER BY id;

-- Cleanup
DROP TABLE test_coupon_claims;
