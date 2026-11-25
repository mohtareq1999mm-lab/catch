<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use Tests\TestCase;

/**
 * #185: Phase 0 closure is gated by authoritative risk identities and reviewable evidence.
 */
class Phase0EvidenceGateTest extends TestCase
{
    private const EVIDENCE_FILE = 'docs/audits/SETTLEMENT_PHASE0_EVIDENCE.md';

    /**
     * Doc 1 §5.1 / Doc 7 Phase 0: identity, title, and dependency order.
     *
     * @var array<string, string>
     */
    private const RISK_MATRIX = [
        'R7' => 'Single transition emitter',
        'R4' => 'Single mirror writer + canonical ETA + aggregate truth table',
        'R1' => 'Creation gate',
        'R2' => 'updateStatus / delivered convergence',
        'R3' => 'Admin endpoint delegation',
        'R5' => 'Delayed/failed paths',
        'R6' => 'Per-item warehouse authorization',
        'R8' => 'Event namespace/listeners',
        'R10' => 'ReturnStatus ownership',
    ];

    /**
     * Terms that distinguish the authoritative risk from a generic progress note.
     *
     * @var array<string, list<string>>
     */
    private const EXPECTED_TERMS = [
        'R7' => ['timeline', 'ShipmentStatusChanged', 'after commit', 'rollback'],
        'R4' => ['syncOrderMirror', 'truth table', 'ETA'],
        'R1' => ['ready_to_ship', 'createForFulfillment', 'route:list'],
        'R2' => ['markDelivered', 'delivered', 'convergence'],
        'R3' => ['ShipmentService', 'mirror', 'transition dispatch'],
        'R5' => ['delayed', 'failed_delivery', 'terminal'],
        'R6' => ['warehouse', 'bulk', '403'],
        'R8' => ['App\\Events', 'listener', 'providers'],
        'R10' => ['ReturnStatus', 'read-compatible', 'restocked'],
    ];

    /** @var list<string> */
    private const STALE_LABELS = [
        'Canonical dispatch boundary',
        'Amended aggregate mirror / ETA',
        'Launch API inventory / route:list',
        'Delivered-bypass regression',
        'COD payment completion authority',
        'Finance permission separation',
        'Sandbox/production isolation',
        'Settlement file storage and parser',
        'Phase 0 closure matrix / gating',
    ];

    public function test_evidence_is_recorded_for_every_phase0_risk_in_dependency_order(): void
    {
        $path = base_path(self::EVIDENCE_FILE);
        $this->assertFileExists($path);

        $markdown = (string) file_get_contents($path);
        $sections = preg_split('/^## /m', $markdown) ?: [];
        $positions = [];

        $this->assertStringContainsString('docs/shipping-discovery/01-existing-architecture-audit.md', $markdown);
        $this->assertStringContainsString('docs/shipping-discovery/07-implementation-backlog.md', $markdown);
        $this->assertStringContainsString('No reviewer approval is claimed', $markdown);

        foreach (self::STALE_LABELS as $staleLabel) {
            $this->assertStringNotContainsString($staleLabel, $markdown, "Stale provisional label still present: {$staleLabel}");
        }

        foreach (self::RISK_MATRIX as $risk => $title) {
            $heading = $risk . ' ' . $title;
            $section = null;
            foreach ($sections as $candidate) {
                if (str_starts_with($candidate, $heading)) {
                    $section = $candidate;

                    break;
                }
            }

            $this->assertNotNull($section, "Missing authoritative Phase 0 evidence section for {$heading}.");

            foreach (['Command:', 'Environment:', 'Result:', 'Classification:', 'Evidence:', 'Status:'] as $field) {
                $this->assertMatchesRegularExpression(
                    '/^' . preg_quote($field, '/') . '\s*\S.{2,}$/m',
                    $section,
                    "{$risk} is missing meaningful {$field} evidence"
                );
            }

            $this->assertMatchesRegularExpression('/^Command:.*run_wt_tests\.ps1.*catch_185fix/m', $section, "{$risk} has no reproducible command");
            $this->assertMatchesRegularExpression('/^Environment:.*APP_ENV=testing.*catch_185fix.*$/m', $section, "{$risk} has no isolated environment evidence");
            $this->assertMatchesRegularExpression('/^Result:.*PASS.*PENDING REVIEW.*$/m', $section, "{$risk} must report a real result without claiming closure");
            $this->assertStringContainsString('Closure requirement:', $section, "{$risk} is missing its authoritative closure artifact");
            $this->assertStringContainsString('Gap:', $section, "{$risk} is missing its outstanding-evidence gap");
            $this->assertStringContainsString('Status: PENDING REVIEW', $section);
            $this->assertStringNotContainsString('Status: CLOSED', $section);

            foreach (self::EXPECTED_TERMS[$risk] as $term) {
                $this->assertStringContainsString($term, $section, "{$risk} evidence must address '{$term}'");
            }

            $position = strpos($markdown, '## ' . $heading);
            $this->assertIsInt($position);
            $positions[] = $position;
        }

        $ordered = $positions;
        sort($ordered);
        $this->assertSame($ordered, $positions, 'Phase 0 evidence risks must follow R7→R4→R1→R2→R3→R5→R6→R8→R10.');
    }
}
