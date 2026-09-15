<?php

namespace App\Audit;

/**
 * Immutable, self-contained description of a single audit event.
 *
 * Carries everything the writer needs to persist a Spatie Activity record
 * WITHOUT re-fetching the subject, so the audit survives soft-delete,
 * force-delete, scheduled purge and import rollback.
 */
final class ActivitySnapshot
{
    public function __construct(
        public string $logName,
        public string $event,
        public string $description,
        public ?string $subjectType = null,
        public ?int $subjectId = null,
        public ?string $causerType = null,
        public ?int $causerId = null,
        public ?array $old = null,
        public ?array $new = null,
        public array $context = [],
        public ?string $batchUuid = null,
        public ?string $reason = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'log_name' => $this->logName,
            'event' => $this->event,
            'description' => $this->description,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'causer_type' => $this->causerType,
            'causer_id' => $this->causerId,
            'old' => $this->old,
            'new' => $this->new,
            'context' => $this->context,
            'batch_uuid' => $this->batchUuid,
            'reason' => $this->reason,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new static(
            logName: (string) ($data['log_name'] ?? 'default'),
            event: (string) ($data['event'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            subjectType: isset($data['subject_type']) ? (string) $data['subject_type'] : null,
            subjectId: isset($data['subject_id']) ? (int) $data['subject_id'] : null,
            causerType: isset($data['causer_type']) ? (string) $data['causer_type'] : null,
            causerId: isset($data['causer_id']) ? (int) $data['causer_id'] : null,
            old: isset($data['old']) && is_array($data['old']) ? $data['old'] : null,
            new: isset($data['new']) && is_array($data['new']) ? $data['new'] : null,
            context: isset($data['context']) && is_array($data['context']) ? $data['context'] : [],
            batchUuid: isset($data['batch_uuid']) ? (string) $data['batch_uuid'] : null,
            reason: isset($data['reason']) ? (string) $data['reason'] : null,
        );
    }

    public function properties(): array
    {
        return [
            'old' => $this->old,
            'new' => $this->new,
            'context' => $this->context,
            'reason' => $this->reason,
        ];
    }
}
