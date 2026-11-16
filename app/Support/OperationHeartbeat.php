<?php

namespace App\Support;

use Throwable;

/**
 * One owner for the operation heartbeat protocol: JSON signal files under
 * storage/app/imports that carry progress, cancellation requests and other
 * operation state between jobs and controllers.
 *
 * Importers, jobs and controllers must not build signal paths themselves.
 */
final class OperationHeartbeat
{
    private const SIGNAL_DIR = 'imports';

    private function __construct(private readonly int $operationId)
    {
    }

    public static function for(int $operationId): self
    {
        return new self($operationId);
    }

    public function signalPath(string $type): string
    {
        return storage_path('app/' . self::SIGNAL_DIR . '/' . $type . '_' . $this->operationId . '.json');
    }

    public function write(string $type, array $data = []): void
    {
        $path = $this->signalPath($type);

        try {
            $dir = dirname($path);

            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            file_put_contents($path, json_encode($data), LOCK_EX);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(string $type): ?array
    {
        $path = $this->signalPath($type);
        clearstatcache(true, $path);

        if (!file_exists($path)) {
            return null;
        }

        try {
            return json_decode((string) file_get_contents($path), true) ?: null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    public function exists(string $type): bool
    {
        $path = $this->signalPath($type);
        clearstatcache(true, $path);

        return file_exists($path);
    }

    public function remove(string $type): void
    {
        $path = $this->signalPath($type);
        clearstatcache(true, $path);

        if (file_exists($path)) {
            @unlink($path);
        }
    }

    public function cancelRequested(): bool
    {
        return $this->exists('cancel');
    }
}
