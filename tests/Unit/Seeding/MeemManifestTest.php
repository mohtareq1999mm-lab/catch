<?php

declare(strict_types=1);

namespace Tests\Unit\Seeding;

use Database\Seeders\Meem\Support\MeemManifest;
use Database\Seeders\Meem\Support\MeemSeedException;
use PHPUnit\Framework\TestCase;

/**
 * Manifest contract: structure, gates, placeholder discovery.
 *
 * Pure — no application boot, no database.
 */
final class MeemManifestTest extends TestCase
{
    private static function manifestPath(): string
    {
        return dirname(__DIR__, 3) . '/database/seeders/Meem/Data/homepage.seed.json';
    }

    private function realManifest(): MeemManifest
    {
        return MeemManifest::fromFile(self::manifestPath());
    }

    public function test_the_committed_manifest_is_structurally_valid(): void
    {
        $manifest = $this->realManifest();

        $this->assertSame('1.0.0', $manifest->get('meta.version'));
        $this->assertSame(['ar', 'en'], $manifest->get('meta.locale_order'));
        $this->assertSame(MeemManifest::DATASETS, array_intersect_key(
            MeemManifest::DATASETS,
            array_flip($manifest->datasetNames()),
        ));
    }

    public function test_an_unsupported_manifest_version_is_rejected(): void
    {
        $data = json_decode((string) file_get_contents(self::manifestPath()), true);
        $data['meta']['version'] = '2.0.0';

        $this->expectException(MeemSeedException::class);
        $this->expectExceptionMessage('is not supported');

        MeemManifest::fromArray($data);
    }

    public function test_a_missing_dataset_key_is_rejected(): void
    {
        $data = json_decode((string) file_get_contents(self::manifestPath()), true);
        unset($data['coupons']);

        $this->expectException(MeemSeedException::class);
        $this->expectExceptionMessage('dataset [coupons] is missing');

        MeemManifest::fromArray($data);
    }

    public function test_placeholders_are_discovered_inside_a_dataset(): void
    {
        $missing = $this->realManifest()->placeholdersIn('settings');

        $this->assertArrayHasKey('/settings/site_email', $missing);
        $this->assertSame('REQUIRED_EMAIL', $missing['/settings/site_email']);
        $this->assertArrayHasKey('/settings/phone', $missing);
        $this->assertArrayHasKey('/settings/site_desc/ar', $missing);
    }

    public function test_instruction_keys_are_not_reported_as_placeholders(): void
    {
        $missing = $this->realManifest()->placeholdersIn('settings');

        // `gate` holds a governance key, not content.
        $this->assertArrayNotHasKey('/settings/gate', $missing);
    }

    public function test_a_dataset_without_placeholders_reports_none(): void
    {
        $this->assertSame([], $this->realManifest()->placeholdersIn('currency'));
    }

    public function test_angle_bracket_templates_are_treated_as_unresolved(): void
    {
        $missing = $this->realManifest()->placeholdersIn('sections');

        // "<resolved_from_homepage_tile_categories>" and friends.
        $this->assertNotEmpty($missing);
    }

    public function test_an_unsigned_gate_blocks_the_dataset_even_when_values_are_ready(): void
    {
        $manifest = $this->realManifest();

        $state = $manifest->state('currency', 'local');

        $this->assertSame(MeemManifest::SKIP_GATE, $state['state']);
        $this->assertSame('D1_currency_sar_approved', $state['gate']);
        $this->assertFalse($state['gate_signed']);
        $this->assertSame([], $state['missing'], 'currency has no placeholders; the gate alone blocks it');
    }

    public function test_a_signed_gate_with_placeholders_is_still_blocked(): void
    {
        $data = json_decode((string) file_get_contents(self::manifestPath()), true);
        $data['meta']['governance']['D5_contact_and_delivery_approved'] = true;

        $state = MeemManifest::fromArray($data)->state('settings', 'local');

        $this->assertSame(MeemManifest::SKIP_PLACEHOLDER, $state['state']);
        $this->assertNotEmpty($state['missing']);
    }

    public function test_a_signed_gate_without_placeholders_is_ready(): void
    {
        $data = json_decode((string) file_get_contents(self::manifestPath()), true);
        $data['meta']['governance']['D1_currency_sar_approved'] = true;

        $state = MeemManifest::fromArray($data)->state('currency', 'local');

        $this->assertSame(MeemManifest::READY, $state['state']);
    }

    public function test_profile_allow_list_is_honoured(): void
    {
        $manifest = $this->realManifest();

        // `warehouse` declares profiles: ["local","staging"] and is absent from
        // the production dataset list.
        $this->assertSame(MeemManifest::SKIP_PROFILE, $manifest->state('warehouse', 'production')['state']);
        $this->assertNotSame(MeemManifest::SKIP_PROFILE, $manifest->state('warehouse', 'local')['state']);
    }

    public function test_writes_are_refused_for_an_unresolved_payload(): void
    {
        $this->expectException(MeemSeedException::class);
        $this->expectExceptionMessage('unresolved values');

        $this->realManifest()->assertWritable('settings', ['site_email' => 'REQUIRED_EMAIL']);
    }

    public function test_writes_are_refused_for_placeholder_contact_data(): void
    {
        $this->expectException(MeemSeedException::class);
        $this->expectExceptionMessage('banned value');

        $this->realManifest()->assertWritable('settings', ['site_email' => 'info@example.com']);
    }

    public function test_a_clean_payload_passes_the_write_guard(): void
    {
        $this->realManifest()->assertWritable('settings', [
            'site_name' => ['ar' => 'ميم ماركت', 'en' => 'Meem Market'],
            'site_email' => 'care@meem.sa',
        ]);

        $this->addToAssertionCount(1);
    }
}
