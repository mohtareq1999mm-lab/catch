<?php

namespace Tests\Feature\CouponDistribution;

use App\Console\Commands\Coupons\ConsumeCouponQueueCommand;
use App\Services\Coupon\Distribution\Consumers\PoisonMessageException;
use App\Services\Coupon\Distribution\Events\CouponDistributionEvents;
use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;
use App\Services\Coupon\Distribution\Messaging\ConsumeResult;
use App\Services\Coupon\Distribution\Observability\CouponEventLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\User;
use Tests\TestCase;

class ConsumerRetryDlqTest extends TestCase
{
    use RefreshDatabase;

    private function message(CouponEventEnvelope $envelope, int $attempt): array
    {
        return [
            'body' => $envelope->toJson(),
            'headers' => ['x-attempt' => $attempt],
            'redelivered' => $attempt > 1,
        ];
    }

    private function processOne(array $message, callable $handler): int
    {
        $command = app(ConsumeCouponQueueCommand::class);

        // Consumers update the audit row created at publish time.
        app(CouponEventLogService::class)->recordPublished(
            CouponEventEnvelope::fromJson($message['body'])
        );

        return $command->processOne($message, 'evaluation', [
            CouponDistributionEvents::USER_EVALUATE => new class($handler) {
                public function __construct(private $handler) {}
                public function handle($envelope)
                {
                    return ($this->handler)($envelope);
                }
            },
        ], app(CouponEventLogService::class));
    }

    public function test_transient_failure_retries_then_dead_letters()
    {
        $envelope = CouponEventEnvelope::create(CouponDistributionEvents::USER_EVALUATE, 1, ['recipient_id' => 1]);

        // Below budget → RETRY + event log retrying.
        $outcome = $this->processOne($this->message($envelope, 1), function () {
            throw new \RuntimeException('transient');
        });

        $this->assertSame(ConsumeResult::RETRY, $outcome);
        $this->assertDatabaseHas('coupon_event_logs', [
            'event_id' => $envelope->eventId,
            'status' => 'retrying',
        ]);

        // At budget (evaluation max = 5) → DEAD_LETTER + dead_lettered log.
        $outcome = $this->processOne($this->message($envelope, 5), function () {
            throw new \RuntimeException('still broken');
        });

        $this->assertSame(ConsumeResult::DEAD_LETTER, $outcome);
        $this->assertDatabaseHas('coupon_event_logs', [
            'event_id' => $envelope->eventId,
            'status' => 'dead_lettered',
        ]);
    }

    public function test_poison_message_dead_letters_without_retry()
    {
        $envelope = CouponEventEnvelope::create(CouponDistributionEvents::USER_EVALUATE, 1);

        $outcome = $this->processOne($this->message($envelope, 1), function () {
            throw new PoisonMessageException('no usable user ids');
        });

        $this->assertSame(ConsumeResult::DEAD_LETTER, $outcome);
        $this->assertDatabaseHas('coupon_event_logs', [
            'event_id' => $envelope->eventId,
            'status' => 'dead_lettered',
        ]);
    }

    public function test_malformed_body_dead_letters_without_handler()
    {
        $command = app(ConsumeCouponQueueCommand::class);

        $outcome = $command->processOne(
            ['body' => '{broken', 'headers' => [], 'redelivered' => false],
            'evaluation',
            [],
            app(CouponEventLogService::class)
        );

        $this->assertSame(ConsumeResult::DEAD_LETTER, $outcome);
    }

    public function test_exhaustion_marks_recipient_permanent_and_counts_failure()
    {
        $code = 'DLQ-'.Str::random(8);
        $coupon = Coupon::create([
            'code' => $code, 'slug' => Str::slug($code), 'name' => 'Dlq',
            'discount_type' => 'percentage', 'discount' => 10,
            'start_date' => now()->subDay(), 'end_date' => now()->addMonth(), 'status' => true,
        ]);
        CouponTargeting::create([
            'coupon_id' => $coupon->id, 'mode' => 'dynamic', 'require_claim' => true,
            'rule_tree' => ['type' => 'min_completed_orders', 'value' => 0],
        ]);
        $user = User::factory()->create();
        $run = \App\Models\CouponDistributionRun::query()->create([
            'coupon_id' => $coupon->id, 'trigger_type' => 'manual',
            'tree_hash' => str_repeat('a', 64), 'dedupe_key' => 'k-'.Str::random(8),
            'status' => 'running',
        ]);
        $recipient = \App\Models\CouponDistributionRecipient::query()->create([
            'run_id' => $run->id, 'coupon_id' => $coupon->id, 'user_id' => $user->id,
            'tree_hash' => str_repeat('a', 64), 'status' => 'discovered',
        ]);

        $envelope = CouponEventEnvelope::create(
            CouponDistributionEvents::USER_EVALUATE, $coupon->id,
            ['run_id' => $run->id, 'recipient_id' => $recipient->id]
        );

        $outcome = $this->processOne($this->message($envelope, 5), function () {
            throw new \RuntimeException('db gone');
        });

        $this->assertSame(ConsumeResult::DEAD_LETTER, $outcome);
        $this->assertSame('failed_permanent', $recipient->fresh()->status->value);
        $this->assertSame(1, (int) $run->fresh()->failed_count);
    }
}
