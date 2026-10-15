<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Traits\ApiResponse;

class AdminOrderTrackingController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        $this->middleware(['auth:sanctum']);
    }

    /**
     * Dashboard overview.
     */
    public function dashboard(Request $request)
    {
        $this->authorizeAdmin($request);

        $period = $request->get('period', 'today'); // today, week, month, year
        $dateRange = $this->getDateRange($period);

        return $this->apiResponse(__('message.MESSAGE.DASHBOARD_RETRIEVED_SUCCESSFULLY'), 200, true, [
            'overview' => $this->getOverviewStats($dateRange),
            'status_breakdown' => $this->getStatusBreakdown($dateRange),
            'recent_orders' => $this->getRecentOrders(10),
            'pending_actions' => $this->getPendingActions(),
            'revenue' => $this->getRevenueStats($dateRange),
        ]);
    }

    /**
     * All orders with advanced filtering.
     */
    public function listOrders(Request $request)
    {
        $this->authorizeAdmin($request);

        $query = Order::query()->with(['user', 'statusHistory' => function ($q) {
            $q->latest('changed_at')->limit(1);
        }]);

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->get('status'));
        }

        // Payment Status Filter (supports single value, comma-separated, array, and aliases)
        if ($request->filled('payment_status')) {
            $rawStatus = $request->get('payment_status');
            $statuses = is_array($rawStatus)
                ? $rawStatus
                : array_map('trim', explode(',', (string) $rawStatus));

            $expanded = [];
            foreach ($statuses as $st) {
                $expanded[] = $st;
                if ($st === 'pending' || $st === Order::PAYMENT_STATUS_PENDING) {
                    $expanded[] = 'pending';
                    $expanded[] = Order::PAYMENT_STATUS_PENDING;
                } elseif ($st === 'paid' || $st === 'success' || $st === Order::PAYMENT_STATUS_SUCCESS) {
                    $expanded[] = 'paid';
                    $expanded[] = 'success';
                    $expanded[] = Order::PAYMENT_STATUS_SUCCESS;
                } elseif ($st === 'failed' || $st === Order::PAYMENT_STATUS_FAILED) {
                    $expanded[] = 'failed';
                    $expanded[] = Order::PAYMENT_STATUS_FAILED;
                } elseif ($st === 'refunded' || $st === Order::PAYMENT_STATUS_REFUNDED) {
                    $expanded[] = 'refunded';
                    $expanded[] = Order::PAYMENT_STATUS_REFUNDED;
                }
            }
            $query->whereIn('payment_status', array_unique($expanded));
        }

        // Payment Method / Type Filter (e.g. online, cash_on_delivery, etc.)
        $paymentMethod = $request->get('payment_method') ?? $request->get('payment_type');
        if (!empty($paymentMethod)) {
            $methods = is_array($paymentMethod)
                ? $paymentMethod
                : array_map('trim', explode(',', (string) $paymentMethod));
            $query->whereIn('payment_method', $methods);
        }

        // Payment Gateway Filter (e.g. myfatoorah, stripe, etc.)
        if ($request->filled('payment_gateway')) {
            $query->where('payment_gateway', $request->get('payment_gateway'));
        }

        if ($request->filled('fulfillment_status')) {
            $query->where('fulfillment_status', $request->get('fulfillment_status'));
        }

        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('user_email', 'like', "%{$search}%")
                    ->orWhere('user_phone', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        // Absolute date filters
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->get('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->get('date_to'));
        }

        // Relative date filtering (older than X days/weeks/months)
        if ($request->filled('older_than')) {
            $cutoff = $this->parseRelativeDuration($request->get('older_than'));
            if ($cutoff !== null) {
                $query->where('created_at', '<=', $cutoff);
            }
        } elseif ($request->filled('older_than_days')) {
            $query->where('created_at', '<=', now()->subDays((int) $request->get('older_than_days')));
        } elseif ($request->filled('older_than_hours')) {
            $query->where('created_at', '<=', now()->subHours((int) $request->get('older_than_hours')));
        } elseif ($request->filled('older_than_months')) {
            $query->where('created_at', '<=', now()->subMonths((int) $request->get('older_than_months')));
        }

        // Relative date filtering (newer than X / within last X)
        if ($request->filled('newer_than')) {
            $cutoff = $this->parseRelativeDuration($request->get('newer_than'));
            if ($cutoff !== null) {
                $query->where('created_at', '>=', $cutoff);
            }
        } elseif ($request->filled('within_days')) {
            $query->where('created_at', '>=', now()->subDays((int) $request->get('within_days')));
        }

        // Sorting (whitelist)
        $allowedSort = ['created_at', 'updated_at', 'total_price', 'status', 'id'];
        $sortBy = $request->get('sort_by', 'created_at');
        $sortBy = in_array($sortBy, $allowedSort, true) ? $sortBy : 'created_at';
        $sortOrder = strtolower($request->get('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortOrder);

        $perPage = min(max((int) $request->get('per_page', 50), 1), 100);
        $orders = $query->paginate($perPage);

        return $this->apiResponse(__('message.MESSAGE.ORDERS_RETRIEVED_SUCCESSFULLY'), 200, true, $orders);
    }

    /**
     * Single order detailed tracking.
     */
    public function trackOrder(Request $request, $orderId)
    {
        $this->authorizeAdmin($request);

        $order = Order::query()->with([
            'user',
            'orderItems',
            'statusHistory.changedBy',
            'transactions',
        ])->find($orderId);

        if (!$order) {
            return $this->apiResponse(__('message.ERROR.ORDER_NOT_FOUND'), 404, false);
        }

        try {
            \App\Services\Logging\OrderTrackingLogger::logTrackingAccess($order, 'admin', $request->user()?->id);
            \App\Services\Metrics\OrderTrackingMetrics::incrementTrackingAccess('admin');
        } catch (\Throwable $e) {}

        return $this->apiResponse(__('message.MESSAGE.TRACKING_RETRIEVED_SUCCESSFULLY'), 200, true, [
            'order' => $order,
            'timeline' => $this->buildAdminTimeline($order),
            'analytics' => $this->getOrderAnalytics($order),
            'actions_available' => $this->getAvailableActions($order),
        ]);
    }

    /**
     * Orders requiring attention.
     */
    public function requiresAttention(Request $request)
    {
        $this->authorizeAdmin($request);

        $issues = [];

        // Pending payment over 24h
        $issues['payment_pending'] = Order::query()
            ->where('status', Order::ORDER_STATUS_PENDING)
            ->where('payment_status', Order::PAYMENT_STATUS_PENDING)
            ->where('created_at', '<', now()->subHours(24))
            ->count();

        // Processing orders over 48h
        $issues['processing_delayed'] = Order::query()
            ->where('status', Order::ORDER_STATUS_PROCESSING)
            ->where('created_at', '<', now()->subHours(48))
            ->count();

        // Failed payments last 7 days
        $issues['payment_failed'] = Order::query()
            ->where('payment_status', Order::PAYMENT_STATUS_FAILED)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        // P2-3: Stuck payment verification (over 30 minutes, online only)
        $issues['payment_verification_stuck'] = Order::query()
            ->where('status', Order::ORDER_STATUS_PENDING)
            ->where('payment_status', Order::PAYMENT_STATUS_PENDING)
            ->where('payment_method', 'online')
            ->where('created_at', '<', now()->subMinutes(30))
            ->where('created_at', '>', now()->subHours(24))
            ->count();

        // Total requiring attention
        $issues['total'] = array_sum($issues);

        $alerts = $this->generateAlerts($issues);

        // Log stuck payments for observability (P2-5)
        if (($issues['payment_verification_stuck'] ?? 0) > 0) {
            try {
                $stuckOrders = Order::query()
                    ->where('status', Order::ORDER_STATUS_PENDING)
                    ->where('payment_status', Order::PAYMENT_STATUS_PENDING)
                    ->where('payment_method', 'online')
                    ->where('created_at', '<', now()->subMinutes(30))
                    ->where('created_at', '>', now()->subHours(24))
                    ->limit(20)
                    ->get();

                foreach ($stuckOrders as $order) {
                    $minutesPending = now()->diffInMinutes($order->created_at);
                    \App\Services\Logging\OrderTrackingLogger::logStuckPayment($order, $minutesPending);
                }
            } catch (\Throwable $e) {
                // logging must never break the endpoint
            }
        }

        return $this->apiResponse(__('message.MESSAGE.ATTENTION_METRICS_RETRIEVED'), 200, true, array_merge($issues, ['alerts' => $alerts]));
    }

    // Helpers

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        if (!$user) {
            abort(401, 'Unauthenticated.');
        }

        try {
            if (method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('view-orders')) {
                return;
            }
            if (method_exists($user, 'can') && $user->can('view-orders')) {
                return;
            }
            // Legacy admin type — still requires explicit permission above in production,
            // but preserve for seeded super_admin without permission cache in tests.
            if (($user->type ?? null) === 'admin' || ($user->role ?? null) === 'admin') {
                // If permissions table exists and user has no permission, deny.
                if (method_exists($user, 'hasPermissionTo')) {
                    abort(403, 'Forbidden. Missing required permission: view-orders.');
                }
                return;
            }
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // If permission check itself fails (e.g. missing table), fall through to deny.
        }

        abort(403, 'Forbidden. Missing required permission: view-orders.');
    }

    private function getDateRange(string $period): array
    {
        return match ($period) {
            'today' => [now()->startOfDay(), now()->endOfDay()],
            'week' => [now()->startOfWeek(), now()->endOfWeek()],
            'month' => [now()->startOfMonth(), now()->endOfMonth()],
            'year' => [now()->startOfYear(), now()->endOfYear()],
            default => [now()->startOfDay(), now()->endOfDay()],
        };
    }

    private function getOverviewStats(array $dateRange): array
    {
        [$start, $end] = $dateRange;

        return [
            'total_orders' => Order::query()->whereBetween('created_at', [$start, $end])->count(),
            'completed_orders' => Order::query()->where('status', Order::ORDER_STATUS_COMPLETED)
                ->whereBetween('created_at', [$start, $end])
                ->count(),
            'pending_orders' => Order::query()->where('status', Order::ORDER_STATUS_PENDING)->count(),
            'cancelled_orders' => Order::query()->where('status', Order::ORDER_STATUS_CANCELLED)
                ->whereBetween('created_at', [$start, $end])
                ->count(),
            'total_revenue' => (float) Order::query()->where('payment_status', Order::PAYMENT_STATUS_SUCCESS)
                ->whereBetween('created_at', [$start, $end])
                ->sum('total_price'),
            // FINANCIAL REPORTING CONTRACT: total_revenue adds total_price
            // values that are each denominated in their own currency_code.
            // revenue_by_currency is the authoritative per-currency split.
            'revenue_by_currency' => Order::query()->where('payment_status', Order::PAYMENT_STATUS_SUCCESS)
                ->whereBetween('created_at', [$start, $end])
                ->selectRaw("COALESCE(currency_code, 'UNKNOWN') as currency_code, SUM(total_price) as total")
                ->groupBy('currency_code')
                ->orderBy('currency_code')
                ->pluck('total', 'currency_code')
                ->map(fn ($total) => round((float) $total, 2))
                ->all(),
        ];
    }

    private function getStatusBreakdown(array $dateRange): array
    {
        [$start, $end] = $dateRange;

        return Order::query()->select('status', DB::raw('count(*) as count'))
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    private function getRecentOrders(int $limit): array
    {
        return Order::query()->with('user')
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn(Order $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'customer' => $order->user->name ?? $order->name,
                'status' => $order->status,
                'total' => round((float) $order->total_price, 2),
                'created_at' => $order->created_at?->diffForHumans(),
            ])
            ->toArray();
    }

    private function getPendingActions(): array
    {
        return [
            'needs_processing' => Order::query()
                ->where('status', Order::ORDER_STATUS_PENDING)
                ->where('payment_status', Order::PAYMENT_STATUS_SUCCESS)
                ->count(),
            'needs_shipment' => Order::query()
                ->where('status', Order::ORDER_STATUS_COMPLETED)
                ->where('fulfillment_status', Order::FULFILLMENT_STATUS_PROCESSING)
                ->count(),
            'payment_verification' => Order::query()
                ->where('payment_status', Order::PAYMENT_STATUS_PENDING)
                ->where('created_at', '>', now()->subHours(24))
                ->count(),
        ];
    }

    private function getRevenueStats(array $dateRange): array
    {
        [$start, $end] = $dateRange;

        $orders = Order::query()->where('payment_status', Order::PAYMENT_STATUS_SUCCESS)
            ->whereBetween('created_at', [$start, $end])
            ->get(['total_price', 'currency_code']);

        $byCurrency = $orders
            ->groupBy(fn ($order) => $order->currency_code ?? 'UNKNOWN')
            ->map(fn ($group) => round((float) $group->sum('total_price'), 2))
            ->sortKeys()
            ->all();

        return [
            'total' => round((float) $orders->sum('total_price'), 2),
            'average' => $orders->count() > 0 ? round((float) $orders->avg('total_price'), 2) : 0,
            'count' => $orders->count(),
            // FINANCIAL REPORTING CONTRACT: `total`/`average` mix the
            // currencies listed in `currencies`. by_currency is authoritative.
            'by_currency' => $byCurrency,
            'currencies' => array_keys($byCurrency),
            'mixed_currencies' => count($byCurrency) > 1,
        ];
    }

    private function buildAdminTimeline(Order $order): array
    {
        return $order->statusHistory()
            ->with('changedBy')
            ->orderBy('changed_at', 'asc')
            ->get()
            ->map(fn($record) => [
                'id' => $record->id,
                'timestamp' => $record->changed_at->toIso8601String(),
                'timestamp_human' => $record->changed_at->diffForHumans(),
                'old_status' => $record->old_status,
                'new_status' => $record->new_status,
                'payment_status' => $record->new_payment_status,
                'fulfillment_status' => $record->new_fulfillment_status,
                'changed_by' => $record->changedBy ? [
                    'id' => $record->changedBy->id,
                    'name' => $record->changedBy->name,
                    'email' => $record->changedBy->email,
                ] : null,
                'changed_by_type' => $record->changed_by_type,
                'notes' => $record->notes,
                'metadata' => $record->metadata,
            ])
            ->toArray();
    }

    private function getOrderAnalytics(Order $order): array
    {
        $history = $order->statusHistory;
        $createdAt = $order->created_at;
        $completedAt = $order->completed_at;

        $firstHistory = $history->first();

        return [
            'total_status_changes' => $history->count(),
            'time_to_complete' => $completedAt && $createdAt
                ? trans_choice('message.MESSAGE.ANALYTICS_HOURS', $createdAt->diffInHours($completedAt), ['count' => $createdAt->diffInHours($completedAt)])
                : null,
            'time_since_creation' => $createdAt?->diffForHumans(),
            'time_in_current_status' => $firstHistory
                ? $firstHistory->changed_at->diffForHumans()
                : $createdAt?->diffForHumans(),
        ];
    }

    private function getAvailableActions(Order $order): array
    {
        return [
            'can_process' => $order->status === Order::ORDER_STATUS_PENDING && $order->payment_status === Order::PAYMENT_STATUS_SUCCESS,
            'can_ship' => $order->status === Order::ORDER_STATUS_COMPLETED && $order->fulfillment_status === Order::FULFILLMENT_STATUS_PROCESSING,
            'can_cancel' => in_array($order->status, [Order::ORDER_STATUS_PENDING, Order::ORDER_STATUS_PROCESSING], true),
            'can_refund' => $order->payment_status === Order::PAYMENT_STATUS_SUCCESS && $order->status !== Order::ORDER_STATUS_DELIVERED,
            'can_mark_delivered' => $order->fulfillment_status === Order::FULFILLMENT_STATUS_OUT_FOR_DELIVERY,
        ];
    }

    private function generateAlerts(array $issues): array
    {
        $alerts = [];

        if (($issues['payment_verification_stuck'] ?? 0) > 0) {
            $alerts[] = [
                'type' => 'warning',
                'priority' => 'medium',
                'count' => $issues['payment_verification_stuck'],
                'message' => __('message.MESSAGE.ATTENTION_VERIFICATION_STUCK', ['count' => $issues['payment_verification_stuck']]),
                'action' => __('message.MESSAGE.ATTENTION_VERIFICATION_STUCK_ACTION'),
                'link' => '/admin/orders?payment_status=payment-pending&sort_by=created_at',
            ];
        }

        if (($issues['payment_pending'] ?? 0) > 5) {
            $alerts[] = [
                'type' => 'info',
                'priority' => 'low',
                'count' => $issues['payment_pending'],
                'message' => __('message.MESSAGE.ATTENTION_PAYMENT_PENDING', ['count' => $issues['payment_pending']]),
                'action' => __('message.MESSAGE.ATTENTION_PAYMENT_PENDING_ACTION'),
                'link' => '/admin/orders?status=pending&payment_status=payment-pending',
            ];
        }

        if (($issues['processing_delayed'] ?? 0) > 0) {
            $alerts[] = [
                'type' => 'warning',
                'priority' => 'high',
                'count' => $issues['processing_delayed'],
                'message' => __('message.MESSAGE.ATTENTION_PROCESSING_DELAYED', ['count' => $issues['processing_delayed']]),
                'action' => __('message.MESSAGE.ATTENTION_PROCESSING_DELAYED_ACTION'),
                'link' => '/admin/orders?status=processing',
            ];
        }

        return $alerts;
    }

    /**
     * Parse human-readable or shorthand relative duration into a Carbon instance.
     * Supports: '2d', '3_days', '2 days', '1w', '1_week', '1m', '1_month', '2m', '1y', or plain integer (days).
     */
    private function parseRelativeDuration(string|int $duration): ?\Illuminate\Support\Carbon
    {
        $duration = trim((string) $duration);
        if ($duration === '') {
            return null;
        }

        // Plain integer -> treat as days
        if (is_numeric($duration)) {
            return now()->subDays((int) $duration);
        }

        // Regex pattern: (\d+)\s*(days?|d|hours?|h|weeks?|w|months?|m|years?|y)
        if (preg_match('/^(\d+)\s*([a-zA-Z_]+)$/', $duration, $matches)) {
            $amount = (int) $matches[1];
            $unit = strtolower(str_replace('_', '', $matches[2]));

            return match (true) {
                in_array($unit, ['h', 'hour', 'hours'], true) => now()->subHours($amount),
                in_array($unit, ['d', 'day', 'days'], true) => now()->subDays($amount),
                in_array($unit, ['w', 'week', 'weeks'], true) => now()->subWeeks($amount),
                in_array($unit, ['m', 'month', 'months'], true) => now()->subMonths($amount),
                in_array($unit, ['y', 'year', 'years'], true) => now()->subYears($amount),
                default => null,
            };
        }

        return null;
    }
}
