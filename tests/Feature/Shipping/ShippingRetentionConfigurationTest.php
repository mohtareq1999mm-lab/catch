<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

/**
 * #136: shipping evidence retention is policy metadata only.
 *
 * Values may be recorded for future, separately approved policy work.
 * Nothing in this configuration may schedule, dispatch, or execute deletion.
 */
final class ShippingRetentionConfigurationTest extends TestCase
{
    /** @var list<string> */
    private const EVIDENCE_TYPES = [
        'webhook_events',
        'redacted_payloads',
        'labels',
        'proof_of_delivery',
        'settlement_files',
    ];

    /** @var list<string> */
    private const DESTRUCTIVE_MARKERS = [
        'shipping-retention',
        'shipping:prune',
        'prune-shipping',
        'shipping-evidence',
    ];

    public function test_each_shipping_evidence_type_has_a_configured_retention_period(): void
    {
        $retention = config('shipping-retention');

        $this->assertIsArray($retention, 'shipping-retention config must be loaded');

        foreach (self::EVIDENCE_TYPES as $type) {
            $this->assertArrayHasKey($type, $retention, "Missing retention period for {$type}");
            $this->assertIsInt($retention[$type], "Retention period for {$type} must be days as an integer");
            $this->assertGreaterThan(0, $retention[$type], "Retention period for {$type} must be positive");
        }
    }

    public function test_retention_config_exposes_periods_only_and_no_execution_controls(): void
    {
        $retention = config('shipping-retention');

        $this->assertSame(self::EVIDENCE_TYPES, array_keys($retention));
        $this->assertSame([], array_filter($retention, static fn ($days) => ! is_int($days)));
    }

    public function test_no_shipping_retention_pruning_path_is_registered(): void
    {
        $registrations = [];

        foreach (app(Schedule::class)->events() as $event) {
            foreach ([$event->command, $event->description] as $field) {
                $description = $this->describe($field);

                if ($this->mentionsShippingRetention($description)) {
                    $registrations[] = 'scheduled: ' . $description;
                }
            }
        }

        foreach (array_keys(app(ConsoleKernel::class)->all()) as $command) {
            if ($this->mentionsShippingRetention((string) $command)) {
                $registrations[] = 'command: ' . $command;
            }
        }

        foreach (app('events')->getRawListeners() as $event => $listeners) {
            foreach ((array) $listeners as $listener) {
                $description = (string) $event . ' -> ' . $this->describe($listener);

                if ($this->mentionsShippingRetention($description)) {
                    $registrations[] = 'listener: ' . $description;
                }
            }
        }

        $this->assertSame([], $registrations, 'Configuring retention must not register a destructive path');
    }

    private function mentionsShippingRetention(string $value): bool
    {
        $value = strtolower($value);

        foreach (self::DESTRUCTIVE_MARKERS as $marker) {
            if (str_contains($value, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function describe(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_object($value) ? get_class($value) : '';
    }
}
