<?php

namespace Marvel\Listeners;


use Illuminate\Contracts\Queue\ShouldQueue;
use Marvel\Enums\EventType;
use Marvel\Events\RefundRequested;
use Marvel\Traits\OrderSmsTrait;
use Marvel\Traits\SmsTrait;


class SendRefundRequestedNotification implements ShouldQueue
{
    public function viaQueue(): string
    {
        return \App\Enums\QueueName::medium();
    }

    use SmsTrait, OrderSmsTrait;

    /**
     * Handle the event.
     *
     * @param RefundRequested $event
     * @return void
     */
    public function handle(RefundRequested $event)
    {
        $refund = $event->refund;
        // Phase 10 unification: the modern orders table carries no language
        // column and refunds key the customer by user_id. Guard both so the
        // request mail/SMS fanout stays alive instead of fataling on nulls.
        $order = $refund->order;

        if (!$order) {
            return;
        }

        $customer = $refund->customer
            ?? \Marvel\Database\Models\User::query()->whereKey($refund->user_id)->first();

        $language = $order->language ?? (defined('DEFAULT_LANGUAGE') ? DEFAULT_LANGUAGE : 'en');
        $emailReceiver = $this->getWhichUserWillGetEmail(EventType::ORDER_REFUND, $language);
        if ($emailReceiver['admin']) {
            // Phase 10 unification: no super_admin role seeded (fresh/test
            // environments) means no admin recipients — skip, never fatal.
            try {
                $admins = $this->adminList();
            } catch (\Throwable $e) {
                $admins = collect();
            }
            foreach ($admins as $admin) {
                // NOTE: the notification class (not the same-named event).
                $admin->notify(new \Marvel\Notifications\RefundRequested($refund, 'admin'));
            }
        }
        if ($emailReceiver['customer'] && $customer) {
            $customer->notify(new \Marvel\Notifications\RefundRequested($refund, 'customer'));
        }
        $this->sendRefundRequestedSms($refund);
    }
}
