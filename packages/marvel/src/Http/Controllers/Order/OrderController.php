<?php

namespace Marvel\Http\Controllers\Order;

use App\Services\General\OrderService;
use Illuminate\Http\Request;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Promotion;
use Marvel\Enums\Permission;
use Marvel\Http\Controllers\CoreController;
use Marvel\Http\Requests\OrderStatusUpdateRequest;
use Marvel\Http\Resources\Order\OrderCollection;
use Marvel\Http\Resources\Order\OrderResource;
use Marvel\Traits\ApiResponse;

class OrderController extends CoreController
{
    use ApiResponse;

    public function __construct(private OrderService $orderService)
    {
        $this->middleware('permission:'.Permission::VIEW_ORDERS)->only(['index']);
        $this->middleware('permission:'.Permission::VIEW_ORDER)->only(['show']);
        $this->middleware('permission:'.Permission::UPDATE_ORDER_STATUS)->only(['updateStatus']);
    }

    public function index(Request $request)
    {
        $limit = $this->getLimit($request);

        $orders = Order::query()
            ->with($this->relations())
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('user_id'), fn($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('user_email'), fn($q) => $q->where('user_email', 'like', "%{$request->user_email}%"))
            ->when($request->filled('promotion_id'), fn($q) => $q->where('promotion_id', $request->promotion_id))
            ->when($request->filled('promotion_name'), function ($q) use ($request) {
                $q->whereIn('promotion_code', Promotion::query()
                    ->where('name', 'like', "%{$request->promotion_name}%")
                    ->select('code'));
            })
            ->when($request->filled('product_id'), fn($q) => $q->whereHas('orderItems', fn($i) => $i->where('product_id', $request->product_id)))
            ->when($request->filled('product_name'), fn($q) => $q->whereHas('orderItems.product', fn($p) => $p->where('name', 'like', "%{$request->product_name}%")))
            ->when($request->filled('flash_sale_name'), function ($q) use ($request) {
                $q->whereHas('orderItems.product.flash_sales', fn($f) => $f->where('title', 'like', "%{$request->flash_sale_name}%"));
            })
            ->when($request->filled('shipping_method'), fn($q) => $q->where('shipping_method', $request->shipping_method))
            ->when($request->filled('created_from'), fn($q) => $q->whereDate('created_at', '>=', $request->created_from))
            ->when($request->filled('created_to'), fn($q) => $q->whereDate('created_at', '<=', $request->created_to))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;
                $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('user_email', 'like', "%{$search}%")
                        ->orWhere('user_phone', 'like', "%{$search}%");
                });
            })
            ->paginate($limit)
            ->withQueryString();

        return $this->apiResponse(
            FETCH_DATA_SUCCESSFULLY,
            200,
            true,
            new OrderCollection($orders)
        );
    }

    public function show(Request $request, string $param)
    {

        $order = Order::query()
            ->with($this->relations())
            ->findOrFail($param);

        return $this->apiResponse(
            FETCH_DATA_SUCCESSFULLY,
            200,
            true,
            new OrderResource($order)
        );
    }

    public function updateStatus(OrderStatusUpdateRequest $request, string $param)
    {
        $order = Order::query()->find($param);

        if (!$order) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }

        try {
            $order = $this->orderService->changeOrderStatus(null, $request->status, $order->id);
        } catch (\RuntimeException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        }

        if (!$order) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }

        return $this->apiResponse(
            ORDER_STATUS_UPDATED_SUCCESSFULLY,
            200,
            true,
            new OrderResource($order->load($this->relations()))
        );
    }

    private function relations(): array
    {
        return [
            'user',
            'orderItems.product',
            'orderItems.productVariant.attributeProducts.attributeValue',
            'transactions',
            'pickupLocation',
        ];
    }

    private function getLimit(Request $request): int
    {
        $limit = (int) $request->get('limit', 15);

        if ($limit <= 0) {
            return 15;
        }

        return min($limit, 100);
    }
}
