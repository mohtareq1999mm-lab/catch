<?php

declare(strict_types=1);

namespace Database\Seeders\Meem\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Run-scoped state for a Meem seeding batch.
 *
 * Owns three things and nothing else:
 *  - the resolved profile / dry-run / force / batch id;
 *  - the production guard;
 *  - every database write, funnelled through transaction().
 *
 * Every model write in a Meem seeder must happen inside transaction().
 * There is deliberately no other write helper.
 */
final class MeemContext
{
    public const PROFILE_LOCAL = 'local';
    public const PROFILE_STAGING = 'staging';
    public const PROFILE_PRODUCTION = 'production';

    /** @var list<string> */
    private array $lines = [];

    public function __construct(
        private readonly string $profile,
        private readonly bool $dryRun,
        private readonly bool $force,
        private readonly ?string $batchId,
        private readonly ?Closure $writer = null,
    ) {
    }

    public static function fromEnvironment(
        bool $apply = false,
        bool $force = false,
        ?Closure $writer = null,
        ?string $profile = null,
    ): self {
        return new self(
            profile: self::normaliseProfile($profile ?? (self::envValue('SEEDING_PROFILE') ?? app()->environment())),
            dryRun: ! $apply,
            force: $force,
            batchId: self::envValue('SEEDING_BATCH_ID'),
            writer: $writer,
        );
    }

    /**
     * Read a process environment variable without depending on `env()`.
     *
     * `env()` returns null for variables that are not present in a cached
     * config bootstrap, which would silently disable the production guard.
     */
    public static function envValue(string $key): ?string
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public function profile(): string
    {
        return $this->profile;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function isProduction(): bool
    {
        return $this->profile === self::PROFILE_PRODUCTION;
    }

    public function batchId(): ?string
    {
        return $this->batchId;
    }

    /**
     * Production guard: a real write in production requires both an explicit
     * batch id and --force. Dry runs are always allowed (they write nothing).
     */
    public function assertRunnable(): void
    {
        if ($this->isProduction() && ! $this->dryRun) {
            if ($this->batchId === null) {
                throw new MeemSeedException(
                    'Refusing to seed production without SEEDING_BATCH_ID. '
                    . 'Set SEEDING_BATCH_ID=<batch> and pass --force.'
                );
            }

            if (! $this->force) {
                throw new MeemSeedException(
                    'Refusing to seed production without --force. '
                    . "Batch [{$this->batchId}] was not confirmed."
                );
            }
        }
    }

    /**
     * The only way a Meem seeder may write.
     *
     * @template T
     * @param Closure():T $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed
    {
        if ($this->dryRun) {
            throw new MeemSeedException('transaction() called during a dry run: nothing may be written.');
        }

        return DB::transaction($callback);
    }

    // -----------------------------------------------------------------
    // Reporting
    // -----------------------------------------------------------------

    public function line(string $line = ''): void
    {
        $this->lines[] = $line;

        if ($this->writer !== null) {
            ($this->writer)($line);
        }
    }

    /**
     * @param array<string, string> $missing
     */
    public function skip(string $dataset, string $state, ?string $gate, array $missing): void
    {
        $reason = match ($state) {
            MeemManifest::SKIP_PROFILE => 'dataset not enabled for profile [' . $this->profile . ']',
            MeemManifest::SKIP_GATE => 'unsigned governance gate [' . ($gate ?? 'n/a') . ']',
            MeemManifest::SKIP_PLACEHOLDER => 'unresolved values',
            default => $state,
        };

        $this->line("  [SKIP] {$dataset} — {$reason}");

        foreach ($missing as $pointer => $token) {
            $this->line("         missing: {$pointer} = {$token}");
        }
    }

    public function noop(string $dataset): void
    {
        $this->line("  [OK]   {$dataset} — already current (no changes)");
    }

    /** @param list<array{key: string, changes: array<string, array{from: mixed, to: mixed}>}> $plan */
    public function planned(string $dataset, array $plan): void
    {
        $this->line("  [PLAN] {$dataset} — " . count($plan) . ' record(s) would change (dry run, nothing written)');

        foreach ($plan as $entry) {
            $fields = implode(', ', array_keys($entry['changes']));
            $this->line("         • {$entry['key']}: {$fields}");
        }
    }

    /** @param list<array{key: string, changes: array<string, array{from: mixed, to: mixed}>}> $plan */
    public function applied(string $dataset, array $plan): void
    {
        $this->line("  [DONE] {$dataset} — " . count($plan) . ' record(s) written in one transaction');

        foreach ($plan as $entry) {
            $this->line("         • {$entry['key']} (" . implode(', ', array_keys($entry['changes'])) . ')');
        }
    }

    /** @return list<string> */
    public function recordedLines(): array
    {
        return $this->lines;
    }

    private static function normaliseProfile(string $profile): string
    {
        return match (strtolower(trim($profile))) {
            'production', 'prod', 'live' => self::PROFILE_PRODUCTION,
            'staging', 'stage', 'preprod' => self::PROFILE_STAGING,
            default => self::PROFILE_LOCAL,
        };
    }

    /**
     * Convenience for seeders that need a before/after snapshot of one value.
     *
     * @return array{from: mixed, to: mixed}
     */
    public static function change(mixed $from, mixed $to): array
    {
        return ['from' => $from, 'to' => $to];
    }

    /**
     * Loose comparison that treats "50" == "50.00" == 50 and false == 0,
     * so idempotency is not defeated by cast/scale noise.
     */
    public static function differs(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            if (! is_array($a) || ! is_array($b)) {
                return true;
            }

            return self::normaliseArray($a) !== self::normaliseArray($b);
        }

        if (is_bool($a) || is_bool($b)) {
            return (bool) $a !== (bool) $b;
        }

        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a !== (float) $b;
        }

        return (string) $a !== (string) $b;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function normaliseArray(array $value): array
    {
        ksort($value);

        return array_map(
            static fn (mixed $item): mixed => is_array($item) ? self::normaliseArray($item) : $item,
            $value,
        );
    }

    /**
     * Re-throw with dataset context so a failure names the dataset, while the
     * original exception stays chained for the log.
     *
     * @template T
     * @param Closure():T $callback
     * @return T
     */
    public function guard(string $dataset, Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            if ($e instanceof MeemSeedException) {
                throw $e;
            }

            throw new MeemSeedException("Dataset [{$dataset}] failed: {$e->getMessage()}", 0, $e);
        }
    }
}
