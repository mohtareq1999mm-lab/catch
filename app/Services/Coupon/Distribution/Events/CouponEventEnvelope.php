<?php

namespace App\Services\Coupon\Distribution\Events;

use Illuminate\Support\Str;

/**
 * Consistent envelope for every coupon event on the RabbitMQ backbone.
 *
 * Carries tracing (event_id / correlation_id / causation_id), routing
 * (event_type / aggregate), versioning, and a minimal payload. Sensitive
 * business data (coupon codes, rules, metrics, PII) MUST NOT be placed in
 * the payload — identifiers only; consumers load state from MySQL.
 */
class CouponEventEnvelope
{
    public string $eventId;
    public string $eventType;
    public int $version = CouponDistributionEvents::VERSION;
    public string $occurredAt;
    public ?string $publishedAt = null;
    public string $correlationId;
    public ?string $causationId = null;
    public string $aggregateType = 'coupon';
    public int|string|null $aggregateId = null;
    public ?int $userId = null;
    public ?int $distributionRunId = null;
    public ?string $treeHash = null;
    public int $attempt = 1;

    /** @var array<string, mixed> */
    public array $payload = [];

    public static function create(
        string $eventType,
        int|string|null $aggregateId = null,
        array $payload = [],
        ?string $correlationId = null,
        ?string $causationId = null,
        ?int $userId = null,
        ?int $distributionRunId = null,
        ?string $treeHash = null,
        string $aggregateType = 'coupon',
    ): self {
        $envelope = new self();
        $envelope->eventId = (string) Str::uuid();
        $envelope->eventType = $eventType;
        $envelope->occurredAt = now()->toIso8601String();
        $envelope->correlationId = $correlationId ?? (string) Str::uuid();
        $envelope->causationId = $causationId;
        $envelope->aggregateType = $aggregateType;
        $envelope->aggregateId = $aggregateId;
        $envelope->userId = $userId;
        $envelope->distributionRunId = $distributionRunId;
        $envelope->treeHash = $treeHash;
        $envelope->payload = $payload;

        return $envelope;
    }

    /**
     * Derive a child event: inherits correlation, points causation at the
     * parent event_id — this builds the traceable event graph.
     */
    public function derive(
        string $eventType,
        array $payload = [],
        ?int $userId = null,
        int|string|null $aggregateId = null,
    ): self {
        return self::create(
            eventType: $eventType,
            aggregateId: $aggregateId ?? $this->aggregateId,
            payload: $payload,
            correlationId: $this->correlationId,
            causationId: $this->eventId,
            userId: $userId ?? $this->userId,
            distributionRunId: $this->distributionRunId,
            treeHash: $this->treeHash,
            aggregateType: $this->aggregateType,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'version' => $this->version,
            'occurred_at' => $this->occurredAt,
            'published_at' => $this->publishedAt,
            'correlation_id' => $this->correlationId,
            'causation_id' => $this->causationId,
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'user_id' => $this->userId,
            'distribution_run_id' => $this->distributionRunId,
            'tree_hash' => $this->treeHash,
            'attempt' => $this->attempt,
            'payload' => $this->payload,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Strict schema validation. Malformed messages are poison — the caller
     * routes them to failed/dead_lettered, never to business processing.
     *
     * @throws InvalidCouponEventException
     */
    public static function fromArray(array $data): self
    {
        $fail = static fn (string $reason): never => throw new InvalidCouponEventException($reason);

        $eventId = $data['event_id'] ?? null;
        $eventType = $data['event_type'] ?? null;
        $version = $data['version'] ?? null;
        $correlationId = $data['correlation_id'] ?? null;

        if (! is_string($eventId) || ! Str::isUuid($eventId)) {
            $fail('event_id must be a UUID.');
        }

        if (! is_string($eventType) || ! CouponDistributionEvents::isKnown($eventType)) {
            $fail('event_type is unknown.');
        }

        if ($version !== CouponDistributionEvents::VERSION) {
            $fail('Unsupported event version ['.var_export($version, true).'].');
        }

        if (! is_string($correlationId) || ! Str::isUuid($correlationId)) {
            $fail('correlation_id must be a UUID.');
        }

        $aggregateId = $data['aggregate_id'] ?? null;

        if ($aggregateId !== null && ! is_int($aggregateId) && ! is_string($aggregateId)) {
            $fail('aggregate_id must be int|string|null.');
        }

        $userId = $data['user_id'] ?? null;

        if ($userId !== null && ! is_int($userId)) {
            $fail('user_id must be int|null.');
        }

        $runId = $data['distribution_run_id'] ?? null;

        if ($runId !== null && ! is_int($runId)) {
            $fail('distribution_run_id must be int|null.');
        }

        $payload = $data['payload'] ?? [];

        if (! is_array($payload)) {
            $fail('payload must be an object.');
        }

        $envelope = new self();
        $envelope->eventId = $eventId;
        $envelope->eventType = $eventType;
        $envelope->version = $version;
        $envelope->occurredAt = (string) ($data['occurred_at'] ?? '');
        $envelope->publishedAt = isset($data['published_at']) ? (string) $data['published_at'] : null;
        $envelope->correlationId = $correlationId;
        $causationId = $data['causation_id'] ?? null;

        if ($causationId !== null && (! is_string($causationId) || ! Str::isUuid($causationId))) {
            $fail('causation_id must be a UUID when present.');
        }

        $envelope->causationId = $causationId;
        $envelope->aggregateType = (string) ($data['aggregate_type'] ?? 'coupon');
        $envelope->aggregateId = $aggregateId;
        $envelope->userId = $userId;
        $envelope->distributionRunId = $runId;
        $envelope->treeHash = isset($data['tree_hash']) ? (string) $data['tree_hash'] : null;
        $envelope->attempt = max(1, (int) ($data['attempt'] ?? 1));
        $envelope->payload = $payload;

        if ($envelope->occurredAt === '') {
            $fail('occurred_at is required.');
        }

        return $envelope;
    }

    /**
     * @throws InvalidCouponEventException
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidCouponEventException('Body is not valid JSON: '.$e->getMessage(), 0, $e);
        }

        if (! is_array($data)) {
            throw new InvalidCouponEventException('Body must decode to a JSON object.');
        }

        return self::fromArray($data);
    }
}
