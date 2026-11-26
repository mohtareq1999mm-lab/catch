<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #132/#139/#140/#141/#142: Round 13 decisions are recorded before any
 * implementation ticket proceeds. This static guard prevents the decision
 * record from silently losing its approval, privacy, or authority boundaries.
 */
class Round13DecisionRecordTest extends TestCase
{
    private function record(): string
    {
        $path = __DIR__ . '/../../docs/adr/0005-round13-open-decisions.md';

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_round13_decision_record_has_owner_and_date(): void
    {
        $record = $this->record();

        $this->assertStringContainsString('Date: 2026-11-25', $record);
        $this->assertStringContainsString('Approved by: `mohtareq1999mm-lab`', $record);
        $this->assertStringContainsString('R13-Q1', $record);
        $this->assertStringContainsString('remains cancelled', $record);
    }

    public function test_replacement_rule_is_explicit_and_conservative(): void
    {
        $record = $this->record();

        $this->assertStringContainsString('`replacement_for_shipment_id`', $record);
        $this->assertStringContainsString('one-to-one', $record);
        $this->assertStringContainsString('excluded from the outstanding aggregate `R`', $record);
        $this->assertStringContainsString('Provider confirmation is required', $record);
        $this->assertStringContainsString('restocking remains owned by', $record);
        $this->assertStringContainsString('Operations and finance approval are required', $record);
        $this->assertStringContainsString('**Not approved**: generic re-shipping', $record);
    }

    public function test_finance_permissions_are_explicit_and_never_payment_authority(): void
    {
        $record = $this->record();

        foreach ([
            'cod-settlement.view',
            'cod-settlement.match',
            'cod-settlement.accept',
            'cod-settlement.close',
            'cod-adjustment.approve',
        ] as $permission) {
            $this->assertStringContainsString($permission, $record);
        }

        $this->assertStringContainsString('never include `payments.*`', $record);
        $this->assertStringContainsString('Broad super-admin seeding excludes finance permissions', $record);
        $this->assertStringContainsString('bounded expiry', $record);
    }

    public function test_cod_adjustment_and_settlement_defaults_are_fail_closed(): void
    {
        $record = $this->record();

        $this->assertStringContainsString('configuration, not code constants', $record);
        $this->assertStringContainsString('per currency and expressed in minor units', $record);
        $this->assertStringContainsString('effective threshold is `0`', $record);
        $this->assertStringContainsString('two distinct approvals', $record);
        $this->assertStringContainsString('zero by default', $record);
        $this->assertStringContainsString('never applies to unmatched lines', $record);
        $this->assertStringContainsString('never change payment completion authority', $record);
    }

    public function test_operations_notification_and_privacy_decisions_are_recorded(): void
    {
        $record = $this->record();

        $this->assertStringContainsString('R13-Q5', $record);
        $this->assertStringContainsString('`shipping-operations`', $record);
        $this->assertStringContainsString('one business day', $record);
        $this->assertStringContainsString('`owner_id` and `sla_due_at`', $record);
        $this->assertStringContainsString('`Order::SHIPMENT_STATUS_DELAYED`', $record);
        $this->assertStringContainsString('**Not approved**: an unowned unknown-status queue', $record);

        $this->assertStringContainsString('R13-Q6', $record);
        $this->assertStringContainsString('Guest shipment notifications remain disabled pending legal sign-off', $record);
        $this->assertStringContainsString('Registered-user notification preferences apply', $record);
        $this->assertStringContainsString('**Not approved**: guest marketing notifications or inferred consent', $record);

        $this->assertStringContainsString('R13-Q7', $record);
        $this->assertStringContainsString('must not expose item names or prices', $record);
        $this->assertStringContainsString('valid guest-token detail surface', $record);
        $this->assertStringContainsString('**Not approved**: public price/item payloads', $record);

        $this->assertStringContainsString('R13-Q8', $record);
        $this->assertStringContainsString('zero by default', $record);
        $this->assertStringContainsString('never applies to unmatched lines', $record);
    }
}
