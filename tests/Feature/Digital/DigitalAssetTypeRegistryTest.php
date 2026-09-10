<?php

namespace Tests\Feature\Digital;

use App\Enums\DigitalAssetCategory;
use App\Enums\DigitalAssetType;
use App\Services\Digital\AssetTypeRegistry;
use Tests\TestCase;

/**
 * Workstream 2 — Asset Type Registry contract tests.
 *
 * These validate REGISTRY METADATA ONLY. No upload pipeline for non-PDF
 * assets exists yet; several assertions deliberately prove the registry
 * KNOWS about future categories while the ACTIVE surface stays PDF-only.
 */
class DigitalAssetTypeRegistryTest extends TestCase
{
    private AssetTypeRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = app(AssetTypeRegistry::class);
    }

    /* ------------------------------------------------------------------
     | Types & categories exist
     * ----------------------------------------------------------------- */

    public function test_all_four_asset_types_are_registered()
    {
        $this->assertEqualsCanonicalizing(
            [DigitalAssetType::FILE->value, DigitalAssetType::URL->value,
             DigitalAssetType::LICENSE->value, DigitalAssetType::ACCESS->value],
            $this->registry->types()
        );
    }

    public function test_file_type_exists_and_resolves()
    {
        $this->assertSame(DigitalAssetType::FILE, $this->registry->resolveType('FILE'));
    }

    public function test_url_type_exists_and_resolves()
    {
        $this->assertSame(DigitalAssetType::URL, $this->registry->resolveType('URL'));
    }

    public function test_license_type_exists_and_resolves()
    {
        $this->assertSame(DigitalAssetType::LICENSE, $this->registry->resolveType('LICENSE'));
    }

    public function test_access_type_exists_and_resolves()
    {
        $this->assertSame(DigitalAssetType::ACCESS, $this->registry->resolveType('ACCESS'));
    }

    public function test_document_category_is_declared_under_file()
    {
        $this->assertContains(DigitalAssetCategory::DOCUMENT->value, $this->registry->categories(DigitalAssetType::FILE));
    }

    public function test_image_category_is_declared_under_file()
    {
        $this->assertContains(DigitalAssetCategory::IMAGE->value, $this->registry->categories(DigitalAssetType::FILE));
    }

    public function test_audio_category_is_declared_under_file()
    {
        $this->assertContains(DigitalAssetCategory::AUDIO->value, $this->registry->categories(DigitalAssetType::FILE));
    }

    public function test_video_category_is_declared_under_file()
    {
        $this->assertContains(DigitalAssetCategory::VIDEO->value, $this->registry->categories(DigitalAssetType::FILE));
    }

    public function test_archive_category_is_declared_under_file()
    {
        $this->assertContains(DigitalAssetCategory::ARCHIVE->value, $this->registry->categories(DigitalAssetType::FILE));
    }

    public function test_software_category_is_declared_under_file()
    {
        $this->assertContains(DigitalAssetCategory::SOFTWARE->value, $this->registry->categories(DigitalAssetType::FILE));
    }

    /**
     * TEXT was intentionally folded into DOCUMENT (TXT/RTF/ODT per the
     * original feature spec). Guarded so it is not reintroduced silently.
     */
    public function test_text_category_is_intentionally_not_declared_but_text_extensions_belong_to_documents()
    {
        $this->assertNotContains('TEXT', $this->registry->categories(DigitalAssetType::FILE));
        $this->assertSame(
            DigitalAssetCategory::DOCUMENT,
            $this->registry->resolveDeclaredCategory('txt')
        );
        $this->assertSame(
            DigitalAssetCategory::DOCUMENT,
            $this->registry->resolveDeclaredCategory('rtf')
        );
    }

    /* ------------------------------------------------------------------
     | PDF resolution (current active surface)
     * ----------------------------------------------------------------- */

    public function test_pdf_resolves_to_document_category()
    {
        $this->assertSame(DigitalAssetCategory::DOCUMENT, $this->registry->resolveCategory('pdf'));
    }

    public function test_pdf_extension_is_recognized_as_uploadable()
    {
        $this->assertTrue($this->registry->supportsExtension('pdf'));
    }

    public function test_valid_pdf_mimes_are_recognized()
    {
        $this->assertTrue($this->registry->supportsMime('application/pdf'));
        $this->assertTrue($this->registry->supportsMime('application/x-pdf'));
    }

    public function test_invalid_mime_is_rejected_by_registry()
    {
        $this->assertFalse($this->registry->supportsMime('text/html'));
        $this->assertFalse($this->registry->supportsMime('application/x-php'));
    }

    public function test_unsupported_extension_is_rejected_from_active_surface()
    {
        // W7 activated AUDIO/VIDEO; executables and code stay rejected.
        $this->assertFalse($this->registry->supportsExtension('exe'));
        $this->assertFalse($this->registry->supportsExtension('php'));
        $this->assertTrue($this->registry->supportsExtension('mp4'));
        $this->assertTrue($this->registry->supportsExtension('mp3'));
    }

    public function test_mime_hint_resolves_category_when_extension_unknown()
    {
        $this->assertSame(
            DigitalAssetCategory::DOCUMENT,
            $this->registry->resolveCategory('weird-ext', 'application/pdf')
        );
    }

    /* ------------------------------------------------------------------
     | Size limits
     * ----------------------------------------------------------------- */

    public function test_max_size_exposes_global_config_value()
    {
        config(['digital.max_upload_kb' => 1234]);

        $this->assertSame(1234, $this->registry->activeMaxKb());
        $this->assertSame(1234, $this->registry->activeMaxKb(DigitalAssetCategory::DOCUMENT->value));
    }

    public function test_category_level_size_override_wins_over_global()
    {
        config(['digital.max_upload_kb' => 20480]);
        config(['digital.asset_types.FILE.categories.DOCUMENT.max_kb' => 512]);

        $this->assertSame(512, $this->registry->activeMaxKb(DigitalAssetCategory::DOCUMENT->value));
        $this->assertSame(20480, $this->registry->activeMaxKb(DigitalAssetCategory::VIDEO->value));
    }

    /* ------------------------------------------------------------------
     | Capability flags
     * ----------------------------------------------------------------- */

    public function test_downloadable_flag()
    {
        $this->assertTrue($this->registry->isDownloadable(DigitalAssetType::FILE));
        $this->assertFalse($this->registry->isDownloadable(DigitalAssetType::URL));
        $this->assertFalse($this->registry->isDownloadable(DigitalAssetType::LICENSE));
        $this->assertFalse($this->registry->isDownloadable(DigitalAssetType::ACCESS));
    }

    public function test_streamable_flags_after_w7_activation()
    {
        // A3 activated: only AUDIO and VIDEO stream; everything else must not.
        $this->assertTrue($this->registry->isStreamable(DigitalAssetCategory::AUDIO));
        $this->assertTrue($this->registry->isStreamable(DigitalAssetCategory::VIDEO));

        foreach ([
            DigitalAssetCategory::DOCUMENT,
            DigitalAssetCategory::SPREADSHEET,
            DigitalAssetCategory::PRESENTATION,
            DigitalAssetCategory::ARCHIVE,
            DigitalAssetCategory::IMAGE,
            DigitalAssetCategory::SOFTWARE,
        ] as $category) {
            $this->assertFalse($this->registry->isStreamable($category), "{$category->value} must not stream");
        }

        // Metadata remains responsive to configuration.
        config(['digital.asset_types.FILE.categories.VIDEO.streamable' => false]);
        $this->assertFalse($this->registry->isStreamable('VIDEO'));
    }

    public function test_previewable_flag()
    {
        $this->assertTrue($this->registry->isPreviewable(DigitalAssetCategory::DOCUMENT));
        $this->assertTrue($this->registry->isPreviewable(DigitalAssetCategory::IMAGE));
        $this->assertTrue($this->registry->isPreviewable(DigitalAssetCategory::AUDIO), 'W7: media preview inline');
        $this->assertTrue($this->registry->isPreviewable(DigitalAssetCategory::VIDEO), 'W7: media preview inline');
        $this->assertFalse($this->registry->isPreviewable(DigitalAssetCategory::ARCHIVE));
    }

    public function test_external_url_capability_is_url_type_only()
    {
        $this->assertTrue($this->registry->allowsExternalUrl(DigitalAssetType::URL));
        $this->assertFalse($this->registry->allowsExternalUrl(DigitalAssetType::FILE));
        $this->assertFalse($this->registry->allowsExternalUrl(DigitalAssetType::LICENSE));
        $this->assertFalse($this->registry->allowsExternalUrl(DigitalAssetType::ACCESS));
    }

    public function test_checksum_requirement()
    {
        $this->assertTrue($this->registry->requiresChecksum(DigitalAssetType::FILE));
        $this->assertTrue($this->registry->requiresChecksum(DigitalAssetType::FILE, 'DOCUMENT'));
        $this->assertFalse($this->registry->requiresChecksum(DigitalAssetType::URL));
        $this->assertFalse($this->registry->requiresChecksum(DigitalAssetType::LICENSE));

        // Category-level override wins over the type default.
        config(['digital.asset_types.FILE.categories.ARCHIVE.checksum_required' => false]);
        $this->assertFalse($this->registry->requiresChecksum(DigitalAssetType::FILE, 'ARCHIVE'));
    }

    public function test_secret_requirement()
    {
        $this->assertTrue($this->registry->requiresSecret(DigitalAssetType::LICENSE));
        $this->assertTrue($this->registry->requiresSecret(DigitalAssetType::ACCESS));
        $this->assertFalse($this->registry->requiresSecret(DigitalAssetType::FILE));
        $this->assertFalse($this->registry->requiresSecret(DigitalAssetType::URL));
    }

    /* ------------------------------------------------------------------
     | Software gate (decision A1)
     * ----------------------------------------------------------------- */

    public function test_software_category_is_disabled_by_default()
    {
        $this->assertFalse(config('digital.allow_software_assets'));
        $this->assertFalse($this->registry->isEnabled(DigitalAssetType::FILE, 'SOFTWARE'));
        $this->assertNull($this->registry->resolveDeclaredCategory('exe'));
        $this->assertNull($this->registry->resolveCategory('exe'));
    }

    public function test_software_becomes_recognized_when_configuration_enables_it()
    {
        config(['digital.allow_software_assets' => true]);

        $this->assertTrue($this->registry->isEnabled(DigitalAssetType::FILE, 'SOFTWARE'));
        $this->assertSame(DigitalAssetCategory::SOFTWARE, $this->registry->resolveDeclaredCategory('apk'));

        // Recognition ≠ uploadability: SOFTWARE has an EMPTY active surface,
        // so the current pipeline still rejects executables (no upload
        // pipeline exists until Workstream 4).
        $this->assertFalse($this->registry->supportsExtension('exe'));
        $this->assertNotContains('SOFTWARE', array_keys($this->registry->uploadableCategories()));
    }

    /* ------------------------------------------------------------------
     | Future types stay inert (decisions A2/A3/A4 scope guard)
     * ----------------------------------------------------------------- */

    public function test_w5_activates_url_license_access_as_creatable_but_never_uploadable()
    {
        // W5 contract: all four types are creatable; only FILE has a file
        // upload surface, and non-FILE types remain undeliverable as files.
        $this->assertEqualsCanonicalizing(
            [DigitalAssetType::FILE->value, DigitalAssetType::URL->value,
             DigitalAssetType::LICENSE->value, DigitalAssetType::ACCESS->value],
            $this->registry->creatableTypes()
        );

        foreach ([DigitalAssetType::URL, DigitalAssetType::LICENSE, DigitalAssetType::ACCESS] as $type) {
            $this->assertTrue($this->registry->isEnabled($type), "{$type->value} must be recognized");
            $this->assertFalse($this->registry->supportsExtension(strtolower($type->value)), "{$type->value} must not become a file extension");
            $this->assertSame([], $this->registry->categories($type));
            $this->assertFalse($this->registry->isDownloadable($type) && $type !== DigitalAssetType::FILE);
        }
    }

    public function test_disabling_the_file_surface_removes_only_file_from_creatable_types()
    {
        config(['digital.asset_types.FILE.enabled' => false]);

        // Non-file delivery types are independent of the FILE upload surface.
        $this->assertEqualsCanonicalizing(
            [DigitalAssetType::URL->value, DigitalAssetType::LICENSE->value, DigitalAssetType::ACCESS->value],
            $this->registry->creatableTypes()
        );
    }

    /* ------------------------------------------------------------------
     | Active-surface composition
     * ----------------------------------------------------------------- */

    public function test_active_surface_is_document_plus_activated_media()
    {
        // W7/A3: DOCUMENT(pdf) remains, AUDIO and VIDEO are now uploadable.
        $this->assertEqualsCanonicalizing(
            ['pdf', 'mp3', 'wav', 'm4a', 'aac', 'ogg', 'flac', 'mp4', 'webm', 'mov', 'mkv'],
            $this->registry->activeExtensions()
        );
        $this->assertEqualsCanonicalizing(
            ['DOCUMENT', 'AUDIO', 'VIDEO'],
            array_keys($this->registry->uploadableCategories())
        );
    }

    public function test_deprecated_legacy_mimes_keys_no_longer_influence_validation()
    {
        // Proves the decoupling: mutating the deprecated keys cannot widen
        // or narrow the active surface anymore.
        config(['digital.allowed_mimes' => ['png', 'exe']]);
        config(['digital.allowed_mime_types' => ['image/png']]);

        $this->assertFalse($this->registry->supportsExtension('png'));
        $this->assertFalse($this->registry->supportsExtension('exe'));
        $this->assertFalse($this->registry->supportsMime('image/png'));
        $this->assertTrue($this->registry->supportsExtension('pdf'), 'registry-driven surface unchanged');
    }

    /* ------------------------------------------------------------------
     | Environment / configuration contract (DIG-008)
     * ----------------------------------------------------------------- */

    public function test_env_example_declares_every_digital_variable_consumed_by_config()
    {
        $envExample = file_get_contents(base_path('.env.example'));

        $expected = [
            'DIGITAL_PRODUCTS_ENABLED' => 'true',
            'DIGITAL_MAX_UPLOAD_KB' => '20480',
            'DIGITAL_DOWNLOAD_LIMIT' => '5',
            'DIGITAL_SIGNED_URL_TTL' => '30',
            'DIGITAL_ALLOW_SOFTWARE_ASSETS' => 'false',
        ];

        foreach ($expected as $variable => $safeDefault) {
            $this->assertMatchesRegularExpression(
                '/^' . preg_quote($variable, '/') . '=' . preg_quote($safeDefault, '/') . '\s*$/m',
                $envExample,
                "{$variable}={$safeDefault} must exist in .env.example with its safe default"
            );
        }
    }

    public function test_software_gate_defaults_to_false_and_honors_override()
    {
        $this->assertFalse((bool) config('digital.allow_software_assets'));

        config(['digital.allow_software_assets' => true]);
        $this->assertTrue(app(AssetTypeRegistry::class)->isEnabled(DigitalAssetType::FILE, 'SOFTWARE'));

        config(['digital.allow_software_assets' => false]);
        $this->assertFalse(app(AssetTypeRegistry::class)->isEnabled(DigitalAssetType::FILE, 'SOFTWARE'));
    }
}
