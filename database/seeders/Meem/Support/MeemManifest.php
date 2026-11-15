<?php

declare(strict_types=1);

namespace Database\Seeders\Meem\Support;

use JsonException;

/**
 * Read-only view over the single Meem seeding manifest.
 *
 * Responsibilities:
 *  - load and structurally validate the JSON document;
 *  - answer "may this dataset run?" (profile allow-list);
 *  - answer "is its governance gate signed?";
 *  - find unresolved REQUIRED_* / <template> tokens inside a dataset.
 *
 * It never writes, never touches the database and never reads .env values.
 */
final class MeemManifest
{
    public const READY = 'READY';
    public const SKIP_PROFILE = 'SKIPPED_PROFILE';
    public const SKIP_GATE = 'SKIPPED_GATE';
    public const SKIP_PLACEHOLDER = 'SKIPPED_PLACEHOLDER';

    public const SUPPORTED_VERSION = '1.0.0';

    /**
     * Seeder-facing dataset name => top-level manifest key.
     *
     * The names are the contract used by the orchestrator and by every
     * dataset seeder; the manifest keys are what a human edits.
     */
    public const DATASETS = [
        'settings' => 'settings',
        'currency' => 'currency',
        'geography' => 'geography',
        'warehouse' => 'warehouse',
        'categories' => 'navigation_roots',
        'brands' => 'brands',
        'products' => 'products',
        'bulk' => 'bulk_merchandising',
        'banners' => 'banners',
        'flash_sales' => 'flash_sales',
        'coupons' => 'coupons',
        'payment' => 'payment',
        'sections' => 'homepage_sections',
    ];

    /** Values that must never reach a seeded record (plan guardrails 3, 4, 5, 7, 8). */
    public const BANNED_VALUES = [
        'info@example.com',
        'support@example.com',
        '+2011111111111',
        'catch.mohammedtareq.me',
        '/hero/catch-',
        'HELLOIBA',
    ];

    /** @param array<string, mixed> $data */
    private function __construct(
        private readonly array $data,
        private readonly string $source,
    ) {
    }

    public static function fromFile(?string $path = null): self
    {
        $path ??= dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR . 'homepage.seed.json';

        if (! is_file($path)) {
            throw new MeemSeedException("Meem manifest not found at [{$path}].");
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new MeemSeedException("Meem manifest at [{$path}] is not readable.");
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MeemSeedException("Meem manifest at [{$path}] is not valid JSON: {$e->getMessage()}");
        }

        $manifest = new self($decoded, $path);
        $manifest->assertStructurallyValid();

        return $manifest;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $source = 'in-memory'): self
    {
        $manifest = new self($data, $source);
        $manifest->assertStructurallyValid();

        return $manifest;
    }

    public function source(): string
    {
        return $this->source;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    /** @return list<string> */
    public function datasetNames(): array
    {
        return array_keys(self::DATASETS);
    }

    public function jsonPath(string $dataset): string
    {
        if (! isset(self::DATASETS[$dataset])) {
            throw new MeemSeedException("Unknown Meem dataset [{$dataset}].");
        }

        return self::DATASETS[$dataset];
    }

    public function has(string $dataset): bool
    {
        return isset(self::DATASETS[$dataset]) && array_key_exists(self::DATASETS[$dataset], $this->data);
    }

    /**
     * Dot-notation read. Arrays are returned as arrays.
     *
     * @return mixed
     */
    public function get(string $dot, mixed $default = null): mixed
    {
        $node = $this->data;

        foreach (explode('.', $dot) as $segment) {
            if (is_array($node) && array_key_exists($segment, $node)) {
                $node = $node[$segment];

                continue;
            }

            return $default;
        }

        return $node;
    }

    /** @return array<string, mixed> */
    public function dataset(string $dataset): array
    {
        $value = $this->get($this->jsonPath($dataset), []);

        return is_array($value) ? $value : [];
    }

    public function gateOf(string $dataset): ?string
    {
        $gate = $this->get($this->jsonPath($dataset) . '.gate');

        return is_string($gate) && $gate !== '' ? $gate : null;
    }

    public function gateSigned(?string $gate): bool
    {
        if ($gate === null) {
            return true;
        }

        return $this->get('meta.governance.' . $gate, false) === true;
    }

    /**
     * Can the active profile run this dataset?
     *
     * Two layers, both honoured:
     *  1. the dataset's own `profiles` list (e.g. geography, warehouse);
     *  2. the profile's `datasets` allow-list ("all", "all_real", or a list).
     */
    public function profileAllows(string $dataset, string $profile): bool
    {
        $local = $this->get($this->jsonPath($dataset) . '.profiles');

        if (is_array($local) && $local !== [] && ! in_array($profile, $local, true)) {
            return false;
        }

        $allowed = $this->get("profiles.{$profile}.datasets");

        if ($allowed === 'all' || $allowed === 'all_real') {
            return true;
        }

        if (is_array($allowed)) {
            return in_array($dataset, $allowed, true);
        }

        return false;
    }

    /**
     * Unresolved tokens under one dataset.
     *
     * Only content subtrees are scanned. Instruction keys (`gate`, `rules`,
     * `match_by`, `endpoint_template`, …) legitimately contain template syntax
     * and are skipped, otherwise the manifest would flag itself.
     *
     * @return array<string, string> JSON pointer => offending token
     */
    public function placeholdersIn(string $dataset): array
    {
        return $this->placeholdersInPath($this->jsonPath($dataset));
    }

    /** @return array<string, string> */
    public function placeholdersInPath(string $dotPath): array
    {
        $node = $this->get($dotPath);

        if (! is_array($node)) {
            return [];
        }

        $out = [];
        $prefix = $this->placeholderPrefix();

        $this->walkPlaceholders($node, '/' . str_replace('.', '/', $dotPath), $prefix, $out);

        return $out;
    }

    /**
     * The token prefix that marks an unresolved value.
     *
     * `validation.reject_any_value_starting_with` is authoritative; the meta
     * value is only a fallback, so the seeder follows whatever the manifest
     * declares rather than a hardcoded constant.
     */
    public function placeholderPrefix(): string
    {
        $declared = $this->get('validation.reject_any_value_starting_with');

        if (is_string($declared) && $declared !== '') {
            return $declared;
        }

        $meta = $this->get('meta.placeholder_prefix');

        return is_string($meta) && $meta !== '' ? $meta : 'REQUIRED_';
    }

    /**
     * Dataset decision for the active profile.
     *
     * Gate is evaluated before placeholders: an unsigned decision blocks the
     * dataset even when every value is already supplied.
     *
     * @return array{state: string, gate: ?string, gate_signed: bool, missing: array<string, string>}
     */
    public function state(string $dataset, string $profile): array
    {
        $gate = $this->gateOf($dataset);
        $gateSigned = $this->gateSigned($gate);
        $missing = $this->placeholdersIn($dataset);

        $state = match (true) {
            ! $this->profileAllows($dataset, $profile) => self::SKIP_PROFILE,
            ! $gateSigned => self::SKIP_GATE,
            $missing !== [] => self::SKIP_PLACEHOLDER,
            default => self::READY,
        };

        return [
            'state' => $state,
            'gate' => $gate,
            'gate_signed' => $gateSigned,
            'missing' => $missing,
        ];
    }

    /**
     * Assert that a payload about to be written contains no unresolved token
     * and no banned value. Defence in depth for a seeder that forgets a check.
     *
     * @param array<string, mixed> $payload
     */
    public function assertWritable(string $dataset, array $payload): void
    {
        $prefix = $this->placeholderPrefix();
        $found = [];
        $this->walkPlaceholders($payload, $dataset, $prefix, $found);

        if ($found !== []) {
            $list = implode(', ', array_map(
                static fn (string $pointer, string $token): string => "{$pointer}={$token}",
                array_keys($found),
                array_values($found),
            ));

            throw new MeemSeedException("Refusing to write dataset [{$dataset}]: unresolved values ({$list}).");
        }

        foreach ($this->flattenStrings($payload) as $pointer => $value) {
            foreach (self::BANNED_VALUES as $banned) {
                if (stripos($value, $banned) !== false) {
                    throw new MeemSeedException(
                        "Refusing to write dataset [{$dataset}]: banned value [{$banned}] at [{$pointer}]."
                    );
                }
            }
        }
    }

    /**
     * @param array<mixed>          $node
     * @param array<string, string> $out
     */
    private function walkPlaceholders(array $node, string $pointer, string $prefix, array &$out): void
    {
        foreach ($node as $key => $value) {
            $child = $pointer . '/' . $key;

            if ($this->isInstructionKey((string) $key)) {
                continue;
            }

            if (is_array($value)) {
                $this->walkPlaceholders($value, $child, $prefix, $out);

                continue;
            }

            if (is_string($value) && $this->looksUnresolved($value, $prefix)) {
                $out[$child] = trim($value);
            }
        }
    }

    /**
     * Keys that describe *how* to seed rather than *what* to seed. Their values
     * may legitimately contain REQUIRED_/&lt;template&gt; syntax.
     */
    private function isInstructionKey(string $key): bool
    {
        static $keys = [
            'gate',
            'rules',
            'meta',
            'validation',
            'profiles',
            'endpoint_template',
            'match_by',
            'match',
            'keep_existing',
            'require_complete_pack_data',
            'children_match_name_en',
            'focus_categories_name_en',
            'forbidden_slugs',
            'forbidden_strings_in_seeded_content',
            'reconcile_note',
            'action',
            'placeholder_prefix',
            'reject_any_value_starting_with',
            'source_audit',
        ];

        return in_array($key, $keys, true);
    }

    private function looksUnresolved(string $value, string $prefix): bool
    {
        $value = trim($value);

        if ($value === '') {
            return false;
        }

        if ($prefix !== '' && str_starts_with($value, $prefix)) {
            return true;
        }

        // <resolved_live_sale>, <copied_verbatim_from_sale_end_date_with_timezone>, …
        return (bool) preg_match('/<[^<>]+>/', $value);
    }

    /**
     * @param array<mixed> $node
     * @return array<string, string>
     */
    private function flattenStrings(array $node, string $pointer = ''): array
    {
        $out = [];

        foreach ($node as $key => $value) {
            $child = $pointer . '/' . $key;

            if (is_array($value)) {
                $out += $this->flattenStrings($value, $child);

                continue;
            }

            if (is_string($value)) {
                $out[$child] = $value;
            }
        }

        return $out;
    }

    private function assertStructurallyValid(): void
    {
        $problems = [];

        $version = $this->get('meta.version');

        if (! is_string($version) || $version === '') {
            $problems[] = 'meta.version is missing.';
        } elseif ($version !== self::SUPPORTED_VERSION) {
            $problems[] = "meta.version [{$version}] is not supported (expected " . self::SUPPORTED_VERSION . ').';
        }

        if (! is_array($this->get('meta.governance'))) {
            $problems[] = 'meta.governance must be an object.';
        }

        $locales = $this->get('meta.locale_order');

        if (! is_array($locales) || ! in_array('ar', $locales, true) || ! in_array('en', $locales, true)) {
            $problems[] = 'meta.locale_order must contain both "ar" and "en".';
        }

        if (! is_array($this->get('profiles'))) {
            $problems[] = 'profiles must be an object.';
        }

        foreach (self::DATASETS as $dataset => $key) {
            if (! array_key_exists($key, $this->data)) {
                $problems[] = "dataset [{$dataset}] is missing (expected top-level key [{$key}]).";
            }
        }

        if ($problems !== []) {
            throw new MeemSeedException(
                "Meem manifest [{$this->source}] is invalid:\n - " . implode("\n - ", $problems)
            );
        }
    }
}
