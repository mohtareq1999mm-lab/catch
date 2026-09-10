#!/bin/bash

echo "=== Documentation Audit ==="
echo ""

echo "1. Checking for RabbitMQ references (should be removed)..."
grep -r "RabbitMQ\|rabbitmq\|AMQP" docs/ --include="*.md" | grep -v "removed" | wc -l

echo ""
echo "2. Checking for outdated API response shapes..."
grep -r '"data":\s*{' docs/api-desc/ --include="*.md" | head -5

echo ""
echo "3. Checking for max_claims_per_user (renamed to max_claims)..."
grep -r "max_claims_per_user" docs/ --include="*.md" | wc -l

echo ""
echo "4. Checking for cart reservation references (removed pattern)..."
grep -r "cart.*reservation\|reserved_quantity.*cart" docs/ --include="*.md" | grep -v "never" | wc -l

echo ""
echo "5. List all docs modified before 2026-09-01 (potentially stale)..."
find docs/ -name "*.md" -type f ! -newermt "2026-09-01" | wc -l

echo ""
echo "=== End Audit ==="
