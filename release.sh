#!/bin/sh
set -e

# =============================================================================
# Railway Release Command Script
# This runs BEFORE the main container starts (separate from healthcheck)
# =============================================================================

echo "🚀 Running Railway Release Command..."
echo "   Working directory: $(pwd)"

cd /var/www/html

# =============================================================================
# 1. Validate APP_KEY is set
# =============================================================================
if [ -z "${APP_KEY}" ]; then
    echo "❌ ERROR: APP_KEY environment variable is not set!"
    exit 1
fi

# =============================================================================
# 2. Run migrations (if enabled)
# =============================================================================
echo ""
echo "=========================================="
echo "        MIGRATION CHECK"
echo "=========================================="
echo "RUN_MIGRATIONS = '${RUN_MIGRATIONS}'"
echo ""

if [ "${RUN_MIGRATIONS}" = "true" ]; then
    echo "🔄 Running database migrations..."
    echo "   DB_HOST: ${DB_HOST}"
    echo "   DB_PORT: ${DB_PORT}"
    echo "   DB_DATABASE: ${DB_DATABASE}"
    echo ""
    
    # Run migrations safely (never use migrate:fresh in production)
    echo "🚀 Executing: php artisan migrate --force"
    if php artisan migrate --force; then
        echo "✅ Migrations completed successfully!"
        
        # Run seeding if enabled
        if [ "${RUN_SEED}" = "true" ]; then
            echo ""
            echo "🌱 Running Marvel seed..."
            if php artisan marvel:seed; then
                echo "✅ Seeding completed successfully!"
            else
                echo "❌ Seeding failed! Error code: $?"
                echo "   You may need to run 'php artisan marvel:seed' manually"
            fi
        fi
    else
        echo "❌ Migration failed! Error code: $?"
        exit 1
    fi
else
    echo "⏭️  Skipping migrations (RUN_MIGRATIONS is not 'true')"
fi

echo ""
echo "✅ Release command completed!"
