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
        private readonly ?ShippingProviderPoller $poller = null,
    ) {}

    public function poll(string $providerCode, string $mode, string $accountCode): ShippingPollingResult
    {
        $lockName = 'shipping-poll:'.$providerCode.':'.$mode.':'.$accountCode;
        $lock = Cache::lock($lockName, 300);

        try {
            if (! $lock->get()) {
                return new ShippingPollingResult(0, 0, 0, $this->cursorValue($providerCode, $mode, $accountCode));
            }
        } catch (\Throwable $e) {
            Log::warning('Shipping polling lock unavailable', ['provider' => $providerCode, 'mode' => $mode, 'account' => $accountCode, 'error' => $e->getMessage()]);

            return new ShippingPollingResult(0, 0, 1, $this->cursorValue($providerCode, $mode, $accountCode));
        }

        try {
            return $this->pollLocked($providerCode, $mode, $accountCode);
        } finally {
            try {
                $lock->release();
            } catch (\Throwable) {
            }
        }
    }

    private function pollLocked(string $providerCode, string $mode, string $accountCode): ShippingPollingResult
    {
        $poller = $this->poller;
        if ($poller === null) {
            return new ShippingPollingResult(0, 0, 0, $this->cursorValue($providerCode, $mode, $accountCode));
        }

        // Ensure the cursor row exists before polling; each write below uses a
        // fresh instance so a failed save cannot leave a dirty model behind.
        $this->cursorRow($providerCode, $mode, $accountCode);
        $cursor = $this->cursorValue($providerCode, $mode, $accountCode);
        $accepted = 0;
        $duplicates = 0;
        $errors = 0;
        $hasMore = true;
        $pages = 0;
        $maxPages = (int) config('shipping.polling.max_pages', 1000);
        $timeBudget = (int) config('shipping.polling.time_budget_seconds', 300);
        $startedAt = now()->getTimestamp();

        while ($hasMore) {
            if ($pages >= $maxPages) {
                $errors++;
                $this->markError($providerCode, $mode, $accountCode, $cursor, 'Shipping polling page budget exceeded', new \RuntimeException('page_budget_exceeded'));
                break;
            }
            if (now()->getTimestamp() - $startedAt >= $timeBudget) {
                $errors++;
                $this->markError($providerCode, $mode, $accountCode, $cursor, 'Shipping polling time budget exceeded', new \RuntimeException('time_budget_exceeded'));
                break;
            }
            $pages++;

            try {
                $page = $poller->poll($providerCode, $mode, $accountCode, $cursor);
            } catch (\Throwable $e) {
                $errors++;
                $this->markError($providerCode, $mode, $accountCode, $cursor, 'Shipping polling page failed', $e);
                break;
            }

            $events = is_array($page->events ?? null) ? $page->events : [];
            $nextCursor = (is_string($page->nextCursor ?? null) || $page->nextCursor === null) ? $page->nextCursor : $cursor;

            try {
                $this->assertCanonicalEvents($events, $providerCode, $mode, $accountCode);
            } catch (\Throwable $e) {
                $errors++;
                $this->markError($providerCode, $mode, $accountCode, $cursor, 'Shipping polling event identity rejected', $e);
                break;
            }

            $pageAccepted = 0;
            $pageDuplicates = 0;

            try {
                DB::transaction(function () use ($events, &$pageAccepted, &$pageDuplicates, $nextCursor, $providerCode, $mode, $accountCode): void {
                    foreach ($events as $event) {
                        $record = $this->inbox->store($event);
                        if ($record === null) {
                            $pageDuplicates++;
                        } else {
                            $pageAccepted++;
                        }
                    }

                    $this->cursorRow($providerCode, $mode, $accountCode)->forceFill([
                        'cursor' => $nextCursor,
                        'last_polled_at' => now(),
                        'last_success_at' => now(),
                        'last_error' => null,
                    ])->save();
                });
            } catch (\Throwable $e) {
                $errors++;
                // Cursor must not advance past non-durable acceptance.
                $this->markError($providerCode, $mode, $accountCode, $cursor, 'Shipping polling storage failed', $e);
                break;
            }

            $accepted += $pageAccepted;
            $duplicates += $pageDuplicates;

            $previousCursor = $cursor;
            $cursor = $nextCursor;
            $hasMore = (bool) ($page->hasMore ?? false);
            if ($hasMore && ($nextCursor === null || $nextCursor === $previousCursor)) {
                // Defensive guard: non-advancing pagination would spin.
                $errors++;
                $this->markError($providerCode, $mode, $accountCode, $cursor, 'Shipping polling pagination did not advance', new \RuntimeException('non_advancing_cursor'));
                break;
            }
        }

        return new ShippingPollingResult($accepted, $duplicates, $errors, $this->cursorValue($providerCode, $mode, $accountCode));
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
