<?php

declare(strict_types=1);

namespace Tests\Unit\Seeding;

use Database\Seeders\Meem\Support\MeemContext;
use Database\Seeders\Meem\Support\MeemSeedException;
use PHPUnit\Framework\TestCase;

/**
 * Run-scoped guards. Pure — no application boot, no database.
 */
final class MeemContextTest extends TestCase
{
    public function test_dry_run_is_the_default(): void
    {
        $context = MeemContext::fromEnvironment(apply: false, profile: 'local');

        $this->assertTrue($context->isDryRun());
        $this->assertSame('local', $context->profile());
    }

    public function test_a_dry_run_can_never_open_a_transaction(): void
    {
        $context = MeemContext::fromEnvironment(apply: false, profile: 'local');

        $this->expectException(MeemSeedException::class);
        $this->expectExceptionMessage('nothing may be written');

        $context->transaction(static fn () => null);
    }

    public function test_production_write_requires_a_batch_id(): void
    {
        $context = new MeemContext(
            profile: 'production',
            dryRun: false,
            force: true,
            batchId: null,
        );

        $this->expectException(MeemSeedException::class);
        $this->expectExceptionMessage('SEEDING_BATCH_ID');

        $context->assertRunnable();
    }

    public function test_production_write_requires_force(): void
    {
        $context = new MeemContext(
            profile: 'production',
            dryRun: false,
            force: false,
            batchId: 'batch-2026-11-15',
        );

        $this->expectException(MeemSeedException::class);
        $this->expectExceptionMessage('--force');

        $context->assertRunnable();
    }

    public function test_production_write_with_batch_id_and_force_is_allowed(): void
    {
        $context = new MeemContext(
            profile: 'production',
            dryRun: false,
            force: true,
            batchId: 'batch-2026-11-15',
        );

        $context->assertRunnable();

        $this->addToAssertionCount(1);
    }

    public function test_a_production_dry_run_needs_no_batch_id(): void
    {
        $context = new MeemContext(
            profile: 'production',
            dryRun: true,
            force: false,
            batchId: null,
        );

        $context->assertRunnable();

        $this->addToAssertionCount(1);
    }

    public function test_profile_aliases_are_normalised(): void
    {
        $this->assertSame('production', MeemContext::fromEnvironment(profile: 'prod')->profile());
        $this->assertSame('staging', MeemContext::fromEnvironment(profile: 'stage')->profile());
        $this->assertSame('local', MeemContext::fromEnvironment(profile: 'development')->profile());
    }

    public function test_differs_ignores_numeric_and_boolean_cast_noise(): void
    {
        $this->assertFalse(MeemContext::differs('50.00', 50));
        $this->assertFalse(MeemContext::differs(false, 0));
        $this->assertFalse(MeemContext::differs(['ar' => 'أ', 'en' => 'A'], ['en' => 'A', 'ar' => 'أ']));

        $this->assertTrue(MeemContext::differs('50.00', '51.00'));
        $this->assertTrue(MeemContext::differs(['en' => 'A'], ['en' => 'B']));
        $this->assertTrue(MeemContext::differs(null, ['en' => 'A']));
    }
}
