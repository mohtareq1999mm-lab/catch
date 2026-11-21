<?php

namespace Tests\Unit\Guest;

use App\Enums\GuestCancellationLinkState;
use App\Services\Guest\GuestCancellationLinkService;
use App\Services\Guest\GuestOrderTokenService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Marvel\Database\Models\Order;
use Tests\TestCase;

/**
 * Cancellation-link liveness (#104): one rule decides unknown / live / used /
 * expired, and both surfaces word that state without re-reading the columns.
 */
class GuestCancellationLinkServiceTest extends TestCase
{
    use DatabaseTransactions;

    private GuestCancellationLinkService $links;

    private GuestOrderTokenService $tokens;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokens = app(GuestOrderTokenService::class);
        $this->links = app(GuestCancellationLinkService::class);
    }

    /**
     * Credential columns are deliberately not fillable (they are minted by
     * the token service), so fixtures write them through a query update.
     *
     * @param array<string, mixed> $credentialColumns
     */
    private function orderWith(array $credentialColumns): Order
    {
        $order = Order::create([
            'name' => 'Link Liveness Test',
            'user_phone' => '+201000000104',
            'address' => ['street' => '1 Test Street', 'city' => 'Test City'],
            'price' => 100,
            'total_price' => 100,
            'status' => 'pending',
        ]);

        Order::query()->whereKey($order->id)->update($credentialColumns);

        return $order->refresh();
    }

    private function liveOrder(?string $expiresAt = null): array
    {
        $raw = $this->tokens->generateToken();
        $order = $this->orderWith([
            'guest_cancellation_token_hash' => $this->tokens->hash($raw),
            'guest_cancellation_token_expires_at' => $expiresAt ?? now()->addDay(),
        ]);

        return [$raw, $order];
    }

    public function test_malformed_token_is_unknown(): void
    {
        $link = $this->links->resolve('not a token!');

        $this->assertSame(GuestCancellationLinkState::Unknown, $link->state);
        $this->assertNull($link->order);
        $this->assertFalse($link->isLive());
    }

    public function test_missing_token_is_unknown(): void
    {
        $link = $this->links->resolve('');

        $this->assertSame(GuestCancellationLinkState::Unknown, $link->state);
        $this->assertNull($link->order);
    }

    public function test_unmatched_token_is_unknown(): void
    {
        $this->liveOrder();

        $link = $this->links->resolve($this->tokens->generateToken());

        $this->assertSame(GuestCancellationLinkState::Unknown, $link->state);
        $this->assertNull($link->order);
    }

    public function test_matched_unused_unexpired_token_is_live(): void
    {
        [$raw, $order] = $this->liveOrder();

        $link = $this->links->resolve($raw);

        $this->assertSame(GuestCancellationLinkState::Live, $link->state);
        $this->assertTrue($link->isLive());
        $this->assertSame($order->id, $link->order?->id);
    }

    public function test_a_null_expiry_keeps_the_link_live(): void
    {
        $raw = $this->tokens->generateToken();
        $order = $this->orderWith([
            'guest_cancellation_token_hash' => $this->tokens->hash($raw),
        ]);

        $this->assertNull($order->guest_cancellation_token_expires_at);
        $this->assertSame(GuestCancellationLinkState::Live, $this->links->stateFor($order));
    }

    public function test_consumed_token_is_used(): void
    {
        [$raw] = $this->liveOrder();
        Order::query()->where('guest_cancellation_token_hash', $this->tokens->hash($raw))
            ->update(['guest_cancellation_token_used_at' => now()]);

        $link = $this->links->resolve($raw);

        $this->assertSame(GuestCancellationLinkState::Used, $link->state);
        $this->assertFalse($link->isLive());
        $this->assertNotNull($link->order, 'A matched dead link still exposes its order.');
    }

    public function test_past_expiry_is_expired(): void
    {
        $raw = $this->tokens->generateToken();
        $this->orderWith([
            'guest_cancellation_token_hash' => $this->tokens->hash($raw),
            'guest_cancellation_token_expires_at' => now()->subMinute(),
        ]);

        $link = $this->links->resolve($raw);

        $this->assertSame(GuestCancellationLinkState::Expired, $link->state);
        $this->assertFalse($link->isLive());
        $this->assertNotNull($link->order);
    }

    public function test_used_wins_over_expired(): void
    {
        $raw = $this->tokens->generateToken();
        $order = $this->orderWith([
            'guest_cancellation_token_hash' => $this->tokens->hash($raw),
            'guest_cancellation_token_used_at' => now()->subHour(),
            'guest_cancellation_token_expires_at' => now()->subMinute(),
        ]);

        $this->assertSame(GuestCancellationLinkState::Used, $this->links->stateFor($order));
        $this->assertSame(GuestCancellationLinkState::Used, $this->links->resolve($raw)->state);
    }

    public function test_an_order_without_a_stored_hash_is_unknown(): void
    {
        $order = $this->orderWith(['guest_cancellation_token_hash' => null]);

        $this->assertNull($order->guest_cancellation_token_hash);
        $this->assertSame(
            GuestCancellationLinkState::Unknown,
            $this->links->stateFor($order),
            'A row that never owned a link must not be reported live.'
        );
    }

    public function test_expiry_exactly_now_is_still_live(): void
    {
        // Whole-second freeze: the model stores datetimes with second
        // precision, so the comparison must not depend on microseconds.
        $this->freezeSecond();

        $order = new Order();
        $order->forceFill([
            'guest_cancellation_token_hash' => 'stored-hash',
            'guest_cancellation_token_expires_at' => now(),
        ]);

        $this->assertSame(GuestCancellationLinkState::Live, $this->links->stateFor($order));

        $this->travel(1)->seconds();

        $this->assertSame(GuestCancellationLinkState::Expired, $this->links->stateFor($order));
    }

    public function test_expiry_is_evaluated_against_the_clock(): void
    {
        $raw = $this->tokens->generateToken();
        $this->orderWith([
            'guest_cancellation_token_hash' => $this->tokens->hash($raw),
            'guest_cancellation_token_expires_at' => now()->addMinutes(10),
        ]);

        $this->assertSame(GuestCancellationLinkState::Live, $this->links->resolve($raw)->state);

        $this->travelTo(now()->addMinutes(11));

        $this->assertSame(GuestCancellationLinkState::Expired, $this->links->resolve($raw)->state);
    }
}
