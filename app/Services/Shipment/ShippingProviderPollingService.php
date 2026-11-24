<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\Contracts\Shipping\ShippingProviderPoller;
use App\DTOs\Shipping\CarrierWebhookEvent;
use App\DTOs\Shipping\ShippingPollingResult;
use App\Models\Shipping\ShippingPollCursor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class ShippingProviderPollingService
{
    public function __construct(
        private readonly ShippingWebhookInbox $inbox,
        private readonly ShippingProviderPoller $poller,
    ) {}

    public function poll(string $providerCode, string $mode, string $accountCode): ShippingPollingResult
    {
        $polling = $this->pollingConfig();
        $lockName = 'shipping-poll:'.$providerCode.':'.$mode.':'.$accountCode;
        $lock = Cache::lock($lockName, $polling['lock_ttl_seconds']);

        try {
            if (! $lock->get()) {
                return new ShippingPollingResult(0, 0, 0, $this->cursorValue($providerCode, $mode, $accountCode));
            }
        } catch (\Throwable $e) {
            Log::warning('Shipping polling lock unavailable', ['provider' => $providerCode, 'mode' => $mode, 'account' => $accountCode, 'error' => $e->getMessage()]);

            return new ShippingPollingResult(0, 0, 1, $this->cursorValue($providerCode, $mode, $accountCode));
        }

        try {
            return $this->pollLocked($providerCode, $mode, $accountCode, $polling);
        } finally {
            try {
                $lock->release();
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @param  array{max_pages:int,time_budget_seconds:int,provider_call_timeout_seconds:int,lock_ttl_seconds:int}  $polling
     */
    private function pollLocked(string $providerCode, string $mode, string $accountCode, array $polling): ShippingPollingResult
    {
        // Ensure the cursor row exists before polling; each write below uses a
        // fresh instance so a failed save cannot leave a dirty model behind.
        $this->cursorRow($providerCode, $mode, $accountCode);
        $cursor = $this->cursorValue($providerCode, $mode, $accountCode);
        $accepted = 0;
        $duplicates = 0;
        $errors = 0;
        $hasMore = true;
        $pages = 0;
        $startedAt = now()->getTimestamp();

        while ($hasMore) {
            if ($pages >= $polling['max_pages']) {
                $errors++;
                $this->markError($providerCode, $mode, $accountCode, $cursor, 'Shipping polling page budget exceeded', new \RuntimeException('page_budget_exceeded'));
                break;
            }
            if (now()->getTimestamp() - $startedAt >= $polling['time_budget_seconds']) {
                $errors++;
                $this->markError($providerCode, $mode, $accountCode, $cursor, 'Shipping polling time budget exceeded', new \RuntimeException('time_budget_exceeded'));
                break;
            }
            $pages++;

            $callStartedAt = now()->getTimestamp();

            try {
                $page = $this->poller->poll($providerCode, $mode, $accountCode, $cursor);
            } catch (\Throwable $e) {
                $errors++;
                $this->markError($providerCode, $mode, $accountCode, $cursor, 'Shipping polling page failed', $e);
                break;
            }

            // Synchronous pollers cannot be interrupted portably; detect an
            // overrun and fail closed. The lock TTL covers this overrun.
            if (now()->getTimestamp() - $callStartedAt > $polling['provider_call_timeout_seconds']) {
                $errors++;
                $this->markError($providerCode, $mode, $accountCode, $cursor, 'Shipping polling provider call timed out', new \RuntimeException('provider_call_timeout'));
                break;
            }

            try {
                [$events, $nextCursor, $hasMore] = $this->normalizePage($page);
            } catch (\Throwable $e) {
                $errors++;
                $this->markError($providerCode, $mode, $accountCode, $cursor, 'Malformed shipping polling response', $e);
                break;
            }

            try {
                $this->assertCanonicalEvents($events, $providerCode, $mode, $accountCode);
            } catch (\Throwable $e) {
                $errors++;
                $this->markError($providerCode, $mode, $accountCode, $cursor, 'Shipping polling event identity rejected', $e);
                break;
            }

            $advancingCursor = $nextCursor !== null && $nextCursor !== '';
            $previousCursor = $cursor;
            $cursor = $advancingCursor ? $nextCursor : $cursor;

            $successAttributes = [
                'last_polled_at' => now(),
                'last_success_at' => now(),
                'last_error' => null,
            ];
            if ($advancingCursor) {
                $successAttributes['cursor'] = $nextCursor;
            }

            $pageAccepted = 0;
            $pageDuplicates = 0;

            try {
                DB::transaction(function () use ($events, &$pageAccepted, &$pageDuplicates, $successAttributes, $providerCode, $mode, $accountCode): void {
                    foreach ($events as $event) {
                        $record = $this->inbox->store($event);
                        if ($record === null) {
                            $pageDuplicates++;
                        } else {
                            $pageAccepted++;
                        }
                    }

                    $this->cursorRow($providerCode, $mode, $accountCode)->forceFill($successAttributes)->save();
                });
            } catch (\Throwable $e) {
                $errors++;
                // Cursor must not advance past non-durable acceptance.
                $this->markError($providerCode, $mode, $accountCode, $previousCursor, 'Shipping polling storage failed', $e);
                break;
            }

            $accepted += $pageAccepted;
            $duplicates += $pageDuplicates;

            if ($hasMore && (! $advancingCursor || $nextCursor === $previousCursor)) {
                // Defensive guard: non-advancing pagination would spin.
                $errors++;
                $this->markError($providerCode, $mode, $accountCode, $cursor, 'Shipping polling pagination did not advance', new \RuntimeException('non_advancing_cursor'));
                break;
            }
        }

        return new ShippingPollingResult($accepted, $duplicates, $errors, $this->cursorValue($providerCode, $mode, $accountCode));
    }

    /**
     * @return array{max_pages:int,time_budget_seconds:int,provider_call_timeout_seconds:int,lock_ttl_seconds:int}
     */
    private function pollingConfig(): array
    {
        $maxPages = max(1, (int) config('shipping.polling.max_pages', 1000));
        $timeBudget = max(1, (int) config('shipping.polling.time_budget_seconds', 300));
        $callTimeout = max(1, (int) config('shipping.polling.provider_call_timeout_seconds', 30));
        $worstCase = $timeBudget + $callTimeout;
        $lockTtl = max($worstCase + 1, (int) config('shipping.polling.lock_ttl_seconds', $worstCase + 60));

        return [
            'max_pages' => $maxPages,
            'time_budget_seconds' => $timeBudget,
            'provider_call_timeout_seconds' => $callTimeout,
            'lock_ttl_seconds' => $lockTtl,
        ];
    }

    /**
     * @return array{0:array<mixed>,1:?string,2:bool}
     */
    private function normalizePage(mixed $page): array
    {
        if (! is_object($page)) {
            throw new InvalidArgumentException('Poller response must be an object.');
        }

        foreach (['events', 'nextCursor', 'hasMore'] as $field) {
            if (! property_exists($page, $field)) {
                throw new InvalidArgumentException('Poller response is missing ['.$field.'].');
            }
        }

        try {
            $events = $page->events;
            $nextCursor = $page->nextCursor;
            $hasMore = $page->hasMore;
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Poller response fields are unreadable.', 0, $e);
        }

        if (! is_array($events)) {
            throw new InvalidArgumentException('Poller response [events] must be an array.');
        }
        if ($nextCursor !== null && ! is_scalar($nextCursor)) {
            throw new InvalidArgumentException('Poller response [nextCursor] must be scalar or null.');
        }
        if (! is_bool($hasMore)) {
            throw new InvalidArgumentException('Poller response [hasMore] must be a boolean.');
        }

        return [$events, $nextCursor === null ? null : (string) $nextCursor, $hasMore];
    }

    /** @param array<mixed> $events */
    private function assertCanonicalEvents(array $events, string $providerCode, string $mode, string $accountCode): void
    {
        foreach ($events as $event) {
            if (! $event instanceof CarrierWebhookEvent
                || $event->providerCode !== $providerCode
                || $event->mode !== $mode
                || $event->accountCode !== $accountCode) {
                throw new InvalidArgumentException('Polled event identity does not match the canonical target.');
            }
        }
    }

    private function markError(string $providerCode, string $mode, string $accountCode, ?string $cursor, string $message, \Throwable $e): void
    {
        try {
            $this->cursorRow($providerCode, $mode, $accountCode)
                ->forceFill(['last_polled_at' => now(), 'last_error' => $e->getMessage()])
                ->save();
        } catch (\Throwable) {
            // Preserve isolation: error reporting must not abort the caller.
        }
        Log::warning($message, [
            'provider' => $providerCode,
            'mode' => $mode,
            'account' => $accountCode,
            'cursor' => $cursor,
            'error' => $e->getMessage(),
        ]);
    }

    private function cursorRow(string $providerCode, string $mode, string $accountCode): ShippingPollCursor
    {
        return ShippingPollCursor::query()->firstOrCreate([
            'provider_code' => $providerCode,
            'mode' => $mode,
            'account_code' => $accountCode,
        ]);
    }

    private function cursorValue(string $providerCode, string $mode, string $accountCode): ?string
    {
        return ShippingPollCursor::query()
            ->where('provider_code', $providerCode)
            ->where('mode', $mode)
            ->where('account_code', $accountCode)
            ->value('cursor');
    }
}
