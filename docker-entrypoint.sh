#!/bin/sh
set -e

# =============================================================================
# Laravel Docker Entrypoint Script for Railway
# FAST STARTUP - migrations run separately via release.sh
# =============================================================================

echo "🚀 Starting Laravel application..."

cd /var/www/html

# =============================================================================
# 1. Quick validation
# =============================================================================
if [ -z "${APP_KEY}" ]; then
    echo "❌ ERROR: APP_KEY environment variable is not set!"
    exit 1
fi

# =============================================================================
# 2. Create storage link (quick)
# =============================================================================
if [ ! -L "public/storage" ]; then
    php artisan storage:link --force 2>/dev/null || true
fi

# =============================================================================
# 3. Cache configuration (production optimization) - ~5 seconds
# =============================================================================
echo "⚡ Caching configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# =============================================================================
# 4. Start Supervisor (manages web + queue workers + scheduler via cron)
# =============================================================================
echo ""
echo "✅ Laravel ready! Starting Supervisor (web + meem-high + meem-medium) on port ${PORT:-8080}..."
echo "   Workers: meem-high (1 proc, timeout 1300) + meem-medium (1 proc, timeout 1300)"
echo "   Scheduler: via dcron (schedule:run every minute)"

# Ensure log directory exists and is writable
mkdir -p /var/www/html/storage/logs
chown -R www:www /var/www/html/storage/logs 2>/dev/null || true

# Start cron in background (for scheduler)
crond -b -l 8 2>/dev/null || true

exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf -n
