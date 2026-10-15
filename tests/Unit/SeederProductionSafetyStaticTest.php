<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seeder production safety (static source audit).
 *
 * Incident: `php artisan db:seed` in production crashed with a 403
 * HttpException because DatabaseSeeder unconditionally invoked
 * WmsOperationsSeeder, which fail-closes via abort_if(isProduction).
 * Worse, AdminOrderOperationsSeeder ran BEFORE it with no guard while
 * resetting admin@gmail.com to a public known password, minting a Sanctum
 * token on every run, and creating ORD-TEST-* orders.
 *
 * Policy enforced here (no database required):
 * - DatabaseSeeder must gate demo accounts + the demo/test-data block
 *   behind app()->isProduction() with an early return, so the default
 *   `db:seed` path succeeds in production without test fixtures.
 * - Every seeder that writes test users, resets credentials, mints tokens,
 *   fabricates orders/analytics, or uses non-idempotent demo ::create must
 *   individually fail closed via abort_if(app()->isProduction(), 403), so
 *   `db:seed --class=X` cannot bypass the DatabaseSeeder skip.
 */
class SeederProductionSafetyStaticTest extends TestCase
{
    private function seederSource(string $class): string
    {
        return (string) file_get_contents(__DIR__.'/../../database/seeders/'.$class.'.php');
    }

    public function test_database_seeder_gates_demo_accounts_behind_non_production(): void
    {
        $src = $this->seederSource('DatabaseSeeder');

        $this->assertStringContainsString('if (! app()->isProduction())', $src);
        // The known-password demo accounts must live inside the gated block.
        $gatedPos = strpos($src, 'if (! app()->isProduction())');
        $this->assertNotFalse($gatedPos);
        $this->assertStringContainsString(
            'admin@demo.com',
            substr($src, (int) $gatedPos),
            'admin@demo.com creation must stay inside the non-production block'
        );
    }

    public function test_database_seeder_skips_demo_block_in_production(): void
    {
        $src = $this->seederSource('DatabaseSeeder');

        $skipPos = strpos($src, 'if (app()->isProduction())');
        $this->assertNotFalse($skipPos, 'DatabaseSeeder must skip demo seeders in production');

        $tail = substr($src, (int) $skipPos);
        // Production path must return before any demo seeder invocation.
        $returnPos = strpos($tail, 'return;');
        $this->assertNotFalse($returnPos);
        $demoCallPos = strpos($tail, 'WmsOperationsSeeder::class');
        $this->assertNotFalse($demoCallPos);
        $this->assertLessThan(
            $demoCallPos,
            $returnPos,
            'production early-return must precede the WMS scenario seeder invocation'
        );
        foreach (['AdminOrderOperationsSeeder::class', 'DashboardDataSeeder::class', 'WarehouseSeeder::class'] as $demo) {
            $this->assertGreaterThan(
                $returnPos,
                strpos($tail, $demo),
                $demo.' must stay in the skipped demo block'
            );
        }
    }

    public function test_database_seeder_keeps_infrastructure_always_run(): void
    {
        $src = $this->seederSource('DatabaseSeeder');

        $skipPos = strpos($src, 'if (app()->isProduction())');
        $this->assertNotFalse($skipPos);
        $head = substr($src, 0, (int) $skipPos);
        foreach ([
            'PermissionSeeder::class',
            'SettingSeeder::class',
            'CurrencySeeder::class',
            'LocationSeeder::class',
            'OrderFlowSeeder::class',
            'FlowInputSeeder::class',
            'OrderStatusPermissionSeeder::class',
        ] as $infra) {
            $this->assertStringContainsString($infra, $head, $infra.' must remain in the always-run baseline');
        }
    }

    /**
     * @dataProvider guardedSeederProvider
     */
    public function test_demo_seeder_fail_closes_in_production(string $class, string $message): void
    {
        $src = $this->seederSource($class);

        $this->assertStringContainsString(
            'abort_if(app()->isProduction(), 403',
            $src,
            $class.' must fail closed in production'
        );
        $this->assertStringContainsString($message, $src);
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function guardedSeederProvider(): array
    {
        return [
            'wms scenarios' => ['WmsOperationsSeeder', 'WmsOperationsSeeder is blocked in production.'],
            'admin order ops' => ['AdminOrderOperationsSeeder', 'AdminOrderOperationsSeeder is blocked in production.'],
            'dashboard fixtures' => ['DashboardDataSeeder', 'DashboardDataSeeder is blocked in production.'],
            'warehouse fixture' => ['WarehouseSeeder', 'WarehouseSeeder is blocked in production.'],
        ];
    }

    public function test_admin_order_operations_token_is_idempotent(): void
    {
        // Regression: every reseed minted a new personal_access_token row and
        // printed a fresh bearer token. Must reuse the existing token row.
        $src = $this->seederSource('AdminOrderOperationsSeeder');

        $this->assertStringContainsString("where('name', 'AdminOrderOperationsToken')->exists()", $src);
    }
}
