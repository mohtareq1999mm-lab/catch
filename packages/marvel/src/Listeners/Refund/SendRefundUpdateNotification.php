<?php

namespace Marvel\Listeners\Refund;

use Illuminate\Contracts\Queue\ShouldQueue;
use Marvel\Enums\EventType;
use Marvel\Events\RefundUpdate;
use Marvel\Traits\OrderSmsTrait;
use Marvel\Traits\SmsTrait;

class SendRefundUpdateNotification implements ShouldQueue
{
    public function viaQueue(): string
    {
        return \App\Enums\QueueName::medium();
    }

    use SmsTrait, OrderSmsTrait;

    /**
     * Handle the event.
     * @param RefundUpdate $event
     * @return void
     */
    public function handle(RefundUpdate $event)
    {
        $refund = $event->refund;
        // Phase 10 unification: same null-safety as the request fanout —
        // modern orders carry no language column, refunds key by user_id.
        $order = $refund->order;

        if (!$order) {
            return;
        }

        if ($order->parent_id) return;
        $language = $order->language ?? (defined('DEFAULT_LANGUAGE') ? DEFAULT_LANGUAGE : 'en');
        $emailReceiver = $this->getWhichUserWillGetEmail(EventType::ORDER_REFUND, $language);

        $customer = $refund->customer
            ?? \Marvel\Database\Models\User::query()->whereKey($refund->user_id)->first();

        if ($emailReceiver['customer'] && $customer) {
            // NOTE: the notification class (not the same-named event).
            $customer->notify(new \Marvel\Notifications\RefundUpdate($refund, 'customer'));
        }

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
                $admin->notify(new \Marvel\Notifications\RefundUpdate($refund, 'admin'));
            }
        }
    }
}
