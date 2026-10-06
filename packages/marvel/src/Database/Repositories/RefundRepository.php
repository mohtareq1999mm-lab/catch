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

    public function storeRefund($request)
    {
        $user = $request->user();
        $refunds = $this->where('order_id', $request->order_id)->get();
        if (count($refunds)) {
            throw new MarvelException(ORDER_ALREADY_HAS_REFUND_REQUEST);
        }
        try {
            $order = Order::findOrFail($request->order_id);
            if ($order->parent !== null) {
                throw new MarvelException(REFUND_ONLY_ALLOWED_FOR_MAIN_ORDER);
            }
        } catch (Exception $th) {
            throw new MarvelException(NOT_FOUND);
        }
        if ($user->id !== $order->customer_id && !$user->hasRole(Role::SUPER_ADMIN)) {
            throw new MarvelException(NOT_AUTHORIZED);
        }
        $data = $request->only($this->dataArray);
        $data['customer_id'] = $order->customer_id;
        $data['amount'] = $order->amount;
        $refund = $this->create($data);
        $this->createChildOrderRefund($order->children, $data);
        return $this->find($refund->id);
    }

    public function createChildOrderRefund($orders, $data)
    {
        try {
            foreach ($orders as  $order) {
                $data['order_id'] = $order->id;
                $data['customer_id'] = $order->customer_id;
                $data['shop_id'] = $order->shop_id;
                $data['amount'] = $order->amount;
                $this->create($data);
            }
        } catch (Exception $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    public function updateRefund($request, $refund)
    {
        if ($refund->shop_id !==  null) {
            throw new MarvelException(WRONG_REFUND);
        }

        // D7 — delivered digital entitlements are NOT refundable.
        $hasDeliveredDigital = \App\Models\DigitalEntitlement::query()
            ->where('order_id', $refund->order_id)
            ->where('status', \App\Models\DigitalEntitlement::STATUS_DELIVERED)
            ->exists();

        if ($hasDeliveredDigital && isset($request->status) && $request->status == RefundStatus::APPROVED) {
            throw new MarvelException(__('message.ERROR.DIGITAL_NOT_REFUNDABLE_AFTER_DELIVERY'));
        }

        $data = $request->only(['status']);
        $refund->update($data);
        $this->changeShopSpecificRefundStatus($refund->order_id, $data);

        if ($refund['status'] == RefundStatus::APPROVED) {
            // P3-3 (D5): payment-only, matching the modern refund service
            // (PaymentRefundService marks payment_status and never touches
            // the lifecycle). The legacy `order_status` write is frozen:
            // 'refunded' has no flow meaning, and writing it would corrupt
            // the status mirror. payment_status is the payment domain's own
            // marker and stays in sync for parent and children alike.
            $orderData['payment_status'] = PaymentStatus::REFUNDED;
            $this->markOrderPaymentRefunded($refund->order_id, $orderData);
        }
        return $refund;
    }

    private function changeShopSpecificRefundStatus($order_id, $data)
    {
        $order = Order::with('children')->findOrFail($order_id);

        $childOrderIds = array_map(function ($childOrder) {
            return $childOrder['id'];
        }, $order->children->toArray());

        $this->whereIn('order_id',  $childOrderIds)->update($data);
    }

    /**
     * Payment-marker sync for refund approval (parent + children). It never
     * touches lifecycle columns (`status`, `current_status_id`,
     * `order_status`) — refunds are a payment-domain concern and the
     * lifecycle stays exactly where the flow left it.
     */
    private function markOrderPaymentRefunded($parentOrderId, array $data)
    {
        $parentOrder = Order::findOrFail($parentOrderId);
        $parentOrder->update($data);
        Order::where('parent_id', $parentOrder->id)->update($data);
    }
}
