<?php

namespace App\Services\Settlement;

use App\Audit\ActivityAuditService;
use App\Enums\Settlement\SettlementAcceptanceStatus;
use App\Enums\Settlement\SettlementStatementRole;
use App\Enums\SettlementMode;
use App\Exceptions\CodSettlementPrecisionException;
use App\Exceptions\SettlementClosureBlockedException;
use App\Models\Currency;
use App\Models\Settlement\CodSettlementStatement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;

/**
 * #135: COD settlement bookkeeping.
 * #182: explicit finance acceptance and an effective accepted set.
 *
 * Reads orders only to establish currency identity. It writes settlement
 * evidence rows and nothing else: no payment, order, shipment, fulfillment,
 * inventory mutation, lifecycle event, queued job, rate lookup, fee rule,
 * tax rule, or currency conversion. Acceptance only changes statement evidence
 * (and its audit trail); it never creates an adjustment or advances a payment.
 */
class CodSettlementService
{
    private const SCOPE_FIELDS = [
        'provider_code', 'mode', 'account_code', 'currency_code', 'period_start', 'period_end',
    ];

    public function createStatement(array $payload): CodSettlementStatement
    {
        $identity = $this->validateIdentity($payload);
        $identity['metadata'] = $this->statementMetadata($payload, $identity['metadata']);
        $lines = $this->resolveLines($this->normalizeLines($payload['lines'] ?? null), $identity);

        return DB::transaction(fn (): CodSettlementStatement => $this->persistStatement(
            $identity,
            $lines,
            $this->statementTotals($lines)
        ));
    }

    public function findStatement(int $id): CodSettlementStatement
    {
        return CodSettlementStatement::query()->with('lines')->findOrFail($id);
    }

    public function paginateStatements(array $filters = []): LengthAwarePaginator
    {
        $query = CodSettlementStatement::query()->with('lines')->orderByDesc('id');

        foreach (['provider_code', 'account_code', 'currency_code'] as $field) {
            $value = $filters[$field] ?? null;
            if ($value !== null && $value !== '') {
                $value = $this->stringField($value, $field, 64);
                $query->where($field, $field === 'currency_code' ? strtoupper($value) : $value);
            }
        }

        $mode = $filters['mode'] ?? null;
        if ($mode !== null && $mode !== '') {
            $mode = $mode instanceof SettlementMode ? $mode : SettlementMode::tryFrom((string) $mode);
            if ($mode === null) {
                throw $this->invalidPayload('mode');
            }
            $query->where('mode', $mode->value);
        }

        $periodStart = $filters['period_start'] ?? null;
        if ($periodStart !== null && $periodStart !== '') {
            $query->where('period_start', '>=', $this->dateField($periodStart, 'period_start'));
        }

        $periodEnd = $filters['period_end'] ?? null;
        if ($periodEnd !== null && $periodEnd !== '') {
            $query->where('period_end', '<=', $this->dateField($periodEnd, 'period_end'));
        }

        return $query->paginate(max(1, min((int) ($filters['limit'] ?? 15), 100)));
    }

    /**
     * Explicit finance acceptance. Import time and arrival order never imply
     * acceptance. A correction may supersede one accepted statement in the
     * same scope; a corrected statement must already be accepted and must not
     * itself have been superseded (no chains, no cross-scope replaces).
     * Supplementary evidence can be accepted, but never replaces anything.
     */
    public function acceptStatement(
        int $statementId,
        int $actorId,
        ?int $supersedesStatementId = null,
    ): CodSettlementStatement {
        $this->assertActor($actorId);

        return DB::transaction(function () use ($statementId, $actorId, $supersedesStatementId): CodSettlementStatement {
            $statement = $this->lockedStatement($statementId);
            $this->assertAcceptable($statement, $supersedesStatementId);
            $superseded = $this->resolveSuperseded($supersedesStatementId, $statement);
            $this->applyAcceptance($statement, $superseded, $actorId);

            return $statement->fresh();
        });
    }

    /** Rejecting a correction never mutates the previously accepted statement. */
    public function rejectStatement(int $statementId, int $actorId): CodSettlementStatement
    {
        $this->assertActor($actorId);

        return DB::transaction(function () use ($statementId, $actorId): CodSettlementStatement {
            $statement = $this->lockedStatement($statementId);
            if (!in_array($statement->acceptance_status, [
                SettlementAcceptanceStatus::PENDING,
                SettlementAcceptanceStatus::ACCEPTED,
            ], true)) {
                throw $this->invalidPayload('acceptance_status');
            }

            $before = $statement->acceptance_status->value;
            $statement->update(['acceptance_status' => SettlementAcceptanceStatus::REJECTED]);
            $this->recordRejection($statement, $before, $actorId);

            return $statement->fresh();
        });
    }

    /**
     * Accepted, non-superseded statements in one scope. Supplementary
     * statements are evidence only and never replace the accepted version.
     *
     * @param array<string,mixed> $scope
     * @return Collection<int,CodSettlementStatement>
     */
    public function effectiveSet(array $scope): Collection
    {
        $scope = $this->validateScope($scope);

        $accepted = CodSettlementStatement::query()
            ->where($scope)
            ->where('acceptance_status', SettlementAcceptanceStatus::ACCEPTED->value)
            ->orderBy('id')
            ->get();

        $supersededIds = $accepted
            ->pluck('supersedes_statement_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return $accepted->reject(fn (CodSettlementStatement $statement): bool =>
            $this->roleOf($statement) === SettlementStatementRole::SUPPLEMENTARY
            || in_array((int) $statement->id, $supersededIds, true)
        )->values();
    }

    /**
     * Closure gate: ambiguity or the absence of an accepted statement is a
     * blocking state. Nothing is closed, adjusted, or marked paid here.
     *
     * @param array<string,mixed> $scope
     */
    public function assertClosureAllowed(array $scope): void
    {
        $scope = $this->validateScope($scope);
        $effective = $this->effectiveSet($scope);
        $ids = $effective->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        if (count($ids) === 1) {
            return;
        }

        $this->blockClosure($scope, $ids);
    }

    /** @param list<int> $ids */
    private function blockClosure(array $scope, array $ids): never
    {
        $blocked = count($ids) > 1
            ? SettlementClosureBlockedException::ambiguous($ids)
            : SettlementClosureBlockedException::noAcceptedStatement();

        ActivityAuditService::recordBatch(
            'settlement',
            'settlement_closure_blocked',
            $blocked->getMessage(),
            ['source' => 'closure_gate', 'scope' => $scope],
            ['effective_statement_ids' => $ids, 'reason' => $blocked->getMessage()],
            Str::uuid()->toString(),
        );

        throw $blocked;
    }

    /** @return array<string,string> */
    public function scopeOf(CodSettlementStatement $statement): array
    {
        return [
            'provider_code' => (string) $statement->provider_code,
            'mode' => (string) $statement->mode,
            'account_code' => (string) $statement->account_code,
            'currency_code' => (string) $statement->currency_code,
            'period_start' => $statement->period_start?->toDateString() ?? '',
            'period_end' => $statement->period_end?->toDateString() ?? '',
        ];
    }

    private function lockedStatement(int $statementId): CodSettlementStatement
    {
        return CodSettlementStatement::query()->whereKey($statementId)->lockForUpdate()->firstOrFail();
    }

    private function assertAcceptable(CodSettlementStatement $statement, ?int $supersedesStatementId): void
    {
        $role = $this->roleOf($statement);
        if ($statement->acceptance_status !== SettlementAcceptanceStatus::PENDING
            || !in_array($role, [
                SettlementStatementRole::CORRECTION,
                SettlementStatementRole::SUPPLEMENTARY,
            ], true)
            || ($supersedesStatementId !== null && $role !== SettlementStatementRole::CORRECTION)
        ) {
            throw $this->invalidPayload('acceptance_status');
        }
    }

    private function resolveSuperseded(
        ?int $supersedesStatementId,
        CodSettlementStatement $statement,
    ): ?CodSettlementStatement {
        if ($supersedesStatementId === null) {
            return null;
        }

        $superseded = $this->lockedStatement($supersedesStatementId);
        if ($superseded->id === $statement->id
            || $superseded->acceptance_status !== SettlementAcceptanceStatus::ACCEPTED
            || $this->roleOf($superseded) !== SettlementStatementRole::CORRECTION
            || $this->scopeOf($superseded) !== $this->scopeOf($statement)
        ) {
            throw $this->invalidPayload('supersedes_statement_id');
        }

        return $superseded;
    }

    private function applyAcceptance(
        CodSettlementStatement $statement,
        ?CodSettlementStatement $superseded,
        int $actorId,
    ): void {
        $before = [
            'acceptance_status' => $statement->acceptance_status->value,
            'accepted_at' => null,
            'accepted_by' => null,
            'supersedes_statement_id' => null,
        ];

        $statement->update([
            'acceptance_status' => SettlementAcceptanceStatus::ACCEPTED,
            'accepted_at' => now(),
            'accepted_by' => $actorId,
            'supersedes_statement_id' => $superseded?->id,
        ]);

        if ($superseded !== null) {
            $this->markSuperseded($superseded, $statement, $actorId);
        }

        $this->recordAcceptance($statement, $before, $superseded, $actorId);
    }

    private function markSuperseded(
        CodSettlementStatement $superseded,
        CodSettlementStatement $correction,
        int $actorId,
    ): void {
        ActivityAuditService::recordSubject(
            CodSettlementStatement::class,
            (int) $superseded->id,
            'superseded',
            'settlement',
            'Accepted correction superseded a prior statement.',
            ['acceptance_status' => SettlementAcceptanceStatus::ACCEPTED->value],
            ['acceptance_status' => SettlementAcceptanceStatus::SUPERSEDED->value],
            ['source' => 'finance', 'scope' => $this->scopeOf($correction)],
            causerId: $actorId,
            causerType: User::class,
            batchUuid: Str::uuid()->toString(),
        );
        $superseded->update(['acceptance_status' => SettlementAcceptanceStatus::SUPERSEDED]);
    }

    /** @param array<string,mixed> $before */
    private function recordAcceptance(
        CodSettlementStatement $statement,
        array $before,
        ?CodSettlementStatement $superseded,
        int $actorId,
    ): void {
        ActivityAuditService::recordSubject(
            CodSettlementStatement::class,
            (int) $statement->id,
            'accepted',
            'settlement',
            'Finance explicitly accepted a settlement statement.',
            $before,
            [
                'acceptance_status' => SettlementAcceptanceStatus::ACCEPTED->value,
                'accepted_by' => $actorId,
                'supersedes_statement_id' => $superseded?->id,
            ],
            ['source' => 'finance', 'scope' => $this->scopeOf($statement)],
            causerId: $actorId,
            causerType: User::class,
        );
    }

    private function recordRejection(CodSettlementStatement $statement, string $before, int $actorId): void
    {
        ActivityAuditService::recordSubject(
            CodSettlementStatement::class,
            (int) $statement->id,
            'rejected',
            'settlement',
            'Finance explicitly rejected a settlement statement.',
            ['acceptance_status' => $before],
            ['acceptance_status' => SettlementAcceptanceStatus::REJECTED->value],
            ['source' => 'finance', 'scope' => $this->scopeOf($statement)],
            causerId: $actorId,
            causerType: User::class,
        );
    }

    private function persistStatement(array $identity, array $lines, array $totals): CodSettlementStatement
    {
        $statement = CodSettlementStatement::create($identity + $totals + [
            'acceptance_status' => SettlementAcceptanceStatus::PENDING,
        ]);

        foreach ($lines as $line) {
            $statement->lines()->create($line);
        }

        return $statement->load('lines');
    }

    private function resolveLines(array $normalizedLines, array $identity): array
    {
        $orders = Order::query()
            ->whereIn('id', array_values(array_unique(array_column($normalizedLines, 'order_id'))))
            ->get()
            ->keyBy('id');

        $currencyCode = null;
        $decimalPlaces = null;
        $lineIdentity = array_intersect_key($identity, array_flip([
            'provider_code', 'mode', 'account_code', 'period_start', 'period_end',
        ]));
        $resolved = [];

        foreach ($normalizedLines as $line) {
            [$currencyCode, $decimalPlaces] = $this->linePrecision($line, $orders, $currencyCode, $decimalPlaces);
            $resolved[] = $this->resolveLine($line, $lineIdentity, $currencyCode, $decimalPlaces);
        }

        return $resolved;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function linePrecision(array $line, Collection $orders, ?string $currencyCode, ?int $decimalPlaces): array
    {
        $order = $orders->get($line['order_id']);
        if ($order === null) {
            throw $this->invalidPayload('order_id');
        }

        $lineCurrency = strtoupper(trim((string) $order->currency_code));
        if ($lineCurrency === '') {
            throw CodSettlementPrecisionException::forCurrency();
        }

        if ($currencyCode !== null && $lineCurrency !== $currencyCode) {
            throw CodSettlementPrecisionException::forMixedCurrency();
        }

        $lineDecimalPlaces = $this->decimalPlacesFor($lineCurrency);
        if ($decimalPlaces !== null && $lineDecimalPlaces !== $decimalPlaces) {
            throw CodSettlementPrecisionException::forMixedCurrency();
        }

        return [$lineCurrency, $lineDecimalPlaces];
    }

    private function resolveLine(
        array $line,
        array $lineIdentity,
        string $currencyCode,
        int $decimalPlaces
    ): array {
        $expectedMinor = $this->toMinorUnits($line['expected'], $decimalPlaces);
        $receivedMinor = $this->toMinorUnits($line['received'], $decimalPlaces);

        return $lineIdentity + [
            'line_key' => $line['line_key'],
            'order_id' => $line['order_id'],
            'currency_code' => $currencyCode,
            'decimal_places' => $decimalPlaces,
            'expected_minor' => $expectedMinor,
            'received_minor' => $receivedMinor,
            'variance_minor' => $receivedMinor - $expectedMinor,
        ];
    }

    private function statementTotals(array $lines): array
    {
        $expectedMinor = 0;
        $receivedMinor = 0;

        foreach ($lines as $line) {
            $expectedMinor += $line['expected_minor'];
            $receivedMinor += $line['received_minor'];
        }

        return [
            'currency_code' => $lines[0]['currency_code'],
            'decimal_places' => $lines[0]['decimal_places'],
            'expected_minor' => $expectedMinor,
            'received_minor' => $receivedMinor,
            'variance_minor' => $receivedMinor - $expectedMinor,
        ];
    }

    private function validateIdentity(array $payload): array
    {
        $mode = $payload['mode'] ?? null;
        $mode = $mode instanceof SettlementMode ? $mode : SettlementMode::tryFrom((string) $mode);
        if ($mode === null) {
            throw $this->invalidPayload('mode');
        }

        $periodStart = $this->dateField($payload['period_start'] ?? null, 'period_start');
        $periodEnd = $this->dateField($payload['period_end'] ?? null, 'period_end');
        if ($periodStart > $periodEnd) {
            throw $this->invalidPayload('period_end');
        }

        $metadata = $payload['metadata'] ?? null;
        if ($metadata !== null && !is_array($metadata)) {
            throw $this->invalidPayload('metadata');
        }

        return [
            'statement_key' => $this->stringField($payload['statement_key'] ?? null, 'statement_key', 191),
            'provider_code' => $this->stringField($payload['provider_code'] ?? null, 'provider_code', 32),
            'mode' => $mode->value,
            'account_code' => $this->stringField($payload['account_code'] ?? null, 'account_code', 64),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'metadata' => $metadata,
        ];
    }

    private function normalizeLines(mixed $lines): array
    {
        if (!is_array($lines) || $lines === []) {
            throw $this->invalidPayload('lines');
        }

        $normalized = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw $this->invalidPayload('lines');
            }

            $orderId = $line['order_id'] ?? null;
            if (!is_int($orderId) && !(is_string($orderId) && ctype_digit($orderId))) {
                throw $this->invalidPayload('order_id');
            }
            $orderId = (int) $orderId;
            if ($orderId <= 0) {
                throw $this->invalidPayload('order_id');
            }

            $lineKey = $line['line_key'] ?? (string) $orderId;
            if (!is_int($lineKey) && !is_string($lineKey)) {
                throw $this->invalidPayload('line_key');
            }

            $normalized[] = [
                'line_key' => $this->stringField((string) $lineKey, 'line_key', 191),
                'order_id' => $orderId,
                'expected' => $line['expected'] ?? null,
                'received' => $line['received'] ?? null,
            ];
        }

        return $normalized;
    }

    private function decimalPlacesFor(string $currencyCode): int
    {
        $currency = Currency::query()->where('code', $currencyCode)->first();
        if ($currency === null || $currency->decimal_places === null) {
            throw CodSettlementPrecisionException::forCurrency();
        }

        $decimalPlaces = (int) $currency->decimal_places;
        if ($decimalPlaces < 0 || $decimalPlaces > 4) {
            throw CodSettlementPrecisionException::forCurrency();
        }

        return $decimalPlaces;
    }

    /**
     * Convert a major-unit decimal into integer minor units without floating
     * point arithmetic. More fraction digits than the currency supports is a
     * precision failure, never a silent round.
     */
    private function toMinorUnits(mixed $amount, int $decimalPlaces): int
    {
        if (!is_int($amount) && !is_string($amount)) {
            throw CodSettlementPrecisionException::forAmount();
        }

        $raw = trim((string) $amount);
        if (!preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
            throw CodSettlementPrecisionException::forAmount();
        }

        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        if (strlen($fraction) > $decimalPlaces) {
            throw CodSettlementPrecisionException::forAmount();
        }

        $fraction = str_pad($fraction, $decimalPlaces, '0');
        $digits = ltrim($decimalPlaces === 0 ? $whole : $whole . $fraction, '0') ?: '0';
        $max = (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw CodSettlementPrecisionException::forAmount();
        }

        return (int) $digits;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed>|null $metadata
     * @return array<string,mixed>
     */
    private function statementMetadata(array $payload, ?array $metadata): array
    {
        $metadata = $metadata ?? [];
        $role = $payload['statement_role'] ?? SettlementStatementRole::CORRECTION->value;
        $role = $role instanceof SettlementStatementRole ? $role : SettlementStatementRole::tryFrom((string) $role);
        if ($role === null || array_key_exists('statement_role', $metadata)) {
            throw $this->invalidPayload('statement_role');
        }

        $metadata['statement_role'] = $role->value;

        return $metadata;
    }

    private function roleOf(CodSettlementStatement $statement): SettlementStatementRole
    {
        $role = is_array($statement->metadata)
            ? ($statement->metadata['statement_role'] ?? SettlementStatementRole::CORRECTION->value)
            : SettlementStatementRole::CORRECTION->value;

        return $role instanceof SettlementStatementRole
            ? $role
            : (SettlementStatementRole::tryFrom((string) $role) ?? SettlementStatementRole::CORRECTION);
    }

    private function assertActor(int $actorId): void
    {
        if ($actorId <= 0 || !User::query()->whereKey($actorId)->exists()) {
            throw $this->invalidPayload('accepted_by');
        }
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,string>
     */
    private function validateScope(array $scope): array
    {
        if (array_diff(array_keys($scope), self::SCOPE_FIELDS) !== []
            || array_diff(self::SCOPE_FIELDS, array_keys($scope)) !== []
        ) {
            throw $this->invalidPayload('scope');
        }

        $validated = [];
        foreach (self::SCOPE_FIELDS as $field) {
            if (!is_string($scope[$field]) || trim($scope[$field]) === '') {
                throw $this->invalidPayload($field);
            }
            $validated[$field] = $scope[$field];
        }

        return $validated;
    }

    private function stringField(mixed $value, string $field, int $maxLength): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw $this->invalidPayload($field);
        }

        $value = trim((string) $value);
        if ($value === '' || strlen($value) > $maxLength) {
            throw $this->invalidPayload($field);
        }

        return $value;
    }

    private function dateField(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)) {
            throw $this->invalidPayload($field);
        }

        if (!checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            throw $this->invalidPayload($field);
        }

        return $value;
    }

    private function invalidPayload(string $field): InvalidArgumentException
    {
        return new InvalidArgumentException(__('cod_settlement.invalid_payload', ['field' => $field]));
    }
}
