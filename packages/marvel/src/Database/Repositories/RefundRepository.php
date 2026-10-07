<?php


namespace Marvel\Database\Repositories;

use Exception;
use Marvel\Database\Models\Address;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Refund;
use Marvel\Enums\PaymentStatus;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Enums\RefundStatus;
use Marvel\Exceptions\MarvelException;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;

class RefundRepository extends BaseRepository
{
    protected $fieldSearchable = [
        'title',
        'order_id',
        'description',
        'refund_policy_id',
        'refund_policy.slug',
        'refund_reason.slug',
    ];

    protected $dataArray = [
        'order_id',
        'images',
        'title',
        'description',
        'refund_policy_id',
        'refund_reason_id'
    ];
    /**
     * Configure the Model
     **/
    public function model()
    {
        return Refund::class;
    }

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
        }
    }

    /**
     * Phase 10 unification: refund writes live in App\Services\Refund\
     * RefundService (the single canonical write path). The legacy
     * store/update helpers that lived here were removed:
     * - storeRefund/createChildOrderRefund wrote unknown columns
     *   (customer_id/shop_id/images are not refund columns), enforced a
     *   one-request-per-order rule incompatible with partial refunds, and
     *   fanned out to child orders.
     * - updateRefund/changeShopSpecificRefundStatus/markOrderPaymentRefunded
     *   falsely marked payment_status REFUNDED with no provider movement.
     * This repository keeps query/scope duties (fetchRefunds, findOrFail,
     * hasPermission via BaseRepository) for the thin HTTP adapters.
     */
}
