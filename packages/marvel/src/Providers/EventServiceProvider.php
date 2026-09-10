<?php

namespace Marvel\Providers;

use App\Events\QuestionAnswered;
use App\Events\RefundApproved;
use App\Events\ReviewCreated;
use App\Listeners\CommissionRateUpdateListener;
use App\Listeners\RatingRemoved;
use App\Listeners\SendReviewNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Marvel\Events\CommissionRateUpdateEvent;
use Marvel\Events\FlashSaleProcessed;
use Marvel\Events\OrderCancelled;
use Marvel\Events\Maintenance;
use Marvel\Events\MessageSent;
use Marvel\Events\OrderDelivered;
use Marvel\Events\OrderProcessed;
use Marvel\Events\OrderReceived;
use Marvel\Events\OwnershipTransferStatusControl;
use Marvel\Events\StoreNoticeEvent;
use Marvel\Events\PaymentMethods;
use Marvel\Events\ProcessUserData;
use Marvel\Events\ProductReviewApproved;
use Marvel\Events\ProductReviewRejected;
use Marvel\Events\RefundRequested;
use Marvel\Events\RefundUpdate;
use Marvel\Events\ShopMaintenance;
use Marvel\Events\ProcessOwnershipTransition;
use Marvel\Listeners\SendQuestionAnsweredNotification;
use Marvel\Listeners\MessageParticipantNotification;
use Marvel\Listeners\SendMessageNotification;
use Marvel\Listeners\ShopMaintenanceListener;
use Marvel\Listeners\StoreNoticeListener;
use Marvel\Listeners\AppDataListener;
use Marvel\Listeners\CheckAndSetDefaultCard;
use Marvel\Listeners\FlashSaleProductProcess;
use Marvel\Listeners\MaintenanceNotification;
use Marvel\Listeners\OwnershipTransferStatusControlListener;
use Marvel\Listeners\ProductReviewApprovedListener;
use Marvel\Listeners\ProductReviewRejectedListener;
use Marvel\Listeners\Refund\SendRefundUpdateNotification;
use Marvel\Listeners\SendOrderCancelledNotification;
use Marvel\Listeners\SendOrderDeliveredNotification;
use Marvel\Listeners\SendOrderReceivedNotification;
use Marvel\Listeners\SendRefundRequestedNotification;
use Marvel\Listeners\StoredMessagedNotifyLogsListener;
use Marvel\Listeners\StoredStoreNoticeNotifyLogsListener;
use Marvel\Listeners\TransferredShopOwnershipNotification;

class EventServiceProvider extends ServiceProvider
{

    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        FlashSaleProcessed::class => [
            FlashSaleProductProcess::class
        ],
        Maintenance::class => [
            MaintenanceNotification::class
        ],
        MessageSent::class => [
            MessageParticipantNotification::class,
            SendMessageNotification::class,
            StoredMessagedNotifyLogsListener::class
        ],
        OrderCancelled::class => [
            SendOrderCancelledNotification::class
        ],
        OrderReceived::class => [
            SendOrderReceivedNotification::class
        ],
        OrderProcessed::class => [
            // ProductInventoryDecrement is intentionally removed:
            // Stock is already decremented synchronously in OrderRepository::deductStock().
            // This listener was redundant AND broken (relied on commented-out ->products() relationship).
        ],
        OrderDelivered::class => [
            SendOrderDeliveredNotification::class,
            \App\Listeners\SendUserOrderDeliveredNotification::class,
        ],
        OwnershipTransferStatusControl::class => [
            OwnershipTransferStatusControlListener::class
        ],

        PaymentMethods::class => [
            CheckAndSetDefaultCard::class
        ],
        ProductReviewApproved::class => [
            ProductReviewApprovedListener::class,
        ],
        ProductReviewRejected::class => [
            ProductReviewRejectedListener::class,
        ],
        ProcessUserData::class => [
            AppDataListener::class
        ],
        ProcessOwnershipTransition::class => [
            TransferredShopOwnershipNotification::class,
        ],
        QuestionAnswered::class => [
            SendQuestionAnsweredNotification::class
        ],
        RefundApproved::class => [
            RatingRemoved::class,
            \App\Listeners\RestoreInventoryOnRefund::class,
            \App\Listeners\GenerateCreditNoteOnRefund::class,
            \App\Listeners\RevokePendingDigitalEntitlements::class,
            \App\Listeners\SendUserOrderRefundedNotification::class,
        ],
        ReviewCreated::class => [
            SendReviewNotification::class
        ],
        RefundRequested::class => [
            SendRefundRequestedNotification::class
        ],
        RefundUpdate::class => [
            SendRefundUpdateNotification::class
        ],
        StoreNoticeEvent::class => [
            StoreNoticeListener::class,
            StoredStoreNoticeNotifyLogsListener::class
        ],
        Maintenance::class => [
            MaintenanceNotification::class
        ],
        CommissionRateUpdateEvent::class => [
            CommissionRateUpdateListener::class
        ],
        ShopMaintenance::class => [
            ShopMaintenanceListener::class
        ]
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        parent::boot();

        //
    }
}
