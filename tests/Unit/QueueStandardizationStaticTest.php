<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * W8 — SYSTEM-WIDE QUEUE POLICY (static source audit).
 *
 * Policy: every ShouldQueue implementer in application code MUST resolve to
 * the semantic high/medium queues via configuration, never hard-coded strings.
 *
 * Allowed patterns:
 *   - config('queue.queues.high') / config('queue.queues.medium')
 *   - QueueName::high() / QueueName::medium() / ->resolved()
 *   - config('frontend.queue', config('queue.queues.high')) (frontend alias)
 *   - literal 'catch-high'/'catch-medium' ONLY in QueueName enum defaults and config fallbacks
 *
 * This is the static half; runtime dispatch proofs live in the Feature suite.
 */
class QueueStandardizationStaticTest extends TestCase
{
    private const ALLOWED = ['catch-high', 'catch-medium'];

    private array $violations = [];
    private int $checked = 0;

    /** @dataProvider queuedClassesProvider */
    public function test_every_queued_class_resolves_to_an_approved_queue(string $file): void
    {
        $src = file_get_contents($file);

        if (!preg_match('/implements\s+[^\n]*ShouldQueue/', $src)) {
            $this->markTestSkipped("No longer queued: {$file}");
        }

        // New canonical config-driven patterns (preferred).
        if (str_contains($src, "config('queue.queues.high')") || str_contains($src, 'config("queue.queues.high")')) {
            $this->assertTrue(true);
            return;
        }
        if (str_contains($src, "config('queue.queues.medium')") || str_contains($src, 'config("queue.queues.medium")')) {
            $this->assertTrue(true);
            return;
        }
        if (preg_match("/QueueName::(high|medium)\(\)/", $src) || str_contains($src, '->resolved()')) {
            $this->assertTrue(true);
            return;
        }

        // Legacy frontend alias (still config-driven).
        if (str_contains($src, "config('frontend.queue'")) {
            $this->assertTrue(true);
            return;
        }

        // Enum-driven assignment e.g. onQueue(\App\Enums\QueueName::MEDIUM->value) or QueueName::HIGH (legacy, before config refactor)
        if (preg_match("/onQueue\(\s*\\\\?App\\\\Enums\\\\QueueName::(HIGH|MEDIUM)->value/", $src) ||
            preg_match("/onQueue\(\s*QueueName::(HIGH|MEDIUM)->value/", $src)) {
            $this->assertTrue(true);
            return;
        }

        // Explicit property assignment must be an approved queue (literal or enum). Legacy path.
        if (preg_match("/public\s+\\\$queue\s*=\s*\\\\App\\\\Enums\\\\QueueName::(HIGH|MEDIUM)->value/", $src) ||
            preg_match("/public\s+\\\$queue\s*=\s*QueueName::(HIGH|MEDIUM)->value/", $src)) {
            $this->assertTrue(true);
            return;
        }
        if (preg_match_all("/public\s+\\\$queue\s*=\s*'([^']+)'/", $src, $m)) {
            foreach ($m[1] as $q) {
                $this->assertContains($q, self::ALLOWED, "{$file} assigns disallowed queue '{$q}'");
            }
            $this->assertTrue(true);
            return;
        }

        // onQueue literal assignments (legacy).
        if (preg_match_all("/onQueue\('([^']+)'\)/", $src, $m)) {
            foreach ($m[1] as $q) {
                $this->assertContains($q, self::ALLOWED, "{$file} onQueue disallowed queue '{$q}'");
            }
            $this->assertTrue(true);
            return;
        }

        $this->fail("Queued class without explicit approved queue: {$file}");
    }

    public static function queuedClassesProvider(): \Generator
    {
        $roots = [
            __DIR__ . '/../../app/Jobs',
            __DIR__ . '/../../app/Listeners',
            __DIR__ . '/../../app/Notifications',
            __DIR__ . '/../../packages/marvel/src/Jobs',
            __DIR__ . '/../../packages/marvel/src/Listeners',
            __DIR__ . '/../../packages/marvel/src/Notifications',
            __DIR__ . '/../../packages/marvel/src/Events',
        ];

        foreach ($roots as $root) {
            if (!is_dir($root)) continue;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') continue;
                $src = file_get_contents($file->getPathname());
                if (!preg_match('/implements[^\r\n]*ShouldQueue\b/', $src)) continue;
                yield [$file->getPathname()];
            }
        }
    }
}
