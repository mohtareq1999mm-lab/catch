<?php

namespace App\Audit;

use App\Jobs\LogActivityJob;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * Canonical Activity Log writer.
 *
 * All audit events flow through here. It persists via the Spatie Activity
 * model directly (not the logger's performedOn()) so subject_type/subject_id
 * survive even when the subject row has been soft-deleted or hard-deleted.
 */
final class ActivityAuditService
{
    /**
     * Persist a fully-formed snapshot synchronously.
     */
    public static function record(ActivitySnapshot $snapshot): ?Activity
    {
        if (!config('activitylog.enabled', true)) {
            return null;
        }

        try {
            return Activity::query()->create([
                'log_name' => $snapshot->logName,
                'description' => $snapshot->description,
                'subject_type' => $snapshot->subjectType,
                'subject_id' => $snapshot->subjectId,
                'causer_type' => $snapshot->causerType,
                'causer_id' => $snapshot->causerId,
                'event' => $snapshot->event,
                'properties' => ActivityRedactor::redact($snapshot->properties()),
                'batch_uuid' => $snapshot->batchUuid,
            ]);
        } catch (\Throwable $e) {
            // Never silently swallow, but never break the business transaction.
            report($e);

            return null;
        }
    }

    /**
     * Build a snapshot from a model + metadata and persist synchronously.
     */
    public static function recordModel(
        Model $model,
        string $event,
        string $logName,
        string $description,
        ?array $old = null,
        ?array $new = null,
        array $context = [],
        ?string $reason = null,
        ?string $batchUuid = null,
    ): ?Activity {
        $resolved = ActorResolver::resolve();
        $mergedContext = array_merge(ActivityContext::capture(), $context);
        $mergedContext['actor'] = $resolved['actor'];
        $mergedContext['executor'] = $resolved['executor'];

        return self::record(new ActivitySnapshot(
            logName: $logName,
            event: $event,
            description: $description,
            subjectType: get_class($model),
            subjectId: (int) $model->getKey(),
            causerType: $resolved['causer_type'],
            causerId: $resolved['causer_id'],
            old: $old,
            new: $new,
            context: $mergedContext,
            batchUuid: $batchUuid,
            reason: $reason,
        ));
    }

    /**
     * Record an event against a subject identified only by type + id (no model
     * instance required). Used by queued listeners/business events where the
     * subject may already be deleted, and where the business actor must be
     * preserved across the queue boundary.
     */
    public static function recordSubject(
        string $subjectType,
        int $subjectId,
        string $event,
        string $logName,
        string $description,
        ?array $old = null,
        ?array $new = null,
        array $context = [],
        ?int $causerId = null,
        ?string $causerType = null,
        ?string $batchUuid = null,
        ?string $reason = null,
    ): ?Activity {
        $resolved = ActorResolver::resolve();
        $mergedContext = array_merge(ActivityContext::capture(), $context);

        // Preserve the explicit business actor when a causer was supplied.
        $causerType = $causerType ?? $resolved['causer_type'];
        $causerId = $causerId ?? $resolved['causer_id'];

        if ($causerId && !isset($mergedContext['actor']['user_id'])) {
            $mergedContext['actor'] = ['type' => 'human', 'user_id' => $causerId, 'user_type' => $causerType];
        }
        $mergedContext['actor'] = $mergedContext['actor'] ?? $resolved['actor'];
        $mergedContext['executor'] = $resolved['executor'];

        return self::record(new ActivitySnapshot(
            logName: $logName,
            event: $event,
            description: $description,
            subjectType: $subjectType,
            subjectId: $subjectId,
            causerType: $causerType,
            causerId: $causerId,
            old: $old,
            new: $new,
            context: $mergedContext,
            batchUuid: $batchUuid,
            reason: $reason,
        ));
    }

    /**
     * Record a subject-less batch/operation event (bulk delete, destroy all,
     * import lifecycle, retention prune, etc.).
     */
    public static function recordBatch(
        string $logName,
        string $event,
        string $description,
        array $context = [],
        ?array $properties = null,
        ?string $batchUuid = null,
        ?string $reason = null,
        ?int $causerId = null,
        ?string $causerType = null,
    ): ?Activity {
        $resolved = ActorResolver::resolve();
        $mergedContext = array_merge(ActivityContext::capture(), $context);
        $mergedContext['actor'] = $resolved['actor'];
        $mergedContext['executor'] = $resolved['executor'];

        $causerType = $causerType ?? $resolved['causer_type'];
        $causerId = $causerId ?? $resolved['causer_id'];

        if ($causerId && !isset($mergedContext['actor']['user_id'])) {
            $mergedContext['actor'] = ['type' => 'human', 'user_id' => $causerId, 'user_type' => $causerType];
        }

        $snapshot = new ActivitySnapshot(
            logName: $logName,
            event: $event,
            description: $description,
            subjectType: null,
            subjectId: null,
            causerType: $causerType,
            causerId: $causerId,
            new: $properties,
            context: $mergedContext,
            batchUuid: $batchUuid,
            reason: $reason,
        );

        return self::record($snapshot);
    }

    /**
     * Persist a snapshot asynchronously via the queue. The snapshot is fully
     * self-contained, so the worker never re-fetches the subject.
     */
    public static function dispatch(ActivitySnapshot $snapshot): void
    {
        LogActivityJob::dispatch($snapshot);
    }
}
