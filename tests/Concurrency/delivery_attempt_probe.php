<?php

declare(strict_types=1);

// #164 concurrency probe: one process = one delivery-attempt transaction.
// Invoked by DeliveryAttemptConcurrencyTest with [assignmentId, representativeId].
// Database credentials come from the inherited process-local environment;
// no .env file is read.

use App\Models\Shipping\ShipmentAssignment;
use App\Services\Shipping\DeliveryAttemptService;
use Illuminate\Contracts\Console\Kernel;

$repoRoot = dirname(__DIR__, 2);

require $repoRoot . '/vendor/autoload.php';

// Spawned probes use the shared composer classmap, which canonicalizes to the
// main tree. Keep branch-local App\ classes authoritative, as tests/bootstrap.php
// does for PHPUnit. Anchor to $repoRoot (this file is inside tests/Concurrency/,
// so __DIR__ . '/../' would resolve into tests/ instead of the repo root).
spl_autoload_register(static function (string $class) use ($repoRoot): void {
    foreach ([
        'App\\' => $repoRoot . '/app/',
        'Tests\\' => $repoRoot . '/tests/',
        'Database\\Factories\\' => $repoRoot . '/database/factories/',
        'Database\\Seeders\\' => $repoRoot . '/database/seeders/',
        'Marvel\\Stubs\\' => $repoRoot . '/packages/marvel/stubs/',
        'Marvel\\' => $repoRoot . '/packages/marvel/src/',
    ] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($file)) {
                require $file;

                return;
            }
            // Class belongs to a repo-owned namespace but is absent in this
            // worktree: fall through to composer (status-quo resolution).
        }
    }
}, true, true);

$app = require $repoRoot . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['shipping.delivery.max_attempts' => 1]);

$emit = static function (bool $ok, string $message = ''): void {
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_THROW_ON_ERROR), PHP_EOL;
};

try {
    $assignmentId = (int) ($argv[1] ?? 0);
    $representativeId = (int) ($argv[2] ?? 0);

    if ($assignmentId <= 0 || $representativeId <= 0) {
        throw new InvalidArgumentException('assignment and representative ids are required');
    }

    $assignment = ShipmentAssignment::query()->findOrFail($assignmentId);
    $app->make(DeliveryAttemptService::class)->recordAttempt($representativeId, $assignment, [
        'status' => 'customer_unavailable',
        'failure_reason' => 'Probe attempt',
    ]);

    $emit(true);
} catch (\Illuminate\Validation\ValidationException $e) {
    $messages = $e->errors();
    $emit(false, (string) (reset($messages)[0] ?? 'validation failed'));
} catch (Throwable $e) {
    $emit(false, $e->getMessage());
}
