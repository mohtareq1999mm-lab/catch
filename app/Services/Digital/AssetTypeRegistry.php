<?php

namespace App\Services\Digital;

use App\Enums\DigitalAssetCategory;
use App\Enums\DigitalAssetType;
use Illuminate\Support\Facades\Config;

/**
 * Canonical Digital Asset Type Registry.
 *
 * The ONLY authoritative source for asset validation metadata and delivery
 * capabilities. FormRequests, DigitalAssetService, the future delivery
 * resolver and license service must consume this class instead of reading
 * scattered rules from controllers or hardcoding extension/MIME lists.
 *
 * Backed by config('digital.asset_types'). Two layers exist per FILE
 * category:
 *  - declared surface  (`extensions` / `mime_types`): the approved target
 *    taxonomy the registry KNOWS about;
 *  - active surface    (`active_extensions` / `active_mime_types`): what
 *    the CURRENT upload pipeline accepts. Only DOCUMENT(pdf) is active
 *    until Workstream 4 ships server-side content sniffing.
 *
 * Declaring a category does NOT enable it (decision A1): SOFTWARE requires
 * digital.allow_software_assets AND a non-empty active surface; URL/
 * LICENSE/ACCESS are metadata-only until their workstreams land (A2/A3).
 */
class AssetTypeRegistry
{
    /** @return string[] all registered asset type values */
    public function types(): array
    {
        return array_keys((array) Config::get('digital.asset_types', []));
    }

    public function resolveType(string $type): ?DigitalAssetType
    {
        return collect(DigitalAssetType::cases())
            ->first(fn (DigitalAssetType $case) => $case->value === strtoupper(trim($type)));
    }

    /** @return string[] category names declared under a type */
    public function categories(DigitalAssetType|string $type): array
    {
        $case = $type instanceof DigitalAssetType ? $type : $this->resolveType($type);

        if ($case === null) {
            return [];
        }

        return array_keys((array) Config::get("digital.asset_types.{$case->value}.categories", []));
    }

    /**
     * Resolve a category from an extension (+ optional MIME hint) against
     * the ACTIVE upload surface. Returns null when unsupported/disabled.
     */
    public function resolveCategory(string $extension, ?string $mime = null): ?DigitalAssetCategory
    {
        foreach ($this->uploadableCategories() as $name => $definition) {
            if (in_array(strtolower($extension), $definition['active_extensions'], true)) {
                return DigitalAssetCategory::from($name);
            }
        }

        // Extension miss: allow an exact MIME hit within one active category.
        if ($mime !== null) {
            foreach ($this->uploadableCategories() as $name => $definition) {
                if (in_array(strtolower($mime), $definition['active_mime_types'], true)) {
                    return DigitalAssetCategory::from($name);
                }
            }
        }

        return null;
    }

    /** Category declaring an extension on its FULL target surface, gated software excluded. */
    public function resolveDeclaredCategory(string $extension): ?DigitalAssetCategory
    {
        $extension = strtolower($extension);

        foreach ($this->fileCategories() as $name => $definition) {
            if (!in_array($extension, $definition['extensions'], true)) {
                continue;
            }

            if ($name === DigitalAssetCategory::SOFTWARE->value && !$this->softwareEnabled()) {
                continue;
            }

            return DigitalAssetCategory::from($name);
        }

        return null;
    }

    /**
     * STRICT extension↔MIME agreement: both must belong to the SAME active
     * category definition. This is the upload-pipeline authorization point
     * against spoofed/mismatched uploads (e.g. .pdf name carrying ZIP bytes).
     */
    public function resolveCompatibleCategory(string $extension, string $mime): ?DigitalAssetCategory
    {
        $extension = strtolower($extension);
        $mime = strtolower($mime);

        foreach ($this->uploadableCategories() as $name => $definition) {
            if (
                in_array($extension, $definition['active_extensions'], true)
                && in_array($mime, $definition['active_mime_types'], true)
            ) {
                return DigitalAssetCategory::from($name);
            }
        }

        return null;
    }

    /** Does the CURRENT pipeline accept this extension? */
    public function supportsExtension(string $extension): bool
    {
        return $this->resolveCategory($extension) !== null;
    }

    /** Does the CURRENT pipeline accept this MIME type? */
    public function supportsMime(string $mime): bool
    {
        foreach ($this->uploadableCategories() as $definition) {
            if (in_array(strtolower($mime), $definition['active_mime_types'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array> enabled FILE categories exposing a non-empty active surface */
    public function uploadableCategories(): array
    {
        $uploadable = [];

        foreach ($this->fileCategories() as $name => $definition) {
            if (!$this->isEnabled(DigitalAssetType::FILE, $name)) {
                continue;
            }

            if (empty($definition['active_extensions']) && empty($definition['active_mime_types'])) {
                continue;
            }

            $uploadable[$name] = $definition;
        }

        return $uploadable;
    }

    /**
     * Types an admin may currently CREATE assets for: enabled types, where
     * FILE additionally requires a live upload surface. Gates the `type`
     * field on create requests so URL/LICENSE/ACCESS cannot be injected.
     *
     * @return string[]
     */
    public function creatableTypes(): array
    {
        return array_values(array_filter(DigitalAssetType::values(), function (string $value): bool {
            if (!$this->isEnabled($value)) {
                return false;
            }

            if ($value === DigitalAssetType::FILE->value && $this->uploadableCategories() === []) {
                return false;
            }

            return true;
        }));
    }

    /** Flat whitelist for the `mimes:` validation rule (active surface only). */
    public function activeExtensions(): array
    {
        $extensions = [];

        foreach ($this->uploadableCategories() as $definition) {
            $extensions = array_merge($extensions, $definition['active_extensions']);
        }

        return array_values(array_unique($extensions));
    }

    /** Flat whitelist used by server-side MIME validation (active surface only). */
    public function activeMimeTypes(): array
    {
        $mimes = [];

        foreach ($this->uploadableCategories() as $definition) {
            $mimes = array_merge($mimes, $definition['active_mime_types']);
        }

        return array_values(array_unique($mimes));
    }

    /** Effective max size in KB: category override, else the global limit. */
    public function activeMaxKb(?string $category = null): int
    {
        if ($category !== null) {
            $override = Config::get(
                'digital.asset_types.' . DigitalAssetType::FILE->value . ".categories." . strtoupper($category) . ".max_kb"
            );

            if (is_numeric($override) && (int) $override > 0) {
                return (int) $override;
            }
        }

        return (int) Config::get('digital.max_upload_kb', 0);
    }

    /**
     * Recognition status: the category/type is declared AND any feature
     * gate is open (A1 software gate). Recognition does NOT imply
     * uploadability — an enabled category still needs a non-empty active
     * surface to accept uploads (see uploadableCategories()).
     *
     * Type-level enablement, or type+category when a category is given.
     */
    public function isEnabled(DigitalAssetType|string $type, ?string $category = null): bool
    {
        $case = $type instanceof DigitalAssetType ? $type : $this->resolveType($type);

        if ($case === null || !(bool) Config::get("digital.asset_types.{$case->value}.enabled", false)) {
            return false;
        }

        if ($category === null) {
            return true;
        }

        // Categories exist only under FILE.
        if ($case !== DigitalAssetType::FILE) {
            return false;
        }

        $definition = Config::get('digital.asset_types.FILE.categories.' . strtoupper($category));

        if (!is_array($definition)) {
            return false;
        }

        if (strtoupper($category) === DigitalAssetCategory::SOFTWARE->value && !$this->softwareEnabled()) {
            return false;
        }

        return true;
    }

    public function isDownloadable(DigitalAssetType|string $type): bool
    {
        return (bool) Config::get('digital.asset_types.' . $this->typeKey($type) . '.downloadable', false);
    }

    /** Category flag wins when present; otherwise the owning type's default applies. */
    public function isStreamable(DigitalAssetCategory|string $category): bool
    {
        return $this->categoryFlag($category, 'streamable');
    }

    public function isPreviewable(DigitalAssetCategory|string $category): bool
    {
        return $this->categoryFlag($category, 'previewable');
    }

    /** Only URL-type assets are delivered by external redirect (future). */
    public function allowsExternalUrl(DigitalAssetType|string $type): bool
    {
        return (bool) Config::get('digital.asset_types.' . $this->typeKey($type) . '.url_allowed', false);
    }

    public function requiresChecksum(DigitalAssetType|string $type, ?string $category = null): bool
    {
        $base = (bool) Config::get('digital.asset_types.' . $this->typeKey($type) . '.checksum_required', false);

        if ($category === null) {
            return $base;
        }

        $override = Config::get(
            'digital.asset_types.FILE.categories.' . strtoupper($category instanceof DigitalAssetCategory ? $category->value : $category) . '.checksum_required'
        );

        return $override === null ? $base : (bool) $override;
    }

    public function requiresSecret(DigitalAssetType|string $type): bool
    {
        return (bool) Config::get('digital.asset_types.' . $this->typeKey($type) . '.requires_secret', false);
    }

    private function categoryFlag(DigitalAssetCategory|string $category, string $flag): bool
    {
        $name = strtoupper($category instanceof DigitalAssetCategory ? $category->value : $category);

        $value = Config::get("digital.asset_types.FILE.categories.{$name}.{$flag}");

        if ($value !== null) {
            return (bool) $value;
        }

        return (bool) Config::get("digital.asset_types.FILE.{$flag}", false);
    }

    private function softwareEnabled(): bool
    {
        return (bool) Config::get('digital.allow_software_assets', false);
    }

    private function typeKey(DigitalAssetType|string $type): string
    {
        $case = $type instanceof DigitalAssetType ? $type : $this->resolveType($type);

        return $case?->value ?? strtoupper($type);
    }

    private function fileCategories(): array
    {
        return (array) Config::get('digital.asset_types.' . DigitalAssetType::FILE->value . '.categories', []);
    }
}
