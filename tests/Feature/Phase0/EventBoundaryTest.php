<?php

namespace Tests\Feature\Phase0;

use App\DTOs\CheckoutTotals;
use App\Events\OrderCancelled;
use App\Events\OrderCreated;
use App\Events\OrderDelivered;
use App\Events\OrderStatusChanged;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Events\RefundApproved;
use App\Listeners\SendPaymentSucceededNotification;
use App\Listeners\SendUserPaymentSucceededNotification;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Marvel\Database\Models\Order;
use Tests\TestCase;

/**
 * Phase 0 regression battery: transaction/event boundary correctness.
 *
 * Mechanism note (verified against vendor/laravel/framework 10.30.1):
 * queued-listener $afterCommit is NOT honored by the events dispatcher's
 * queueHandler(); the supported deferral is event-level
 * ShouldDispatchAfterCommit. All five critical domain events implement it.
 */
class EventBoundaryTest extends TestCase
{
    private function orderStub(int $id = 77): Order
    {
        $order = new Order();
        $order->forceFill(['id' => $id]);

        return $order;
    }

    private function totals(): CheckoutTotals
    {
        return new CheckoutTotals(
            subtotal: 100.0,
            promotionDiscount: 0.0,
            couponDiscount: 0.0,
            finalTotal: 100.0,
        );
    }

    // ------------------------------------------------- contract pinning

    /** @test */
    public function critical_domain_events_implement_should_dispatch_after_commit()
    {
        foreach ([
            OrderCreated::class,
            PaymentSucceeded::class,
            PaymentFailed::class,
            OrderCancelled::class,
            OrderStatusChanged::class,
            RefundApproved::class,
        ] as $eventClass) {
            $this->assertContains(
                ShouldDispatchAfterCommit::class,
                class_implements($eventClass),
                "{$eventClass} must implement ShouldDispatchAfterCommit"
            );
        }
    }

    // ------------------------------------------------- P1

    /** @test */
    public function order_created_listeners_are_not_queued_when_the_transaction_rolls_back()
    {
        Queue::fake();
        $service = $this->app->make(\App\Services\Checkout\OrderCreationService::class);

        try {
            DB::transaction(function () use ($service) {
                $service->finalizeOrder($this->orderStub(), $this->totals());

                throw new \RuntimeException('force rollback after finalizeOrder');
            });
        } catch (\RuntimeException) {
            // expected
        }

        Queue::assertNothingPushed();
    }

    /** @test */
    public function order_created_listeners_are_queued_only_after_commit()
    {
        Queue::fake();
        $service = $this->app->make(\App\Services\Checkout\OrderCreationService::class);

        DB::transaction(function () use ($service) {
            $service->finalizeOrder($this->orderStub(91), $this->totals());

            // Still inside the open transaction — nothing may be queued yet.
            Queue::assertNothingPushed();
        });

        $this->assertListenerQueued(\App\Listeners\SendNewOrderNotification::class);
        $this->assertListenerQueued(\App\Listeners\SendUserOrderCreatedNotification::class);
    }

    // ------------------------------------------------- P2

    /** @test */
    public function payment_success_notification_listeners_declare_after_commit()
    {
        $this->assertTrue((new SendPaymentSucceededNotification())->afterCommit);
        $this->assertTrue((new SendUserPaymentSucceededNotification())->afterCommit);
    }

    /** @test */
    public function payment_success_inside_rolled_back_transaction_pushes_no_listener_jobs()
    {
        Queue::fake();

        try {
            DB::transaction(function () {
                event(new PaymentSucceeded($this->orderStub()));

                throw new \RuntimeException('force rollback after payment success');
            });
        } catch (\RuntimeException) {
            // expected
        }

        Queue::assertNothingPushed();
    }

    /** @test */
    public function payment_success_after_commit_pushes_all_four_listeners()
    {
        Queue::fake();

        DB::transaction(function () {
            event(new PaymentSucceeded($this->orderStub()));
        });

        foreach ([
            SendPaymentSucceededNotification::class,
            SendUserPaymentSucceededNotification::class,
            \App\Listeners\GenerateInvoiceListener::class,
            \App\Listeners\FulfillDigitalProducts::class,
        ] as $listenerClass) {
            $this->assertListenerQueued($listenerClass);
        }
    }

    /** @test */
    public function payment_failed_inside_rolled_back_transaction_pushes_nothing()
    {
        Queue::fake();

        try {
            DB::transaction(function () {
                event(new PaymentFailed($this->orderStub()));

                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        Queue::assertNothingPushed();
    }

    /** @test */
    public function order_cancelled_inside_rolled_back_transaction_pushes_nothing()
    {
        Queue::fake();

        try {
            DB::transaction(function () {
                event(new OrderCancelled($this->orderStub()));

                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        Queue::assertNothingPushed();
    }

    // ------------------------------------------------- #18

    /** @test */
    public function order_delivered_event_has_a_registered_listener()
    {
        // Phase 0 #18: the mapping previously resolved to the nonexistent
        // App\Providers\OrderDelivered because of a missing import.
        $listeners = app('events')->getListeners(OrderDelivered::class);

        $this->assertGreaterThanOrEqual(1, count($listeners));
    }

    // ------------------------------------------------- helpers

    private function assertListenerQueued(string $listenerClass): void
    {
        $root = Queue::getFacadeRoot();
        $ref = new \ReflectionClass($root);

        if (! $ref->hasMethod('pushedJobs')) {
            $this->fail('Queue fake does not expose pushedJobs()');
        }

        $pushed = $ref->getMethod('pushedJobs')->invoke($root);

        $found = collect($pushed)
            ->flatMap(fn ($jobs) => $jobs)
            ->pluck('job')
            ->filter(fn ($job) => $job instanceof \Illuminate\Events\CallQueuedListener)
            ->contains(fn ($job) => $job->class === $listenerClass);

        $this->assertTrue($found, "Expected {$listenerClass} to be queued");
    }
}
