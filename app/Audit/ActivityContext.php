<?php

namespace App\Audit;

/**
 * Request/execution context captured alongside audit events.
 *
 * Populated by RequestIdMiddleware (HTTP), by import/batch flows, and by
 * commands/jobs. Values are captured into ActivitySnapshot at dispatch time
 * so queued writers never depend on the original request scope.
 */
final class ActivityContext
{
    private array $data = [];

    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = new self();
    }

    public function set(string $key, mixed $value): self
    {
        if ($value !== null && $value !== '') {
            $this->data[$key] = $value;
        }

        return $this;
    }

    public function merge(array $values): self
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }

        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->data;
    }

    public static function capture(): array
    {
        return self::instance()->all();
    }

    public static function forJob(string $job): void
    {
        self::instance()->merge(['source' => 'queue', 'job' => $job]);
    }

    public static function forCommand(string $command): void
    {
        self::instance()->merge(['source' => 'console', 'command' => $command]);
    }

    public static function forImport(int $importId, ?string $batchUuid = null): void
    {
        self::instance()->merge(['source' => 'import', 'import_id' => $importId]);
        if ($batchUuid) {
            self::instance()->set('batch_uuid', $batchUuid);
        }
    }
}
