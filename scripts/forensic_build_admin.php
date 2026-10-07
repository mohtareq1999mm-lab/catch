<?php
// Forensic builder (ADMIN fragment): runtime inventory -> admin-spec.json + admin-postman.json
// Scope: /api/v1/v1/* (double-prefix), /api/v1/admin/*, /api/v1/dashboard*,
//        /api/v1/logs*, /api/v1/settings, /api/v1/enum-types, /api/v1/broadcasting/*,
//        /api/v1/user*, webhooks/callbacks (+public track-order). sanctum/csrf-cookie excluded (non-api).
// Source of truth: implementation. Unknown stays unknown. No invented fields.
error_reporting(E_ERROR);
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$routes = Illuminate\Support\Facades\Route::getRoutes();
$inv = [];
foreach ($routes as $r) {
    $uri = $r->uri();
    if (stripos($uri, 'api') !== 0) continue;
    $methods = array_values(array_filter($r->methods(), fn($m) => $m !== 'HEAD' && $m !== 'OPTIONS'));
    $action = $r->getAction();
    $mw = array_values((array)($action['middleware'] ?? []));
    foreach ($methods as $m) {
        $inv[] = ['method' => $m, 'uri' => '/' . $uri, 'name' => $r->getName(),
            'action' => $action['controller'] ?? ($action['uses'] ?? 'Closure'), 'middleware' => $mw];
    }
}
usort($inv, fn($a, $b) => strcmp($a['method'] . ' ' . $a['uri'], $b['method'] . ' ' . $b['uri']));

function inScope($e) {
    $u = $e['uri'];
    if (strpos($u, '/api/v1/v1/') === 0) return true;
    if (strpos($u, '/api/v1/admin/') === 0) return true;
    if (strpos($u, '/api/v1/dashboard') === 0) return true;
    if (strpos($u, '/api/v1/logs') === 0) return true;
    if (strpos($u, '/api/v1/settings') === 0) return true;
    if ($u === '/api/v1/enum-types') return true;
    if (strpos($u, '/api/v1/broadcasting') === 0) return true;
    if (strpos($u, '/api/v1/user/') === 0) return true;
    if (preg_match('#/checkout/(callback|error-callback|webhooks)|/track-order#', $u)) return true;
    return false;
}
function classify($e) {
    $uri = $e['uri']; $mw = implode(' ', (array)$e['middleware']);
    if (preg_match('#/webhook|/callback|/broadcasting/#i', $uri)) return 'system';
    if (preg_match('#^/api/v1/(admin|v1/admin)/#', $uri)) return 'admin';
    if (strpos($mw, 'permission:') !== false) return 'admin';
    if (strpos($mw, 'auth:sanctum') !== false) {
        if (strpos($mw, 'throttle:admin') !== false) return 'admin';
        return 'general';
    }
    return 'general';
}
function audience($e, $g) {
    if ($g === 'system') {
        if (strpos($e['uri'], 'broadcasting') !== false) return 'admin';
        return 'system';
    }
    $mw = implode(' ', (array)$e['middleware']);
    if ($g === 'admin') return 'admin';
    if (strpos($mw, 'auth:sanctum') !== false) return 'customer';
    return 'public';
}
// ---- proven deep overlays (key: METHOD + space + URI template) ----
$over = [];
$Q = fn($name, $loc, $rules) => ['name' => $name, 'location' => $loc, 'rules' => $rules];
$R = fn($s, $d) => ['status' => $s, 'desc' => $d];
// Analytics (in-controller permission, NOT route-level)
$over['GET /api/v1/v1/admin/analytics/dashboard'] = ['perm_in_controller' => 'view-analytics',
 'fields' => [$Q('period', 'query', 'sometimes|in:1h,24h,7d,30d,90d (default 24h; invalid coerced to 24h)')],
 'resp' => [$R(200, 'Dashboard retrieved. {period + overview}'), $R(401, 'Unauthenticated'), $R(403, 'Missing required permission: view-analytics'), $R(429, 'throttle:admin')],
 'purpose' => 'Admin analytics overview for period; served via OrderAnalyticsService::getDashboardOverview (Cache::remember).',
 'biz' => ['service' => 'OrderAnalyticsService::getDashboardOverview', 'cache' => 'Cache::remember dashboard key; cleared only by POST clear-cache', 'audit' => 'none proven (no activity log write)'],
 'ev' => ['packages/marvel/src/Rest/Routes.php', 'app/Http/Controllers/Api/Admin/AnalyticsController.php@dashboard', 'app/Services/Analytics/OrderAnalyticsService.php']];
$over['GET /api/v1/v1/admin/analytics/time-series'] = ['perm_in_controller' => 'view-analytics',
 'fields' => [$Q('metric', 'query', 'required|in:orders,revenue,avg_order_value'), $Q('period', 'query', 'sometimes|in:1h,24h,7d,30d,90d (default 7d)'), $Q('granularity', 'query', 'sometimes|in:hour,day,week,month (default day)')],
 'resp' => [$R(200, 'Time series retrieved. {metric,period,granularity,series}'), $R(401, 'Unauthenticated'), $R(403, 'Missing required permission: view-analytics'), $R(422, 'raw {field:[msg]} (metric invalid)'), $R(429, 'throttle:admin')],
 'purpose' => 'Metric time-series for charts. NOTE: date_from/date_to/group_by NOT implemented (only period+granularity).',
 'biz' => ['service' => 'OrderAnalyticsService::getTimeSeries', 'cache' => 'Cache::remember per metric/period/granularity'],
 'ev' => ['packages/marvel/src/Rest/Routes.php', 'app/Http/Controllers/Api/Admin/AnalyticsController.php@timeSeries']];
$over['GET /api/v1/v1/admin/analytics/top-customers'] = ['perm_in_controller' => 'view-analytics',
 'fields' => [$Q('limit', 'query', 'optional integer 1-100 (default 10)')],
 'resp' => [$R(200, 'Top customers retrieved.'), $R(401, 'Unauthenticated'), $R(403, 'Missing required permission: view-analytics'), $R(429, 'throttle:admin')],
 'purpose' => 'Top customers by spend (limit-capped).',
 'biz' => ['service' => 'OrderAnalyticsService::getTopCustomers'],
 'ev' => ['packages/marvel/src/Rest/Routes.php', 'app/Http/Controllers/Api/Admin/AnalyticsController.php@topCustomers']];
$over['GET /api/v1/v1/admin/analytics/customer-segmentation'] = ['perm_in_controller' => 'view-analytics',
 'fields' => [],
 'resp' => [$R(200, 'Segmentation retrieved.'), $R(401, 'Unauthenticated'), $R(403, 'Missing required permission: view-analytics'), $R(429, 'throttle:admin')],
 'purpose' => 'Customer segmentation snapshot. No query params implemented.',
 'biz' => ['service' => 'OrderAnalyticsService::getCustomerSegmentation', 'cache' => 'Cache::remember'],
 'ev' => ['packages/marvel/src/Rest/Routes.php', 'app/Http/Controllers/Api/Admin/AnalyticsController.php@customerSegmentation']];
$over['GET /api/v1/v1/admin/analytics/performance'] = ['perm_in_controller' => 'view-analytics',
 'fields' => [$Q('period', 'query', 'optional in:1h,24h,7d,30d,90d (default 24h)')],
 'resp' => [$R(200, 'Performance retrieved. {period,sla_compliance,bottlenecks} (empty arrays when order_performance_metrics view missing)'), $R(401, 'Unauthenticated'), $R(403, 'Missing required permission: view-analytics'), $R(429, 'throttle:admin')],
 'purpose' => 'SLA compliance + stuck-order bottlenecks (non-delivered/cancelled older than 24h).',
 'biz' => ['tables' => 'order_performance_metrics view (optional), orders', 'note' => 'avg_hours_stuck hardcoded 0 (proven stub)'],
 'ev' => ['packages/marvel/src/Rest/Routes.php', 'app/Http/Controllers/Api/Admin/AnalyticsController.php@performance']];
$over['POST /api/v1/v1/admin/analytics/clear-cache'] = ['perm_in_controller' => 'view-analytics',
 'fields' => [],
 'resp' => [$R(200, 'Analytics cache cleared'), $R(401, 'Unauthenticated'), $R(403, 'Missing required permission: view-analytics'), $R(429, 'throttle:admin')],
 'purpose' => 'Clear analytics cache. SIDE EFFECT: OrderAnalyticsService::clearCache calls Cache::flush() when store supports it (broad — clears ALL cache, not just analytics).',
 'biz' => ['side_effect' => 'Cache::flush() (broad) else forget known keys'],
 'ev' => ['packages/marvel/src/Rest/Routes.php', 'app/Http/Controllers/Api/Admin/AnalyticsController.php@clearCache', 'app/Services/Analytics/OrderAnalyticsService.php::clearCache']];
// Exports are SYNC downloads — no async jobs (gap vs import/export status/cancel/download pattern)
$over['POST /api/v1/v1/admin/analytics/export/orders'] = ['perm_in_controller' => 'export-analytics',
 'fields' => [$Q('date_from', 'body', 'required|date'), $Q('date_to', 'body', 'required|date|after:date_from'), $Q('status', 'body', 'nullable|string')],
 'resp' => [$R(200, 'CSV file download (deleted server-side after send)'), $R(401, 'Unauthenticated'), $R(403, 'Missing required permission: export-analytics'), $R(422, 'raw validation'), $R(429, 'throttle:admin')],
 'purpose' => 'SYNC CSV export of orders (NOT async: no job, no status/cancel/download endpoints). Writes storage/app/exports/*.csv then response()->download()->deleteFileAfterSend(true).',
 'biz' => ['service' => 'AnalyticsExportService::exportOrders', 'async' => 'NONE proven (gap)'],
 'ev' => ['packages/marvel/src/Rest/Routes.php', 'app/Http/Controllers/Api/Admin/AnalyticsExportController.php@exportOrders', 'app/Services/Analytics/AnalyticsExportService.php']];
$over['POST /api/v1/v1/admin/analytics/export/customer-ltv'] = ['perm_in_controller' => 'export-analytics',
 'fields' => [],
 'resp' => [$R(200, 'CSV file download (deleted server-side after send)'), $R(401, 'Unauthenticated'), $R(403, 'Missing required permission: export-analytics'), $R(429, 'throttle:admin')],
 'purpose' => 'SYNC CSV export of customer LTV (NOT async). No date params implemented.',
 'biz' => ['service' => 'AnalyticsExportService::exportCustomerLTV', 'async' => 'NONE proven (gap)'],
 'ev' => ['packages/marvel/src/Rest/Routes.php', 'app/Http/Controllers/Api/Admin/AnalyticsExportController.php@exportCustomerLTV']];
$over['POST /api/v1/v1/admin/analytics/export/performance'] = ['perm_in_controller' => 'export-analytics',
 'fields' => [$Q('date_from', 'body', 'required|date'), $Q('date_to', 'body', 'required|date|after:date_from')],
 'resp' => [$R(200, 'CSV file download (deleted server-side after send)'), $R(401, 'Unauthenticated'), $R(403, 'Missing required permission: export-analytics'), $R(422, 'raw validation'), $R(429, 'throttle:admin')],
 'purpose' => 'SYNC CSV export of performance metrics (NOT async).',
 'biz' => ['service' => 'AnalyticsExportService::exportPerformanceMetrics', 'async' => 'NONE proven (gap)'],
 'ev' => ['packages/marvel/src/Rest/Routes.php', 'app/Http/Controllers/Api/Admin/AnalyticsExportController.php@exportPerformance']];
// WMS batches (route permission + warehouse scope in-controller)
$over['GET /api/v1/v1/admin/batches'] = ['fields' => [$Q('warehouse_id', 'query', 'nullable|integer|exists:warehouses,id'), $Q('status', 'query', 'nullable|in:pending,assigned,picking,completed,cancelled'), $Q('assigned_to', 'query', 'nullable|integer|exists:users,id'), $Q('limit', 'query', 'nullable|integer|min:1|max:100 (perPage default 15)')],
 'resp' => [$R(200, 'Batches fetched (paginated BatchResource)'), $R(401, ''), $R(403, 'non-global actor without home warehouse / cross-warehouse filter'), $R(429, 'throttle:admin')],
 'purpose' => 'List batches. Non manage-warehouse actors are force-scoped to home warehouse (fail-closed).',
 'biz' => ['scope' => 'WmsAdminController::authorizeWarehouseScope; manage-warehouse sees all', 'transitions' => 'n/a (read)'],
 'ev' => ['packages/marvel/src/Rest/Routes.php', 'app/Http/Controllers/Api/Admin/Wms/BatchController.php@index', 'app/Http/Requests/Admin/Wms/ListBatchesRequest.php']];
$over['POST /api/v1/v1/admin/batches'] = ['fields' => [$Q('fulfillment_ids', 'body', 'required|array|min:1|max:100'), $Q('fulfillment_ids.*', 'body', 'integer|distinct|exists:fulfillments,id'), $Q('warehouse_id', 'body', 'nullable|integer|exists:warehouses,id'), $Q('type', 'body', 'nullable|in:wave')],
 'resp' => [$R(201, 'Batch created (BatchDetailResource)'), $R(401, ''), $R(403, 'warehouse scope'), $R(404, 'explicit warehouse missing'), $R(422, 'zero fulfillments / zero pickable items / locked-row conflicts'), $R(429, 'throttle:admin')],
 'purpose' => 'Create wave batch from fulfillments. No idempotency key by design: double POST creates two batches.',
 'biz' => ['service' => 'BatchPickingService::createBatchFromFulfillments', 'creates' => 'batch status=pending + picking tasks status=pending'],
 'ev' => ['packages/marvel/src/Rest/Routes.php', 'app/Http/Controllers/Api/Admin/Wms/BatchController.php@store', 'app/Http/Requests/Admin/Wms/CreateBatchRequest.php', 'app/Services/Fulfillment/BatchPickingService.php']];
$over['GET /api/v1/v1/admin/batches/pending-fulfillments'] = ['fields' => [$Q('warehouse_id', 'query', 'optional integer (resolves default fulfillment warehouse when absent)'), $Q('limit', 'query', 'optional 1-100 default 10')],
 'resp' => [$R(200, 'Pending fulfillments (FulfillmentResource[])'), $R(403, 'warehouse scope (anti-enumeration)'), $R(404, 'warehouse missing'), $R(422, 'no resolvable warehouse'), $R(429, 'throttle:admin')],
 'purpose' => 'Candidate fulfillments for batching (static route precedes batches/{id}; not captured by show).',
 'biz' => ['service' => 'BatchPickingService::getPendingFulfillments'],
 'ev' => ['packages/marvel/src/Rest/Routes.php', 'app/Http/Controllers/Api/Admin/Wms/BatchController.php@pendingFulfillments']];
$over['GET /api/v1/v1/admin/batches/{id}'] = ['fields' => [$Q('id', 'path', 'integer batch id')],
 'resp' => [$R(200, 'Batch detail + pickingTasks ordered by sequence'), $R(403, 'warehouse scope'), $R(404, 'batch missing'), $R(429, 'throttle:admin')],
 'purpose' => 'Batch detail read.', 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/BatchController.php@show']];
$over['GET /api/v1/v1/admin/batches/{id}/next-task'] = ['fields' => [$Q('id', 'path', 'integer batch id')],
 'resp' => [$R(200, 'Next picking task OR {message: No pending tasks} + data absent'), $R(403, 'warehouse scope'), $R(404, 'batch missing'), $R(429, 'throttle:admin')],
 'purpose' => 'Next pending picking task for scan flow.', 'biz' => ['service' => 'BatchPickingService::getNextTask'], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/BatchController.php@nextTask']];
$over['POST /api/v1/v1/admin/batches/{id}/assign'] = ['fields' => [$Q('id', 'path', 'integer batch id'), $Q('user_id', 'body', 'required|integer|exists:users,id')],
 'resp' => [$R(200, 'Batch assigned (status=assigned)'), $R(403, 'warehouse scope'), $R(404, ''), $R(409, 'live contention from service lock'), $R(422, 'batch not in pending|assigned'), $R(429, 'throttle:admin')],
 'purpose' => 'PROVEN TRANSITION: batch pending|assigned -> assigned. Terminal/wrong-state refuses 422.',
 'biz' => ['service' => 'BatchPickingService::assignBatch', 'transition' => 'pending|assigned -> assigned'],
 'ev' => ['app/Http/Controllers/Api/Admin/Wms/BatchController.php@assign', 'app/Services/Fulfillment/BatchPickingService.php']];
$over['POST /api/v1/v1/admin/batches/{id}/start'] = ['fields' => [$Q('id', 'path', 'integer batch id')],
 'resp' => [$R(200, 'Batch picking started (status=picking)'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'wrong status (service: Cannot start picking batch in status)'), $R(429, 'throttle:admin')],
 'purpose' => 'PROVEN TRANSITION: batch assigned -> picking (service-guarded; exact from-status guard inside service).',
 'biz' => ['service' => 'BatchPickingService::startPicking', 'transition' => 'assigned -> picking'],
 'ev' => ['app/Http/Controllers/Api/Admin/Wms/BatchController.php@start', 'app/Services/Fulfillment/BatchPickingService.php']];
$over['POST /api/v1/v1/admin/batches/{id}/refresh-progress'] = ['fields' => [$Q('id', 'path', 'integer batch id')],
 'resp' => [$R(200, 'Batch completed (status=completed) OR progress refreshed (idempotent no-op when not ready/already completed)'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'service refusal'), $R(429, 'throttle:admin')],
 'purpose' => 'PROVEN TRANSITION: batch -> completed when all tasks picked (stable timestamp); advances fully-picked fulfillments via FulfillmentTransition. Idempotent.',
 'biz' => ['service' => 'BatchPickingService::refreshBatchProgress', 'transition' => 'picking -> completed (when ready)'],
 'ev' => ['app/Http/Controllers/Api/Admin/Wms/BatchController.php@refreshProgress', 'app/Services/Fulfillment/BatchPickingService.php']];
$over['POST /api/v1/v1/admin/batches/{id}/cancel'] = ['fields' => [$Q('id', 'path', 'integer batch id'), $Q('reason', 'body', 'required|string|min:1|max:2000')],
 'resp' => [$R(200, 'Batch cancelled (status=cancelled)'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'terminal batch (completed|cancelled) or missing reason'), $R(429, 'throttle:admin')],
 'purpose' => 'PROVEN TRANSITION: batch -> cancelled (reason required; completed|cancelled refuse).',
 'biz' => ['service' => 'BatchPickingService::cancelBatch', 'transition' => 'non-terminal -> cancelled'],
 'ev' => ['app/Http/Controllers/Api/Admin/Wms/BatchController.php@cancel', 'app/Http/Requests/Admin/Wms/BatchCommandRequest.php']];
$over['POST /api/v1/v1/admin/batches/{id}/retry'] = ['fields' => [$Q('id', 'path', 'integer batch id'), $Q('reason', 'body', 'required|string|min:1|max:2000')],
 'resp' => [$R(200, 'Skipped tasks retried (PickingTaskResource[]) OR Nothing retryable'), $R(403, 'warehouse scope'), $R(404, ''), $R(409, 'batch already has open picking task'), $R(422, 'terminal batch / missing reason'), $R(429, 'throttle:admin')],
 'purpose' => 'PROVEN TRANSITION: skipped tasks -> pending (new retry tasks). Terminal batches refuse.',
 'biz' => ['service' => 'BatchPickingService::retrySkippedTasks', 'transition' => 'skipped -> pending (new rows)'],
 'ev' => ['app/Http/Controllers/Api/Admin/Wms/BatchController.php@retry', 'app/Services/Fulfillment/BatchPickingService.php']];
// Picking
$over['GET /api/v1/v1/admin/picking-tasks'] = ['fields' => [$Q('warehouse_id', 'query', 'nullable|integer|exists:warehouses,id'), $Q('batch_id', 'query', 'nullable|integer|exists:fulfillment_batches,id'), $Q('fulfillment_id', 'query', 'nullable|integer|exists:fulfillments,id'), $Q('status', 'query', 'nullable|in:pending,assigned,picking,picked,skipped,cancelled'), $Q('mine', 'query', 'nullable|boolean'), $Q('limit', 'query', 'nullable|integer|min:1|max:100')],
 'resp' => [$R(200, 'Picking tasks paginated'), $R(403, 'warehouse scope'), $R(429, 'throttle:admin')],
 'purpose' => 'List picking tasks (warehouse-scoped).', 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PickingController.php@index', 'app/Http/Requests/Admin/Wms/ListPickingTasksRequest.php']];
$over['GET /api/v1/v1/admin/picking-tasks/{id}'] = ['fields' => [$Q('id', 'path', 'integer task id')],
 'resp' => [$R(200, 'Picking task detail'), $R(403, 'warehouse scope (task warehouse)'), $R(404, ''), $R(429, 'throttle:admin')],
 'purpose' => 'Picking task detail.', 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PickingController.php@show']];
$over['POST /api/v1/v1/admin/picking-tasks/{id}/claim'] = ['fields' => [$Q('id', 'path', 'integer task id')],
 'resp' => [$R(200, 'Claimed (status=assigned, lease set)'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'task not in pending|assigned|picking'), $R(429, 'throttle:admin')],
 'purpose' => 'PROVEN TRANSITION: picking task pending -> assigned (lease claim; override flag supported in service).',
 'biz' => ['service' => 'PickingExecutionService::claim', 'transition' => 'pending -> assigned'],
 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PickingController.php@claim', 'app/Services/Fulfillment/PickingExecutionService.php']];
$over['POST /api/v1/v1/admin/picking-tasks/{id}/release'] = ['fields' => [$Q('id', 'path', 'integer task id')],
 'resp' => [$R(200, 'Claim released'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'task not in pending|assigned|picking'), $R(429, 'throttle:admin')],
 'purpose' => 'Release claim (returns task to pool). Same status gate as claim.',
 'biz' => ['service' => 'PickingExecutionService::releaseClaim'], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PickingController.php@releaseClaim']];
$over['POST /api/v1/v1/admin/picking-tasks/{id}/confirm'] = ['fields' => [$Q('id', 'path', 'integer task id'), $Q('location', 'body', 'required|string|max:255 (scan)'), $Q('product', 'body', 'required|string|max:255 (scan)'), $Q('quantity', 'body', 'required|numeric|gt:0'), $Q('op_seq', 'body', 'nullable|integer|min:0'), $Q('override', 'body', 'sometimes|boolean')],
 'resp' => [$R(200, 'Scan confirmed (progress recorded)'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'scan mismatch / wrong status'), $R(429, 'throttle:admin')],
 'purpose' => 'Scan-flow confirm (ConfirmPickRequest). Feeds refresh-progress completion authority.',
 'biz' => ['service' => 'PickingExecutionService::confirm'], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PickingController.php@confirm', 'app/Http/Requests/Admin/Wms/ConfirmPickRequest.php']];
$over['POST /api/v1/v1/admin/picking-tasks/{id}/record-pick'] = ['fields' => [$Q('id', 'path', 'integer task id'), $Q('quantity', 'body', 'required|numeric|gt:0'), $Q('notes', 'body', 'nullable|string|max:2000'), $Q('override', 'body', 'sometimes|boolean')],
 'resp' => [$R(200, 'Pick recorded (status=picking until qty met, then picked)'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'task not in assigned|picking; qty must be > 0'), $R(429, 'throttle:admin')],
 'purpose' => 'PROVEN TRANSITION: picking task assigned|picking -> picking (partial) | picked (qty met).',
 'biz' => ['service' => 'BatchPickingService::recordPick', 'transition' => 'assigned|picking -> picking|picked'],
 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PickingController.php@recordPick', 'app/Http/Requests/Admin/Wms/RecordPickRequest.php', 'app/Services/Fulfillment/BatchPickingService.php']];
$over['POST /api/v1/v1/admin/picking-tasks/{id}/skip'] = ['fields' => [$Q('id', 'path', 'integer task id'), $Q('reason', 'body', 'required|string|min:1|max:2000')],
 'resp' => [$R(200, 'Skipped (status=skipped)'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'task not in pending|assigned|picking'), $R(429, 'throttle:admin')],
 'purpose' => 'PROVEN TRANSITION: picking task pending|assigned|picking -> skipped (reason required).',
 'biz' => ['service' => 'BatchPickingService::skipTask', 'transition' => 'pending|assigned|picking -> skipped'],
 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PickingController.php@skip', 'app/Http/Requests/Admin/Wms/SkipTaskRequest.php']];
$over['POST /api/v1/v1/admin/picking-tasks/{id}/reallocate'] = ['fields' => [$Q('id', 'path', 'integer task id'), $Q('product_location_id', 'body', 'required|integer|exists:product_locations,id')],
 'resp' => [$R(200, 'Reallocated to new placement'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'service refusal'), $R(429, 'throttle:admin')],
 'purpose' => 'Move task to another product placement.',
 'biz' => ['service' => 'PickingExecutionService::reallocateTask'], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PickingController.php@reallocate', 'app/Http/Requests/Admin/Wms/ReallocateTaskRequest.php']];
$over['POST /api/v1/v1/admin/fulfillments/{id}/create-tasks'] = ['fields' => [$Q('id', 'path', 'integer fulfillment id')],
 'resp' => [$R(200, 'Picking tasks created'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'service refusal'), $R(429, 'throttle:admin')],
 'purpose' => 'Generate picking tasks for a fulfillment (permission picking-execute + manage-level scope).',
 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PickingController.php@createTasks']];
$over['POST /api/v1/v1/admin/fulfillments/{id}/complete-picking'] = ['fields' => [$Q('id', 'path', 'integer fulfillment id')],
 'resp' => [$R(200, 'Fulfillment picking completed (-> picked via transition owner)'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'incomplete picks / illegal transition'), $R(429, 'throttle:admin')],
 'purpose' => 'PROVEN TRANSITION: fulfillment picking -> picked via FulfillmentTransition (OrderPickingService::completePicking).',
 'biz' => ['service' => 'OrderPickingService::completePicking + FulfillmentTransition', 'transition' => 'picking -> picked'],
 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PickingController.php@completePicking', 'app/Services/Fulfillment/OrderPickingService.php', 'app/Services/Fulfillment/FulfillmentTransition.php']];
$over['POST /api/v1/v1/admin/fulfillment-items/{id}/assign-placement'] = ['fields' => [$Q('id', 'path', 'integer fulfillment-item id'), $Q('product_location_id', 'body', 'required|integer|exists:product_locations,id')],
 'resp' => [$R(200, 'Placement assigned'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'service refusal'), $R(429, 'throttle:admin')],
 'purpose' => 'Assign stock placement to a fulfillment item.',
 'biz' => ['service' => 'FulfillmentService::assignPlacement'], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PickingController.php@assignPlacement', 'app/Http/Requests/Admin/Wms/AssignPlacementRequest.php']];
// Packing + packages + stations
$over['POST /api/v1/v1/admin/fulfillments/{id}/create-packing-task'] = ['fields' => [$Q('id', 'path', 'integer fulfillment id')],
 'resp' => [$R(200, 'Packing task created (status=pending; fulfillment -> packing)'), $R(403, 'warehouse scope'), $R(404, ''), $R(409, 'open packing task exists'), $R(422, 'fulfillment not in picked|packing'), $R(429, 'throttle:admin')],
 'purpose' => 'PROVEN TRANSITION: fulfillment picked|packing -> packing (task pending). Exactly-one-open-task guard (409).',
 'biz' => ['service' => 'PackingService (create) + FulfillmentTransition::transition(packing)', 'transition' => 'picked|packing -> packing'],
 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PackingController.php@createTask', 'app/Services/Fulfillment/PackingService.php']];
$over['GET /api/v1/v1/admin/packing-tasks'] = ['fields' => [$Q('warehouse_id', 'query', 'nullable|integer|min:1'), $Q('station_id', 'query', 'nullable|integer|min:1'), $Q('fulfillment_id', 'query', 'nullable|integer|min:1'), $Q('status', 'query', 'nullable|in:pending,assigned,packing,packed,verified,cancelled'), $Q('assigned_to', 'query', 'nullable|integer|min:1'), $Q('limit', 'query', 'nullable|integer|min:1|max:100')],
 'resp' => [$R(200, 'Packing tasks paginated'), $R(403, 'warehouse scope'), $R(429, 'throttle:admin')],
 'purpose' => 'List packing tasks.', 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PackingController.php@index', 'app/Http/Requests/Admin/Wms/ListPackingTasksRequest.php']];
$over['GET /api/v1/v1/admin/packing-tasks/{id}'] = ['fields' => [$Q('id', 'path', 'integer task id')],
 'resp' => [$R(200, 'Packing task detail'), $R(403, 'warehouse scope'), $R(404, ''), $R(429, 'throttle:admin')],
 'purpose' => 'Packing task detail.', 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PackingController.php@show']];
$over['POST /api/v1/v1/admin/packing-tasks/{id}/assign'] = ['fields' => [$Q('id', 'path', 'integer task id'), $Q('station_id', 'body', 'required|integer|min:1')],
 'resp' => [$R(200, 'Assigned (status=assigned; station must be active)'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'task not in pending|assigned; station inactive'), $R(429, 'throttle:admin')],
 'purpose' => 'PROVEN TRANSITION: packing task pending|assigned -> assigned (station-bound).',
 'biz' => ['transition' => 'pending|assigned -> assigned'], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PackingController.php@assign', 'app/Http/Requests/Admin/Wms/AssignPackingTaskRequest.php', 'app/Services/Fulfillment/PackingService.php']];
$over['POST /api/v1/v1/admin/packing-tasks/{id}/start'] = ['fields' => [$Q('id', 'path', 'integer task id')],
 'resp' => [$R(200, 'Started (status=packing; station must be active)'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'task not assigned'), $R(429, 'throttle:admin')],
 'purpose' => 'PROVEN TRANSITION: packing task assigned -> packing.',
 'biz' => ['transition' => 'assigned -> packing'], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PackingController.php@start', 'app/Services/Fulfillment/PackingService.php']];
$over['POST /api/v1/v1/admin/packing-tasks/{id}/pack'] = ['fields' => [$Q('id', 'path', 'integer task id'), $Q('weight', 'body', 'required|numeric|gt:0'), $Q('dimensions', 'body', 'required|array|min:1'), $Q('dimensions.*', 'body', 'numeric'), $Q('materials', 'body', 'nullable|array'), $Q('notes', 'body', 'nullable|string|max:2000')],
 'resp' => [$R(200, 'Packed (status=packed)'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'task not in packing'), $R(429, 'throttle:admin')],
 'purpose' => 'PROVEN TRANSITION: packing task packing -> packed (weight+dimensions required).',
 'biz' => ['transition' => 'packing -> packed'], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PackingController.php@pack', 'app/Http/Requests/Admin/Wms/CompletePackingRequest.php', 'app/Services/Fulfillment/PackingService.php']];
$over['POST /api/v1/v1/admin/packing-tasks/{id}/verify'] = ['fields' => [$Q('id', 'path', 'integer task id'), $Q('notes', 'body', 'nullable|string|max:2000')],
 'resp' => [$R(200, 'Verified (status=verified; fulfillment -> ready_to_ship)'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'task not packed; open sibling tasks'), $R(429, 'throttle:admin')],
 'purpose' => 'PROVEN TRANSITION: packing task packed -> verified AND fulfillment packing -> ready_to_ship (single transition owner). Shipment creation requires verified task.',
 'biz' => ['transition' => 'packed -> verified; fulfillment packing -> ready_to_ship'], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PackingController.php@verify', 'app/Services/Fulfillment/PackingService.php::verifyPacking']];
$over['POST /api/v1/v1/admin/packing-tasks/{id}/cancel'] = ['fields' => [$Q('id', 'path', 'integer task id'), $Q('reason', 'body', 'required|string|min:1|max:2000')],
 'resp' => [$R(200, 'Packing task cancelled'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'terminal/wrong status'), $R(429, 'throttle:admin')],
 'purpose' => 'Cancel packing task (reason required).', 'biz' => ['service' => 'PackingService::cancelTask'], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PackingController.php@cancel']];
$over['GET /api/v1/v1/admin/packing-stations'] = ['fields' => [$Q('warehouse_id', 'query', 'nullable|integer|min:1'), $Q('status', 'query', 'nullable|in:active,inactive,maintenance'), $Q('limit', 'query', 'nullable|integer|min:1|max:100')],
 'resp' => [$R(200, 'Packing stations paginated'), $R(403, 'warehouse scope'), $R(429, 'throttle:admin')],
 'purpose' => 'List packing stations (stations carry warehouse_id directly).', 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PackingController.php@stations', 'app/Http/Requests/Admin/Wms/ListPackingStationsRequest.php']];
$over['GET /api/v1/v1/admin/packages'] = ['fields' => [$Q('fulfillment_id', 'query', 'required|integer|min:1'), $Q('status', 'query', 'nullable|in:open,sealed,handed_off,voided'), $Q('limit', 'query', 'nullable|integer|min:1|max:100')],
 'resp' => [$R(200, 'Packages paginated (scoped via fulfillment.warehouse_id)'), $R(403, 'warehouse scope'), $R(422, 'fulfillment_id missing'), $R(429, 'throttle:admin')],
 'purpose' => 'List packages (fulfillment_id required).', 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PackageController.php@index', 'app/Http/Requests/Admin/Wms/ListPackagesRequest.php']];
$over['POST /api/v1/v1/admin/packages'] = ['fields' => [$Q('fulfillment_id', 'body', 'required|integer|min:1'), $Q('packing_task_id', 'body', 'nullable|integer|min:1'), $Q('weight', 'body', 'nullable|numeric|gt:0'), $Q('dimensions', 'body', 'nullable|array|min:1'), $Q('notes', 'body', 'nullable|string|max:2000')],
 'resp' => [$R(201, 'Package created (status=open)'), $R(403, 'warehouse scope'), $R(404, 'fulfillment missing'), $R(422, 'validation / unverified-task guard'), $R(429, 'throttle:admin')],
 'purpose' => 'Create package (status=open).', 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PackageController.php@store', 'app/Http/Requests/Admin/Wms/CreatePackageRequest.php']];
$over['GET /api/v1/v1/admin/packages/{id}'] = ['fields' => [$Q('id', 'path', 'integer package id')],
 'resp' => [$R(200, 'Package detail'), $R(403, 'warehouse scope'), $R(404, ''), $R(429, 'throttle:admin')],
 'purpose' => 'Package detail.', 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PackageController.php@show']];
$over['POST /api/v1/v1/admin/packages/{id}/add-item'] = ['fields' => [$Q('id', 'path', 'integer package id'), $Q('fulfillment_item_id', 'body', 'required|integer|min:1'), $Q('quantity', 'body', 'required|numeric|gt:0')],
 'resp' => [$R(200, 'Item added'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'sealed/voided package or qty guard'), $R(429, 'throttle:admin')],
 'purpose' => 'Add fulfillment item to open package.', 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PackageController.php@addItem', 'app/Http/Requests/Admin/Wms/AddPackageItemRequest.php']];
$over['POST /api/v1/v1/admin/packages/{id}/seal'] = ['fields' => [$Q('id', 'path', 'integer package id'), $Q('weight', 'body', 'nullable|numeric|gt:0'), $Q('dimensions', 'body', 'nullable|array|min:1')],
 'resp' => [$R(200, 'Sealed (status=sealed)'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'not open / empty package'), $R(429, 'throttle:admin')],
 'purpose' => 'PROVEN TRANSITION: package open -> sealed.', 'biz' => ['transition' => 'open -> sealed'], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PackageController.php@seal', 'app/Http/Requests/Admin/Wms/SealPackageRequest.php']];
$over['POST /api/v1/v1/admin/packages/{id}/void'] = ['fields' => [$Q('id', 'path', 'integer package id'), $Q('reason', 'body', 'required|string|min:1|max:2000')],
 'resp' => [$R(200, 'Voided (status=voided; Package::STATUS_VOIDED excluded from open-task counts)'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'terminal state'), $R(429, 'throttle:admin')],
 'purpose' => 'PROVEN TRANSITION: package -> voided (reason required; manage-fulfillment).',
 'biz' => ['transition' => 'open|sealed -> voided'], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/PackageController.php@void']];
// Fulfillment surface
$over['GET /api/v1/v1/admin/fulfillments'] = ['fields' => [$Q('warehouse_id', 'query', 'nullable|integer|exists:warehouses,id'), $Q('order_id', 'query', 'nullable|integer|exists:orders,id'), $Q('status', 'query', 'nullable|in:pending,picking,picked,packing,ready_to_ship,shipped,delivered,cancelled'), $Q('assigned_to', 'query', 'nullable|integer|exists:users,id'), $Q('limit', 'query', 'nullable|integer|min:1|max:100')],
 'resp' => [$R(200, 'Fulfillments paginated'), $R(403, 'warehouse scope'), $R(429, 'throttle:admin')],
 'purpose' => 'List fulfillments. Status universe proven by ListFulfillmentsRequest.',
 'biz' => ['transitions' => 'Fulfillment::allowedTransitions: pending->[picking,cancelled]; picking->[picked,packing,cancelled]; picked->[packing,cancelled]; packing->[ready_to_ship,cancelled]; ready_to_ship->[shipped,cancelled]; shipped->[delivered]; delivered|cancelled terminal'],
 'ev' => ['app/Http/Controllers/Api/Admin/Wms/FulfillmentController.php@index', 'app/Http/Requests/Admin/Wms/ListFulfillmentsRequest.php', 'app/Models/Fulfillment/Fulfillment.php::allowedTransitions']];
$over['GET /api/v1/v1/admin/fulfillments/{id}'] = ['fields' => [$Q('id', 'path', 'integer fulfillment id')],
 'resp' => [$R(200, 'Fulfillment detail'), $R(403, 'warehouse scope'), $R(404, ''), $R(429, 'throttle:admin')],
 'purpose' => 'Fulfillment detail.', 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/FulfillmentController.php@show']];
$over['POST /api/v1/v1/admin/fulfillments/release'] = ['fields' => [$Q('order_id', 'body', 'required|integer'), $Q('warehouse_id', 'body', 'nullable|integer|exists:warehouses,id'), $Q('idempotency_key', 'body', 'nullable|string|max:64|not_regex:/^auto-release-order-/')],
 'resp' => [$R(200, 'Fulfillment released (status=pending)'), $R(403, 'warehouse scope'), $R(404, 'order missing'), $R(409, 'idempotency-key replay'), $R(422, 'auto-prefix key rejected / no releasable lines'), $R(429, 'throttle:admin')],
 'purpose' => 'Release fulfillment for an order (idempotent via idempotency_key; auto-release-order-* prefix reserved).',
 'biz' => ['service' => 'FulfillmentService::releaseForOrder'], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/FulfillmentController.php@release', 'app/Http/Requests/Admin/Wms/ReleaseFulfillmentRequest.php']];
$over['POST /api/v1/v1/admin/fulfillments/{id}/cancel'] = ['fields' => [$Q('id', 'path', 'integer fulfillment id'), $Q('reason', 'body', 'required|string|min:1|max:2000')],
 'resp' => [$R(200, 'Fulfillment cancelled (-> cancelled via transition owner)'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'illegal transition (e.g. shipped|delivered)'), $R(429, 'throttle:admin')],
 'purpose' => 'PROVEN TRANSITION: fulfillment -> cancelled via FulfillmentTransition (illegal from shipped|delivered).',
 'biz' => ['service' => 'FulfillmentService::cancelFulfillment', 'transition' => 'non-terminal -> cancelled'],
 'ev' => ['app/Http/Controllers/Api/Admin/Wms/FulfillmentController.php@cancel', 'app/Http/Requests/Admin/Wms/CancelFulfillmentRequest.php']];
$over['POST /api/v1/v1/admin/fulfillments/{id}/assign'] = ['fields' => [$Q('id', 'path', 'integer fulfillment id'), $Q('user_id', 'body', 'required|integer|exists:users,id')],
 'resp' => [$R(200, 'Fulfillment assigned to user'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'service refusal'), $R(429, 'throttle:admin')],
 'purpose' => 'Assign fulfillment to a user (no status change proven).',
 'biz' => ['service' => 'FulfillmentService::assignToUser'], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/FulfillmentController.php@assign', 'app/Http/Requests/Admin/Wms/AssignFulfillmentRequest.php']];
// Warehouses + locations
$over['GET /api/v1/v1/admin/warehouses'] = ['fields' => [],
 'resp' => [$R(200, 'Warehouses paginated'), $R(401, ''), $R(403, 'no permission / scope'), $R(429, 'throttle:admin')],
 'purpose' => 'List warehouses.', 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/WarehouseController.php@index']];
$over['POST /api/v1/v1/admin/warehouses'] = ['fields' => [$Q('code', 'body', 'required|string|max:50|unique:warehouses,code'), $Q('name', 'body', 'required|string|max:255'), $Q('address', 'body', 'nullable|string'), $Q('city', 'body', 'nullable|string|max:100'), $Q('country', 'body', 'nullable|string|max:100'), $Q('status', 'body', 'sometimes|in:active,inactive'), $Q('is_default', 'body', 'sometimes|boolean'), $Q('metadata', 'body', 'nullable|array')],
 'resp' => [$R(201, 'Warehouse created'), $R(401, ''), $R(403, ''), $R(422, 'raw validation'), $R(429, 'throttle:admin')],
 'purpose' => 'Create warehouse.', 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/WarehouseController.php@store', 'app/Http/Requests/Admin/Wms/CreateWarehouseRequest.php']];
$over['GET /api/v1/v1/admin/warehouses/{id}'] = ['fields' => [$Q('id', 'path', 'integer warehouse id')],
 'resp' => [$R(200, 'Warehouse detail'), $R(404, ''), $R(429, 'throttle:admin')],
 'purpose' => 'Warehouse detail.', 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/WarehouseController.php@show']];
$over['PUT /api/v1/v1/admin/warehouses/{id}'] = ['fields' => [$Q('id', 'path', 'integer warehouse id'), $Q('name', 'body', 'sometimes|required|string|max:255'), $Q('address', 'body', 'nullable|string'), $Q('city', 'body', 'nullable|string|max:100'), $Q('country', 'body', 'nullable|string|max:100'), $Q('metadata', 'body', 'nullable|array')],
 'resp' => [$R(200, 'Warehouse updated'), $R(404, ''), $R(422, 'raw validation'), $R(429, 'throttle:admin')],
 'purpose' => 'Update warehouse (code/is_default/status NOT mutable here — use set-default/activate/deactivate).', 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/WarehouseController.php@update', 'app/Http/Requests/Admin/Wms/UpdateWarehouseRequest.php']];
$over['POST /api/v1/v1/admin/warehouses/{id}/set-default'] = ['fields' => [$Q('id', 'path', 'integer warehouse id')],
 'resp' => [$R(200, 'Default warehouse switched'), $R(404, ''), $R(422, 'inactive warehouse'), $R(429, 'throttle:admin')],
 'purpose' => 'Set default fulfillment warehouse.', 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/WarehouseController.php@setDefault']];
$over['POST /api/v1/v1/admin/warehouses/{id}/activate'] = ['fields' => [$Q('id', 'path', 'integer warehouse id')],
 'resp' => [$R(200, 'Activated (status=active)'), $R(404, ''), $R(429, 'throttle:admin')],
 'purpose' => 'PROVEN TRANSITION: warehouse inactive -> active.', 'biz' => ['transition' => 'inactive -> active'], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/WarehouseController.php@activate']];
$over['POST /api/v1/v1/admin/warehouses/{id}/deactivate'] = ['fields' => [$Q('id', 'path', 'integer warehouse id')],
 'resp' => [$R(200, 'Deactivated (status=inactive)'), $R(404, ''), $R(422, 'default warehouse guard (proven pattern: default cannot deactivate — verify before relying)'), $R(429, 'throttle:admin')],
 'purpose' => 'PROVEN TRANSITION: warehouse active -> inactive.', 'biz' => ['transition' => 'active -> inactive'], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/WarehouseController.php@deactivate']];
$over['DELETE /api/v1/v1/admin/warehouses/{id}'] = ['fields' => [$Q('id', 'path', 'integer warehouse id')],
 'resp' => [$R(200, 'Warehouse deleted'), $R(404, ''), $R(422, 'default / has-stock guard'), $R(429, 'throttle:admin')],
 'purpose' => 'Delete warehouse (guarded — default/stocked warehouses refuse).', 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/WarehouseController.php@destroy']];
$over['GET /api/v1/v1/admin/locations'] = ['fields' => [],
 'resp' => [$R(200, 'Locations paginated (anti-enumeration: filtered read precedent)'), $R(403, 'warehouse scope'), $R(429, 'throttle:admin')],
 'purpose' => 'List locations.', 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/LocationController.php@index']];
$over['POST /api/v1/v1/admin/locations'] = ['fields' => [$Q('warehouse_id', 'body', 'required|integer|exists:warehouses,id'), $Q('parent_id', 'body', 'nullable|integer|exists:locations,id'), $Q('code', 'body', 'proven required-ish unique code (see CreateLocationRequest)'), $Q('name', 'body', 'required|string|max:255'), $Q('barcode', 'body', 'nullable|string|max:255'), $Q('type', 'body', 'nullable|in:<Location types union>'), $Q('status', 'body', 'sometimes|in:active,inactive'), $Q('priority', 'body', 'sometimes|integer'), $Q('metadata', 'body', 'nullable|array')],
 'resp' => [$R(201, 'Location created'), $R(403, 'warehouse scope'), $R(422, 'raw validation'), $R(429, 'throttle:admin')],
 'purpose' => 'Create location under a warehouse.', 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/LocationController.php@store', 'app/Http/Requests/Admin/Wms/CreateLocationRequest.php']];
$over['GET /api/v1/v1/admin/locations/{id}'] = ['fields' => [$Q('id', 'path', 'integer location id')],
 'resp' => [$R(200, 'Location detail'), $R(403, 'warehouse scope'), $R(404, ''), $R(429, 'throttle:admin')],
 'purpose' => 'Location detail.', 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/LocationController.php@show']];
$over['PUT /api/v1/v1/admin/locations/{id}'] = ['fields' => [$Q('id', 'path', 'integer location id'), $Q('name', 'body', 'sometimes|required|string|max:255'), $Q('barcode', 'body', 'nullable|string|max:255'), $Q('priority', 'body', 'sometimes|integer'), $Q('metadata', 'body', 'nullable|array')],
 'resp' => [$R(200, 'Location updated'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'raw validation'), $R(429, 'throttle:admin')],
 'purpose' => 'Update location.', 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/LocationController.php@update', 'app/Http/Requests/Admin/Wms/UpdateLocationRequest.php']];
$over['POST /api/v1/v1/admin/locations/{id}/activate'] = ['fields' => [$Q('id', 'path', 'integer location id')],
 'resp' => [$R(200, 'Activated'), $R(403, 'warehouse scope'), $R(404, ''), $R(429, 'throttle:admin')],
 'purpose' => 'PROVEN TRANSITION: location -> active.', 'biz' => ['transition' => 'inactive -> active'], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/LocationController.php@activate']];
$over['POST /api/v1/v1/admin/locations/{id}/deactivate'] = ['fields' => [$Q('id', 'path', 'integer location id')],
 'resp' => [$R(200, 'Deactivated'), $R(403, 'warehouse scope'), $R(404, ''), $R(429, 'throttle:admin')],
 'purpose' => 'PROVEN TRANSITION: location -> inactive. NOTE: locations have NO delete endpoint (proven absent).', 'biz' => ['transition' => 'active -> inactive'], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/LocationController.php@deactivate']];
// WMS shipments (fulfillment-scoped adapter; order-only labels 404 here)
$over['GET /api/v1/v1/admin/shipments'] = ['fields' => [$Q('fulfillment_id', 'query', 'required|integer|min:1'), $Q('status', 'query', 'nullable|string|max:50'), $Q('limit', 'query', 'nullable|integer|min:1|max:100')],
 'resp' => [$R(200, 'Shipments paginated (fulfillment-scoped; order-only labels 404 on this surface)'), $R(403, 'warehouse scope'), $R(422, 'fulfillment_id missing'), $R(429, 'throttle:admin')],
 'purpose' => 'List shipments for a fulfillment.', 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/ShipmentController.php@index', 'app/Http/Requests/Admin/Wms/ListShipmentsRequest.php']];
$over['GET /api/v1/v1/admin/shipments/{id}'] = ['fields' => [$Q('id', 'path', 'integer shipment id')],
 'resp' => [$R(200, 'Shipment detail'), $R(403, 'warehouse scope'), $R(404, 'shipment missing OR order-only label (null fulfillment)'), $R(429, 'throttle:admin')],
 'purpose' => 'Shipment detail (fulfillment-scoped).', 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/ShipmentController.php@show']];
$over['POST /api/v1/v1/admin/fulfillments/{id}/shipments'] = ['fields' => [$Q('id', 'path', 'integer fulfillment id'), $Q('courier', 'body', 'nullable|string|max:255'), $Q('shipping_method', 'body', 'nullable|string|max:255'), $Q('notes', 'body', 'nullable|string|max:2000'), $Q('destination_address', 'body', 'nullable|array'), $Q('idempotency_key', 'body', 'nullable|string|max:255')],
 'resp' => [$R(201, 'Shipment created (status=label_created)'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'fulfillment not ready_to_ship / cancelled fulfillment'), $R(429, 'throttle:admin')],
 'purpose' => 'PROVEN TRANSITION: create shipment label (status=label_created). Requires fulfillment ready_to_ship; verified packing task required upstream.',
 'biz' => ['service' => 'ShipmentService::createForFulfillment', 'transition' => '(fulfillment ready_to_ship) -> shipment label_created'],
 'ev' => ['app/Http/Controllers/Api/Admin/Wms/ShipmentController.php@store', 'app/Http/Requests/Admin/Wms/CreateShipmentRequest.php', 'app/Services/Shipment/ShipmentService.php']];
$over['POST /api/v1/v1/admin/shipments/{id}/dispatch'] = ['fields' => [$Q('id', 'path', 'integer shipment id'), $Q('notes', 'body', 'nullable|string|max:2000')],
 'resp' => [$R(200, 'Dispatched (shipment label_created -> picked_up; fulfillment ready_to_ship -> shipped)'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'shipment not label_created / fulfillment not ready_to_ship'), $R(429, 'throttle:admin')],
 'purpose' => 'PROVEN TRANSITION: shipment dispatch + fulfillment ship (inside service). Duplicate dispatch documented idempotent-ish (verify before relying).',
 'biz' => ['service' => 'ShipmentService::dispatch', 'transition' => 'label_created -> picked_up; fulfillment ready_to_ship -> shipped'],
 'ev' => ['app/Http/Controllers/Api/Admin/Wms/ShipmentController.php@dispatchShipment', 'app/Services/Shipment/ShipmentService.php']];
$over['POST /api/v1/v1/admin/shipments/{id}/deliver'] = ['fields' => [$Q('id', 'path', 'integer shipment id'), $Q('notes', 'body', 'nullable|string|max:2000')],
 'resp' => [$R(200, 'Delivered (shipment -> delivered; fulfillment shipped -> delivered)'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'shipment not in picked_up|in_transit|out_for_delivery / fulfillment not shipped; already-delivered probe path'), $R(429, 'throttle:admin')],
 'purpose' => 'PROVEN TRANSITION: shipment picked_up|in_transit|out_for_delivery -> delivered.',
 'biz' => ['service' => 'ShipmentService::markDelivered', 'transition' => 'picked_up|in_transit|out_for_delivery -> delivered'],
 'ev' => ['app/Http/Controllers/Api/Admin/Wms/ShipmentController.php@markDelivered', 'app/Services/Shipment/ShipmentService.php']];
$over['POST /api/v1/v1/admin/shipments/{id}/cancel'] = ['fields' => [$Q('id', 'path', 'integer shipment id'), $Q('reason', 'body', 'required|string|min:1|max:2000')],
 'resp' => [$R(200, 'Shipment cancelled'), $R(403, 'warehouse scope'), $R(404, ''), $R(422, 'terminal shipment'), $R(429, 'throttle:admin')],
 'purpose' => 'Cancel shipment (reason required). Exact terminal set untraced — verify controller/service before relying.',
 'biz' => [], 'ev' => ['app/Http/Controllers/Api/Admin/Wms/ShipmentController.php@cancelShipment', 'app/Http/Requests/Admin/Wms/BatchCommandRequest.php']];
$over['POST /api/v1/v1/admin/orders/{orderId}/cancel'] = ['fields' => [$Q('orderId', 'path', 'integer order id'), $Q('reason', 'body', 'required|string|min:1|max:2000')],
 'resp' => [$R(200, 'Order cancelled + open fulfillments cascaded (same transaction; fulfillment id/status list returned)'), $R(403, 'fail-closed: non-global actor on cross-warehouse / no-fulfillment order'), $R(404, 'order missing'), $R(422, 'flow-gate refusal (sealed custody, packed work, live shipment) — order untouched'), $R(429, 'throttle:admin')],
 'purpose' => 'During-fulfillment order cancellation. Sole writer OrderService::changeOrderStatus (Order Flow authority); shipped/terminal fulfillments never force-cancelled.',
 'biz' => ['service' => 'OrderService::changeOrderStatus(cancelled) + cascade', 'scope' => 'fail-closed warehouse scope'],
 'ev' => ['packages/marvel/src/Rest/Routes.php', 'app/Http/Controllers/Api/Admin/Wms/OrderCancellationController.php@cancel']];
// Admin tracking (in-controller view-orders, NOT route-level)
$over['GET /api/v1/admin/tracking/dashboard'] = ['perm_in_controller' => 'view-orders',
 'fields' => [$Q('period', 'query', 'optional today|week|month|year (default today)')],
 'resp' => [$R(200, 'Dashboard {overview,status_breakdown,recent_orders(10),pending_actions,revenue}'), $R(401, 'Unauthenticated'), $R(403, 'Missing required permission: view-orders'), $R(429, 'throttle:admin')],
 'purpose' => 'Admin tracking dashboard. Route carries NO permission: middleware (gap) — enforced in-controller.',
 'biz' => ['note' => 'computed from orders; no date_from/date_to params (only period)'], 'ev' => ['routes/api.php', 'app/Http/Controllers/Api/Admin/AdminOrderTrackingController.php@dashboard']];
$over['GET /api/v1/admin/tracking/orders'] = ['perm_in_controller' => 'view-orders',
 'fields' => [$Q('status', 'query', 'optional'), $Q('payment_status', 'query', 'optional'), $Q('fulfillment_status', 'query', 'optional'), $Q('search', 'query', 'optional (order_number,user_email,user_phone,name LIKE)'), $Q('date_from', 'query', 'optional (created_at >=)'), $Q('date_to', 'query', 'optional (created_at <=)'), $Q('sort_by', 'query', 'optional whitelist: created_at,updated_at,total_price,status,id (default created_at)'), $Q('sort_order', 'query', 'optional asc|desc (default desc)'), $Q('per_page', 'query', 'optional 1-100 default 50')],
 'resp' => [$R(200, 'Orders paginated (user + latest statusHistory eager-loaded)'), $R(401, 'Unauthenticated'), $R(403, 'Missing required permission: view-orders'), $R(429, 'throttle:admin')],
 'purpose' => 'Admin order list with filters. date_from/date_to ARE implemented here (unlike analytics).',
 'biz' => ['eager' => 'user, statusHistory(latest 1)'], 'ev' => ['routes/api.php', 'app/Http/Controllers/Api/Admin/AdminOrderTrackingController.php@listOrders']];
$over['GET /api/v1/admin/tracking/orders/{orderId}'] = ['perm_in_controller' => 'view-orders',
 'fields' => [$Q('orderId', 'path', 'integer order id (!) NOT {id}/{order})')],
 'resp' => [$R(200, 'Order + timeline + analytics + actions_available{can_process,can_ship,can_refund}'), $R(401, 'Unauthenticated'), $R(403, 'Missing required permission: view-orders'), $R(404, 'Order not found'), $R(429, 'throttle:admin')],
 'purpose' => 'Single-order tracking. SIDE EFFECTS (proven): OrderTrackingLogger::logTrackingAccess + OrderTrackingMetrics::incrementTrackingAccess (best-effort try/catch).',
 'biz' => ['audit' => 'logTrackingAccess(admin, user_id); incrementTrackingAccess(admin)', 'advisory' => 'can_process = status pending+payment success; can_ship = status completed+fulfillment processing; can_refund = paid + not delivered'],
 'ev' => ['routes/api.php', 'app/Http/Controllers/Api/Admin/AdminOrderTrackingController.php@trackOrder']];
$over['GET /api/v1/admin/tracking/requires-attention'] = ['perm_in_controller' => 'view-orders',
 'fields' => [],
 'resp' => [$R(200, '{payment_pending(pending+unpaid>24h),processing_delayed(>48h),payment_failed(7d),payment_verification_stuck(online 30m-24h),total,alerts}'), $R(401, 'Unauthenticated'), $R(403, 'Missing required permission: view-orders'), $R(429, 'throttle:admin')],
 'purpose' => 'Attention metrics with proven thresholds. SIDE EFFECT (proven): logStuckPayment per stuck order (max 20, best-effort).',
 'biz' => ['audit' => 'OrderTrackingLogger::logStuckPayment when stuck>0'], 'ev' => ['routes/api.php', 'app/Http/Controllers/Api/Admin/AdminOrderTrackingController.php@requiresAttention']];
// Admin notifications (route-level permissions)
$over['GET /api/v1/admin/notifications'] = ['fields' => [],
 'resp' => [$R(200, 'Admin notifications paginated'), $R(401, 'Unauthenticated (Broadcast-adjacent: auth required despite no auth:sanctum in group — controller/group relies on permission middleware chain; verify before relying)'), $R(403, 'missing view-notifications'), $R(429, 'throttle:api (group default)')],
 'purpose' => 'Admin notification inbox (permission:view-notifications). NOTE: group lacks auth:sanctum + throttle:admin (gap) — only permission: + lang middleware at route level.',
 'biz' => [], 'ev' => ['packages/marvel/src/Rest/Routes.php (admin group)', 'Marvel\\Http\\Controllers\\NotificationController@index']];
$over['GET /api/v1/admin/notifications/unread'] = ['fields' => [],
 'resp' => [$R(200, 'Unread admin notifications'), $R(403, 'missing view-notifications')],
 'purpose' => 'Unread admin notifications (permission:view-notifications). Same group-middleware gap as index.',
 'biz' => [], 'ev' => ['packages/marvel/src/Rest/Routes.php (admin group)']];
$over['PATCH /api/v1/admin/notifications/{id}/read'] = ['fields' => [$Q('id', 'path', 'notification id')],
 'resp' => [$R(200, 'Marked read'), $R(403, 'missing manage-notifications'), $R(404, 'notification missing')],
 'purpose' => 'Mark admin notification read (permission:manage-notifications).', 'biz' => [], 'ev' => ['packages/marvel/src/Rest/Routes.php (admin group)']];
$over['PATCH /api/v1/admin/notifications/read-all'] = ['fields' => [],
 'resp' => [$R(200, 'All marked read'), $R(403, 'missing manage-notifications')],
 'purpose' => 'Mark all admin notifications read (permission:manage-notifications). Static route precedes {id}/read.', 'biz' => [], 'ev' => ['packages/marvel/src/Rest/Routes.php (admin group)']];
$over['DELETE /api/v1/admin/notifications/{id}'] = ['fields' => [$Q('id', 'path', 'notification id')],
 'resp' => [$R(200, 'Deleted'), $R(403, 'missing manage-notifications'), $R(404, '')],
 'purpose' => 'Delete admin notification (permission:manage-notifications).', 'biz' => [], 'ev' => ['packages/marvel/src/Rest/Routes.php (admin group)']];
$over['DELETE /api/v1/admin/notifications'] = ['fields' => [],
 'resp' => [$R(200, 'All deleted'), $R(403, 'missing manage-notifications')],
 'purpose' => 'Delete all admin notifications (permission:manage-notifications).', 'biz' => [], 'ev' => ['packages/marvel/src/Rest/Routes.php (admin group)']];
// Dashboard x16 (route permission view-analytics + throttle:analytics 60/min)
foreach ([
 'overview' => ['Overview totals (completed-revenue base-currency-safe, refunds, orders, products, customers). Cache key dashboard_overview 300s (fixed — request params IGNORED).', 'App/Services/Dashboard/DashboardService.php::getOverview'],
 'revenue' => ['Revenue overview + sales-by-month (current year). Cache key dashboard_revenue 300s (fixed).', 'App/Services/Dashboard/DashboardService.php::getRevenueOverview'],
 'order-stats' => ['Order status overview. No query params implemented.', 'App/Services/Dashboard/DashboardService.php::getOrderStatusOverview'],
 'recent-orders' => ['Recent orders. Query: limit (default 10, cap 50).', 'App/Services/Dashboard/DashboardService.php::getRecentOrders'],
 'top-products' => ['Top selling products. Query: limit (default 10, cap 50).', 'App/Services/Dashboard/DashboardService.php::getTopSellingProducts'],
 'category-stats' => ['Category stats. No query params implemented.', 'App/Services/Dashboard/DashboardService.php::getCategoryStats'],
 'low-stock' => ['Low-stock products. Query: limit (default 10, cap 50).', 'App/Services/Dashboard/DashboardService.php::getLowStockProducts'],
 'sales' => ['Sales analytics. No query params implemented (date_from/date_to/group_by NOT implemented).', 'App/Services/Dashboard/DashboardService.php::getSalesAnalytics'],
 'customers' => ['Customer analytics. No query params implemented.', 'App/Services/Dashboard/DashboardService.php::getCustomerAnalytics'],
 'products' => ['Product analytics. No query params implemented.', 'App/Services/Dashboard/DashboardService.php::getProductAnalytics'],
 'orders' => ['Order analytics. No query params implemented.', 'App/Services/Dashboard/DashboardService.php::getOrderAnalytics'],
 'categories' => ['Category analytics. No query params implemented.', 'App/Services/Dashboard/DashboardService.php::getCategoryAnalytics'],
 'coupons' => ['Coupon analytics. No query params implemented.', 'App/Services/Dashboard/DashboardService.php::getCouponAnalytics'],
 'cart' => ['Cart analytics. No query params implemented.', 'App/Services/Dashboard/DashboardService.php::getCartAnalytics'],
 'finance' => ['Finance analytics. No query params implemented.', 'App/Services/Dashboard/DashboardService.php::getFinanceAnalytics'],
 'reconciliation' => ['Reconciliation summary. Takes NO request input (method signature has no $request).', 'App/Services/Dashboard/DashboardService.php::getReconciliationSummary'],
] as $seg => $info) {
    $fld = [];
    if (in_array($seg, ['recent-orders', 'top-products', 'low-stock'], true)) $fld = [$Q('limit', 'query', 'optional integer default 10 cap 50')];
    $over['GET /api/v1/dashboard/' . $seg] = ['fields' => $fld,
     'resp' => [$R(200, 'OK {success:true,message:lang key,data} (HasCache::remember per fullUrl)'), $R(401, 'Unauthenticated'), $R(403, 'missing view-analytics'), $R(429, 'throttle:analytics 60/min')],
     'purpose' => $info[0] . ' Guard: auth:sanctum + throttle:analytics + permission:view-analytics. Response cached per fullUrl (FrontendResource::DASHBOARD).',
     'biz' => ['cache' => 'HasCache::remember 300s-ish per fullUrl'], 'ev' => ['packages/marvel/src/Rest/Routes.php (dashboard group)', 'app/Http/Controllers/Api/General/DashboardController.php', $info[1]]];
}
$over['GET /api/v1/logs/activity'] = ['fields' => [$Q('log_name', 'query', 'optional'), $Q('event', 'query', 'optional'), $Q('causer_id', 'query', 'optional'), $Q('search', 'query', 'optional (description|log_name LIKE)'), $Q('per_page', 'query', 'optional default 15')],
 'resp' => [$R(200, 'Activity logs paginated {data,meta}'), $R(401, 'Unauthenticated'), $R(403, 'missing view-activity-log'), $R(429, 'throttle:admin')],
 'purpose' => 'Activity/audit log read (spatie activitylog). Controller-level permission:view-activity-log + group auth:sanctum+throttle:admin.',
 'biz' => ['table' => 'activity_log via Spatie Activity model'], 'ev' => ['packages/marvel/src/Rest/Routes.php', 'Marvel\\Http\\Controllers\\ActivityLogController@index']];
$over['GET /api/v1/settings'] = ['fields' => [],
 'resp' => [$R(200, 'Site settings'), $R(401, 'Unauthenticated'), $R(403, 'missing view-settings|update-settings'), $R(429, 'throttle:admin')],
 'purpose' => 'Read site settings (permission:view-settings|update-settings).', 'biz' => [], 'ev' => ['packages/marvel/src/Rest/Routes.php', 'Marvel\\Http\\Controllers\\SettingsController@index']];
$over['PUT /api/v1/settings'] = ['fields' => [$Q('site_name.*', 'body', 'sometimes|string|min:3|max:200'), $Q('site_desc.*', 'body', 'sometimes|string|min:3|max:2000'), $Q('logo|footer_logo|favicon', 'body', 'sometimes|image|mimes:jpeg,png,jpg,gif,svg|max:2048')],
 'resp' => [$R(200, 'Settings updated'), $R(401, 'Unauthenticated'), $R(403, 'missing update-settings'), $R(422, 'raw validation'), $R(429, 'throttle:admin')],
 'purpose' => 'Update site settings (permission:update-settings; SettingsRequest sometimes-rules; see full request for remaining keys).',
 'biz' => [], 'ev' => ['packages/marvel/src/Rest/Routes.php', 'Marvel\\Http\\Controllers\\SettingsController@update', 'Marvel\\Http\\Requests\\SettingsRequest.php']];
$over['GET /api/v1/enum-types'] = ['fields' => [],
 'resp' => [$R(200, '{discount-type,coupon-type,product-type,promotion-type,promotion-mount-type,flash-sale-type} (public closure)')],
 'purpose' => 'Public enum catalog (no auth, api middleware only).', 'biz' => [], 'ev' => ['packages/marvel/src/Rest/Routes.php (closure)']];
$over['GET /api/v1/broadcasting/auth'] = ['fields' => [],
 'resp' => [$R(200, 'Broadcast auth (pusher/ably channel auth payload)'), $R(401, 'Unauthenticated'), $R(403, 'channel auth denied')],
 'purpose' => 'Broadcasting channel auth (Broadcast::routes, auth:sanctum). System/internal — websocket handshake, not customer REST.',
 'biz' => [], 'ev' => ['packages/marvel/src/Rest/Routes.php (Broadcast::routes)', 'Marvel\\Providers broadcast wiring']];
$over['POST /api/v1/broadcasting/auth'] = ['fields' => [$Q('socket_id', 'body', 'required by pusher protocol (provider-level)') , $Q('channel_name', 'body', 'required by pusher protocol (provider-level)')],
 'resp' => [$R(200, 'Broadcast auth payload'), $R(401, 'Unauthenticated'), $R(403, 'channel auth denied')],
 'purpose' => 'Broadcasting channel auth POST (primary pusher flow). System/internal.', 'biz' => [], 'ev' => ['packages/marvel/src/Rest/Routes.php (Broadcast::routes)']];
// user/* (customer audience)
$over['GET /api/v1/user/notification-preferences'] = ['fields' => [],
 'resp' => [$R(200, 'Own notification preferences'), $R(401, 'Unauthenticated'), $R(429, 'throttle:authenticated')],
 'purpose' => 'CUSTOMER-owned: read own notification preferences (owner scope in-controller).', 'biz' => ['audience' => 'customer'], 'ev' => ['routes/api.php', 'app/Http/Controllers/Api/User/NotificationPreferencesController.php@index']];
$over['PUT /api/v1/user/notification-preferences'] = ['fields' => [$Q('(preference keys)', 'body', 'validated in-controller (see controller); unknown keys rejected — verify before relying')],
 'resp' => [$R(200, 'Preferences updated'), $R(401, 'Unauthenticated'), $R(422, 'raw validation'), $R(429, 'throttle:authenticated')],
 'purpose' => 'CUSTOMER-owned: update own notification preferences.', 'biz' => ['audience' => 'customer'], 'ev' => ['routes/api.php', 'app/Http/Controllers/Api/User/NotificationPreferencesController.php@update']];
$over['POST /api/v1/user/devices/register'] = ['fields' => [$Q('token', 'body', 'required|string|max:500 (FCM)'), $Q('platform', 'body', 'required|in:ios,android,web'), $Q('device_name|device_id|app_version|os_version', 'body', 'nullable')],
 'resp' => [$R(200, 'Device registered'), $R(401, 'Unauthenticated'), $R(422, 'raw validation'), $R(429, 'throttle:authenticated')],
 'purpose' => 'CUSTOMER-owned: register FCM device token.', 'biz' => ['audience' => 'customer'], 'ev' => ['routes/api.php', 'app/Http/Controllers/Api/User/NotificationPreferencesController.php@registerDevice']];
$over['DELETE /api/v1/user/devices/{deviceId}'] = ['fields' => [$Q('deviceId', 'path', 'integer device id')],
 'resp' => [$R(200, 'Device unregistered'), $R(401, 'Unauthenticated'), $R(404, 'not own device'), $R(429, 'throttle:authenticated')],
 'purpose' => 'CUSTOMER-owned: unregister own device.', 'biz' => ['audience' => 'customer'], 'ev' => ['routes/api.php', 'app/Http/Controllers/Api/User/NotificationPreferencesController.php@unregisterDevice']];
$over['GET /api/v1/user/notifications/history'] = ['fields' => [],
 'resp' => [$R(200, 'Own notification history'), $R(401, 'Unauthenticated'), $R(429, 'throttle:authenticated')],
 'purpose' => 'CUSTOMER-owned: own notification history.', 'biz' => ['audience' => 'customer'], 'ev' => ['routes/api.php', 'app/Http/Controllers/Api/User/NotificationPreferencesController.php@notificationHistory']];
// System: callbacks + webhooks + public tracking
$over['GET /api/v1/general/checkout/callback'] = ['fields' => [$Q('(gateway query)', 'query', 'provider paymentId/session refs — re-verified server-side via checkInvoice; never trusted blindly')],
 'resp' => [$R(302, 'gateway redirect flow (proven: browser-callback only; server re-verifies)'), $R(429, 'throttle:payment-callback 20/min')],
 'purpose' => 'SYSTEM: MyFatoorah/stripe browser success callback (public; throttle:payment-callback). Canonical completion via PaymentCompletionService::completeLocked (idempotency token, order-pending, amount x1000, currency, provider ref).',
 'biz' => ['webhook' => 'MyFatoorah has NO webhook by design (browser-callback only)'], 'ev' => ['routes/api.php', 'app/Http/Controllers/Api/General/OrderController.php@checkoutCallback']];
$over['POST /api/v1/general/checkout/callback'] = ['fields' => [],
 'resp' => [$R(200, 'callback processed (see GET twin)'), $R(429, 'throttle:payment-callback 20/min')],
 'purpose' => 'SYSTEM: checkout callback POST twin (same handler as GET).', 'biz' => [], 'ev' => ['routes/api.php', 'app/Http/Controllers/Api/General/OrderController.php@checkoutCallback']];
$over['GET /api/v1/general/checkout/error-callback'] = ['fields' => [],
 'resp' => [$R(200, 'error callback processed'), $R(429, 'throttle:payment-callback 20/min')],
 'purpose' => 'SYSTEM: gateway failure-return callback (public).', 'biz' => [], 'ev' => ['routes/api.php', 'app/Http/Controllers/Api/General/OrderController.php@checkoutErrorCallback']];
$over['POST /api/v1/general/checkout/error-callback'] = ['fields' => [],
 'resp' => [$R(200, 'error callback processed'), $R(429, 'throttle:payment-callback 20/min')],
 'purpose' => 'SYSTEM: error-callback POST twin.', 'biz' => [], 'ev' => ['routes/api.php', 'app/Http/Controllers/Api/General/OrderController.php@checkoutErrorCallback']];
$over['POST /api/v1/general/checkout/webhooks/stripe'] = ['sig' => ['Stripe-Signature' => '<stripe-generated v1= HMAC of RAW body>'],
 'fields' => [$Q('(raw JSON body)', 'body', 'Stripe Event (verified from RAW bytes via StripeWebhookVerifier against config payment.gateways.stripe.webhook_secret; never re-encoded JSON)')],
 'resp' => [$R(200, 'completed | ignored (unknown txn / unsupported type incl. charge.refunded — refunds are admin-initiated only)'), $R(400, 'unsigned/misconfigured OR invalid signature OR missing session ref'), $R(429, 'throttle:payment-webhook 20/min')],
 'purpose' => 'SYSTEM: Stripe server webhook. Signature FIRST on raw body; checkout.session.completed -> completeFromProviderRef; payment_intent.payment_failed -> markFailed (unresolvable pi acked 200 ignored). Logs carry ids only (no secrets/PII). Dedupe via _webhook_event_ids (cap 20).',
 'biz' => ['verifier' => 'StripeWebhookVerifier::construct(raw, sig, secret)', 'throttle' => 'payment-webhook 20/min/IP', 'auth' => 'NONE (public) — signature is the auth'],
 'ev' => ['routes/api.php', 'app/Http/Controllers/Api/General/PaymentWebhookController.php@stripe', 'app/Services/Payment/StripeWebhookVerifier.php']];
$over['POST /api/v1/general/checkout/webhooks/paypal'] = ['sig' => ['Paypal-Auth-Algo' => '<from PayPal transmission>', 'Paypal-Cert-Url' => '<from PayPal transmission>', 'Paypal-Transmission-Id' => '<from PayPal transmission>', 'Paypal-Transmission-Sig' => '<from PayPal transmission>', 'Paypal-Transmission-Time' => '<from PayPal transmission>'],
 'fields' => [$Q('(raw JSON body)', 'body', 'PayPal webhook event (event_type + id)')],
 'resp' => [$R(200, 'completed | ignored'), $R(400, 'empty/invalid body OR unsigned (any transmission header missing)'), $R(401, 'verification error / signature mismatch'), $R(503, 'webhook_id misconfigured'), $R(429, 'throttle:payment-webhook 20/min')],
 'purpose' => 'SYSTEM: PayPal server webhook. SDK verify-webhook-signature against config webhook_id; PAYMENT.CAPTURE.COMPLETED -> complete. Auth NONE (public) — transmission signature is the auth.',
 'biz' => ['verifier' => 'PayPalWebhookVerifier::verify(transmission[5 headers], event)', 'throttle' => 'payment-webhook 20/min/IP'],
 'ev' => ['routes/api.php', 'app/Http/Controllers/Api/General/PaymentWebhookController.php@paypal', 'app/Services/Payment/PayPalWebhookVerifier.php']];
$over['POST /api/v1/general/track-order'] = ['fields' => [$Q('order_number', 'body', 'required|string'), $Q('user_email', 'body', 'required_without:user_phone|email|max:255'), $Q('user_phone', 'body', 'required_without:user_email|string|max:255')],
 'resp' => [$R(200, 'Order tracking (email/phone ownership check)'), $R(404, 'no matching order (proven pattern — verify before relying)'), $R(422, 'raw validation'), $R(429, 'throttle:public-tracking 10/min')],
 'purpose' => 'Public order tracking verified by order_number + email|phone (no auth; throttle:public-tracking).',
 'biz' => [], 'ev' => ['routes/api.php', 'app/Http/Controllers/Api/General/OrderTrackingController.php@trackByOrderNumber']];
// ---- build filtered spec ----
$scoped = array_values(array_filter($inv, fn($e) => inScope($e)));
function folderAdmin($path) {
    $u = strtolower($path);
    if (strpos($u, '/analytics/export') !== false) return 'Analytics Exports';
    if (strpos($u, '/analytics') !== false) return 'Analytics';
    if (strpos($u, '/batches') !== false) return 'Batches';
    if (strpos($u, '/picking-tasks') !== false || strpos($u, '/fulfillment-items') !== false) return 'Picking';
    if (strpos($u, '/packing-tasks') !== false || strpos($u, '/packing-stations') !== false) return 'Packing';
    if (strpos($u, '/fulfillments') !== false || strpos($u, '/fulfillment') !== false) return 'Fulfillment';
    if (strpos($u, '/warehouses') !== false) return 'Warehouses';
    if (strpos($u, '/locations') !== false) return 'Locations';
    if (strpos($u, '/shipments') !== false || strpos($u, '/orders/') !== false) return 'Shipments & Order Ops';
    if (strpos($u, '/packages') !== false) return 'Packages';
    if (strpos($u, '/dashboard') !== false) return 'Dashboard';
    if (strpos($u, '/tracking') !== false) return 'Tracking';
    if (strpos($u, '/notifications') !== false) return 'Notifications';
    if (strpos($u, '/refunds') !== false) return 'Refunds';
    if (strpos($u, '/logs') !== false) return 'Logs';
    if (strpos($u, '/settings') !== false || strpos($u, '/payment-gateways') !== false) return 'Settings';
    if (strpos($u, '/order-flow') !== false || strpos($u, '/order-status') !== false) return 'Order Flows';
    return 'Other';
}
$groups = ['general' => [], 'admin' => [], 'system' => []];
foreach ($scoped as $e) {
    $g = classify($e); $key = $e['method'] . ' ' . $e['uri'];
    $o = $over[$key] ?? null;
    $act = is_string($e['action']) ? $e['action'] : 'Closure';
    $parts = explode('@', $act);
    $mw = (array)$e['middleware'];
    $mwS = implode(',', $mw);
    $perm = null;
    foreach ($mw as $m) if (strpos((string)$m, 'permission:') !== false) { $perm = (string)$m; break; }
    if ($perm === null && isset($o['perm_in_controller'])) $perm = 'IN-CONTROLLER: ' . $o['perm_in_controller'] . ' (no route-level permission: middleware — gap)';
    $throttles = [];
    foreach ($mw as $m) if (strpos((string)$m, 'throttle:') !== false) $throttles[] = (string)$m;
    $needsAuth = strpos($mwS, 'auth:sanctum') !== false || $perm !== null;
    $aud = audience($e, $g);
    if ($o && isset($o['sig'])) {
        $mech = 'none (public) + provider signature headers (signature IS the auth)';
        $needsAuth = false;
    } elseif ($g === 'system') $mech = 'none (public)';
    elseif ($aud === 'admin') $mech = 'sanctum Bearer {{admin_token}}';
    elseif ($aud === 'customer') $mech = 'sanctum Bearer {{access_token}} (customer-owned)';
    elseif (strpos($mwS, 'auth:sanctum') !== false) $mech = 'sanctum Bearer';
    else $mech = 'none';
    $rec = ['id' => strtolower($key), 'group' => $g, 'audience' => $aud, 'method' => $e['method'], 'path' => $e['uri'],
     'name' => $e['name'], 'controller' => $o ? ($parts[0] ?? $act) : $parts[0], 'action' => $parts[1] ?? $act,
     'middleware' => $mw,
     'authentication' => ['required' => $needsAuth, 'mechanism' => $mech, 'throttle' => $throttles ? implode(' + ', $throttles) : null],
     'authorization' => ['required' => $perm !== null, 'detail' => $perm ?? ($needsAuth ? 'owner scope may apply in-controller; verify before relying' : 'none')],
     'purpose' => $o['purpose'] ?? 'As-implemented behavior; inspect controller/service in implementation_trace before integrating.',
     'request' => ['body_contract' => $o ? ['fields' => $o['fields']] : 'unknown:not_found-inspect-controller-FormRequest', 'validation_sources' => ['routes', 'controller', 'FormRequest']],
     'responses' => $o['resp'] ?? [['status' => 'unknown', 'description' => 'unproven: route-confirmed only; verify controller Resource/serializer and exception handler before use.']],
     'business_logic' => $o['biz'] ?? ['note' => 'untraced: route-confirmed only; see implementation_trace'],
     'implementation_trace' => ['route_files' => [strpos($e['uri'], '/api/v1/v1/') === 0 || strpos($e['uri'], '/api/v1/dashboard') === 0 || $e['uri'] === '/api/v1/logs/activity' || strpos($e['uri'], '/api/v1/settings') === 0 || $e['uri'] === '/api/v1/enum-types' ? 'packages/marvel/src/Rest/Routes.php' : 'routes/api.php'], 'evidence_files' => $o['ev'] ?? []],
     'evidence' => ['confidence' => $o ? 'high' : 'medium', 'route' => 'confirmed', 'controller' => $o ? 'confirmed' : 'route-action-only', 'fields' => $o ? 'confirmed' : 'untraced'],
     'status' => $o ? 'deep-traced' : 'route-confirmed'];
    if (strpos($e['uri'], '/api/v1/v1/') !== false) {
        $rec['status'] = $o ? 'deep-traced-unreachable-double-v1' : 'unreachable-double-v1';
        $rec['unreachable'] = true;
        $rec['unreachable_reason'] = 'Effective runtime path contains /api/v1/v1/admin/* (RestAPIServiceProvider prefix api/v1 + inner prefix v1/admin). Documented /api/v1/admin/* 404s. Verify via route:list before use.';
    }
    $groups[$g][] = $rec;
}
$spec = ['meta' => [
  'specification_version' => '2.0.0', 'generated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'source_of_truth' => 'implementation',
  'audit_type' => 'forensic_api_contract_audit (ADMIN fragment)', 'accuracy_policy' => 'implementation_over_documentation',
  'laravel_version' => '10.30.1', 'total_endpoint_count' => count($scoped),
  'scope' => 'ADMIN + WMS + ANALYTICS + SYSTEM: all /api/v1/v1/* double-prefix, /api/v1/admin/*, /api/v1/dashboard*, /api/v1/logs*, /api/v1/settings, /api/v1/enum-types, /api/v1/broadcasting/*, /api/v1/user*, webhooks/callbacks (+public track-order). sanctum/csrf-cookie excluded (non-API, web middleware).',
  'detected_api_route_files' => ['routes/api.php', 'packages/marvel/src/Rest/Routes.php'],
  'guard_model' => 'No separate admin guard: auth:sanctum + throttle:admin (400/min) + Spatie PermissionMiddleware (permission:*) per-route; analytics/export + tracking use in-controller authorizeAdmin() (view-analytics / export-analytics / view-orders). No route-level role:super_admin middleware anywhere in scope (proven absent).'],
 'authentication' => ['mechanisms' => ['sanctum Bearer admin token via POST /api/v1/admin-login (type=admin) — {{admin_token}}', 'sanctum Bearer customer token via POST /api/v1/token — {{access_token}} (user/* only)', 'Stripe-Signature + Paypal-Transmission-* provider signatures on webhooks (no Bearer)', 'public guest: enum-types, callbacks, track-order (email/phone verified)']],
 'global_rules' => ['wrapper' => 'Marvel\\Traits\\ApiResponse {status,message,success,data?} HTTP=status; Marvel FormRequest failedValidation returns raw {field:[msg]} 422 (no envelope)',
  'throttles' => 'admin 400/min (user-or-IP), analytics 60/min, authenticated 300/min, login 5/min/IP, public-api 120/min/IP, payment-callback 20/min/IP, payment-webhook 20/min/IP, public-tracking 10/min/IP, sensitive 5/min, otp 3/min, cart 20/min, refunds 5/min (all proven in AppServiceProvider + RouteServiceProvider)',
  'exports' => 'Analytics exports are SYNC CSV downloads (no async jobs, no status/cancel/download). Catalog import/export (products/brands/categories) DO use async status/cancel/download — analytics does not.',
  'unreachable' => 'All /api/v1/v1/* paths carry unreachable:true (double-v1 prefix stacking).'],
 'enums' => ['fulfillment_transition' => ['pending' => ['picking', 'cancelled'], 'picking' => ['picked', 'packing', 'cancelled'], 'picked' => ['packing', 'cancelled'], 'packing' => ['ready_to_ship', 'cancelled'], 'ready_to_ship' => ['shipped', 'cancelled'], 'shipped' => ['delivered'], 'delivered' => [], 'cancelled' => []],
  'batch_status' => ['pending', 'assigned', 'picking', 'completed', 'cancelled'],
  'picking_task_status' => ['pending', 'assigned', 'picking', 'picked', 'skipped', 'cancelled'],
  'packing_task_status' => ['pending', 'assigned', 'packing', 'packed', 'verified', 'cancelled'],
  'package_status' => ['open', 'sealed', 'handed_off', 'voided'],
  'packing_station_status' => ['active', 'inactive', 'maintenance'],
  'shipment_status_proven' => ['pending', 'label_created', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'cancelled'],
  'analytics_period' => ['1h', '24h', '7d', '30d', '90d'], 'time_series_metric' => ['orders', 'revenue', 'avg_order_value'], 'granularity' => ['hour', 'day', 'week', 'month']],
 'general_api' => ['summary' => [], 'endpoints' => $groups['general']],
 'admin_api' => ['summary' => [], 'endpoints' => $groups['admin']],
 'system_api' => ['summary' => [], 'endpoints' => $groups['system']],
 'business_flows' => [
   ['name' => 'Admin analytics review', 'actor' => 'admin', 'steps' => ['POST /api/v1/admin-login (type=admin)', 'GET analytics/dashboard?period', 'GET analytics/time-series?metric&granularity', 'GET analytics/top-customers?limit', 'POST analytics/export/* (SYNC csv download)', 'POST analytics/clear-cache (Cache::flush side effect)'], 'note' => 'All analytics paths are double-v1 at runtime (unreachable at documented single-v1).'],
   ['name' => 'WMS pick-pack-ship', 'actor' => 'warehouse staff', 'steps' => ['POST fulfillments/release (order_id)', 'POST batches (fulfillment_ids) -> pending', 'POST batches/{id}/assign -> assigned', 'POST batches/{id}/start -> picking', 'POST picking-tasks/{id}/claim|confirm|record-pick -> picked', 'POST batches/{id}/refresh-progress -> completed', 'POST fulfillments/{id}/create-packing-task -> packing', 'POST packing-tasks/{id}/assign|start|pack|verify -> verified (fulfillment ready_to_ship)', 'POST packages + add-item + seal', 'POST fulfillments/{id}/shipments -> label_created', 'POST shipments/{id}/dispatch -> picked_up (fulfillment shipped)', 'POST shipments/{id}/deliver -> delivered'], 'terminal' => ['delivered', 'cancelled', 'voided']],
   ['name' => 'Admin order oversight', 'actor' => 'admin', 'steps' => ['GET admin/tracking/dashboard?period', 'GET admin/tracking/orders?status&date_from&date_to', 'GET admin/tracking/orders/{orderId} (audited)', 'GET admin/tracking/requires-attention', 'POST v1/admin/orders/{orderId}/cancel (during-fulfillment, cascades)'], 'note' => 'tracking/* are single-v1 reachable; orders/cancel is double-v1.']],
 'security_findings' => [
   ['id' => 'A-01', 'severity' => 'SHOULD-FIX', 'title' => 'Double /api/v1/v1/admin prefix unreachable at documented path (90 routes)', 'evidence' => 'RestAPIServiceProvider prefix api/v1 + inner prefix v1/admin/...', 'recommendation' => 'strip inner v1/; route:list diff; compat redirect'],
   ['id' => 'A-02', 'severity' => 'SHOULD-FIX', 'title' => 'Analytics/export + tracking enforce permission in-controller, not at route level', 'evidence' => 'AnalyticsController::authorizeAdmin view-analytics/export-analytics; AdminOrderTrackingController::authorizeAdmin view-orders; no permission: middleware on those routes', 'recommendation' => 'add explicit route-level permission: middleware matching in-controller strings'],
   ['id' => 'A-03', 'severity' => 'SHOULD-FIX', 'title' => 'Admin notifications group lacks auth:sanctum + throttle:admin at route level', 'evidence' => 'Routes.php admin group: permission: + lang only', 'recommendation' => 'add auth:sanctum + throttle:admin to admin notifications group'],
   ['id' => 'A-04', 'severity' => 'INFO', 'title' => 'No route-level role:super_admin middleware in scope (proven absent)', 'evidence' => 'rg role: across app/packages/routes returns no in-scope hits; super_admin bypass only in-controller', 'recommendation' => 'rely on permission:* + warehouse scope; document super_admin semantics'],
   ['id' => 'A-05', 'severity' => 'INFO', 'title' => 'Analytics clear-cache calls Cache::flush() (broad)', 'evidence' => 'OrderAnalyticsService::clearCache', 'recommendation' => 'scope to analytics keys if shared cache matters'],
   ['id' => 'A-06', 'severity' => 'INFO', 'title' => 'Analytics exports are sync (no async jobs) — DoS/mismatch vs catalog pattern', 'evidence' => 'AnalyticsExportService writes storage/app/exports + download()->deleteFileAfterSend; no Jobs dispatched', 'recommendation' => 'document sync nature; add async only on explicit request']],
 'global_error_contract' => ['envelope' => '{status,message,success:false} (+data:null on 429)', 'raw_validation' => '{field:[msg]} 422 from Marvel FormRequest::failedValidation', 'auth' => '401 {message:Unauthenticated,status:false}', 'throttle' => '429 {success:false,message,data:null}'],
];
$isDeep = fn($r) => strpos($r['status'], 'deep-traced') === 0;
$isRoute = fn($r) => $r['status'] === 'route-confirmed';
$all = array_merge($groups['admin'], $groups['general'], $groups['system']);
$deepCount = 0;
foreach ($all as $rr) { if ($isDeep($rr)) $deepCount++; }
$routeCount = 0;
foreach ($all as $rr) { if ($isRoute($rr)) $routeCount++; }
$spec['audit_summary'] = ['total_routes' => count($scoped), 'general' => count($groups['general']), 'admin' => count($groups['admin']), 'system' => count($groups['system']), 'deep_traced' => $deepCount, 'route_confirmed' => $routeCount];
@mkdir('C:\\Users\\mohta\\AppData\\Local\\Temp\\opencode\\forensic', 0777, true);
file_put_contents('C:\\Users\\mohta\\AppData\\Local\\Temp\\opencode\\forensic\\admin-spec.json', json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo 'admin-spec endpoints: ' . count($groups['general']) . '/' . count($groups['admin']) . '/' . count($groups['system']) . PHP_EOL;

// ---- postman (same v2.1 schema) ----
function pmDesc($e) {
    $auth = $e['authentication'];
    $areq = ($auth['required'] ?? false) ? ('required (' . ($auth['mechanism'] ?? 'sanctum Bearer') . ')' . (isset($auth['throttle']) && $auth['throttle'] ? (' Throttle: ' . $auth['throttle']) : '')) : 'not required (guest allowed)';
    $S = fn($t) => "==================================================\n" . $t . "\n==================================================\n\n";
    $unreach = isset($e['unreachable']) ? ("\nWARNING: UNREACHABLE double-v1 path. " . ($e['unreachable_reason'] ?? '') . "\n") : '';
    return $S('BUSINESS PURPOSE') . ($e['purpose'] ?? 'unknown') . "\n\n"
      . $S('ACTOR') . ($e['audience'] ?? 'unknown') . ' (' . $e['group'] . ' API)' . "\n\n"
      . $S('AUTHENTICATION') . $areq . "\n\n"
      . $S('AUTHORIZATION') . json_encode($e['authorization']) . "\n\n"
      . $S('PRECONDITIONS') . "See business_logic; auth token present where required." . $unreach . "\n\n"
      . $S('REQUEST CONTRACT') . json_encode($e['request'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n"
      . $S('VALIDATION') . json_encode(['validation_sources' => ['routes', 'controller', 'FormRequest'], 'contract' => $e['request']['body_contract'] ?? 'unknown'], JSON_PRETTY_PRINT) . "\n\n"
      . $S('BUSINESS LOGIC') . json_encode($e['business_logic'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n"
      . $S('DATABASE EFFECTS') . json_encode($e['business_logic'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n"
      . $S('SIDE EFFECTS') . json_encode($e['business_logic'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n"
      . $S('SUCCESS RESPONSES') . json_encode($e['responses'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n"
      . $S('ERROR RESPONSES') . json_encode($e['responses'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n"
      . $S('PREVIOUS BUSINESS STEP') . "unknown — reason: no reliable consumer/workflow evidence found\n\n"
      . $S('NEXT BUSINESS STEP') . "unknown — reason: no reliable consumer/workflow evidence found\n\n"
      . $S('IMPLEMENTATION TRACE') . json_encode($e['implementation_trace'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
function pmFolder($e) {
    $g = $e['group']; $u = $e['path'];
    if ($g === 'system') {
        if (strpos($u, 'webhook') !== false) return 'Webhooks';
        if (strpos($u, 'callback') !== false) return 'Payment Callbacks';
        return 'Internal';
    }
    if ($g === 'general') {
        if (strpos($u, '/user/') !== false) return 'User (customer-owned)';
        if (strpos($u, 'track-order') !== false) return 'Public Tracking';
        return 'Public';
    }
    return folderAdmin($u);
}
$tree = ['General' => [], 'Admin' => [], 'System' => []];
$gmap = ['general' => 'General', 'admin' => 'Admin', 'system' => 'System'];
foreach (['general_api', 'admin_api', 'system_api'] as $k) {
    foreach (($spec[$k]['endpoints'] ?? []) as $e) {
        $G = $gmap[$e['group']] ?? 'General';
        $F = pmFolder($e);
        $tree[$G][$F][] = $e;
    }
}
foreach ($tree as $gg => &$ff) ksort($ff);
unset($ff);
$items = [];
foreach (['General', 'Admin', 'System'] as $G) {
    $subs = [];
    foreach ($tree[$G] as $F => $list) {
        if (!$list) continue;
        $reqs = [];
        foreach ($list as $e) {
            $method = $e['method'];
            $raw = '{{base_url}}' . preg_replace_callback('#\{([A-Za-z0-9_]+)\}#', fn($m) => '{{' . (['orderId' => 'order_id', 'itemId' => 'item_id', 'deviceId' => 'device_id'][ $m[1]] ?? $m[1]) . '}}', $e['path']);
            // query params for GETs with proven optional query fields
            $q = [];
            if ($method === 'GET' && is_array($e['request']['body_contract'] ?? null)) {
                foreach (($e['request']['body_contract']['fields'] ?? []) as $f) {
                    if (($f['location'] ?? '') !== 'query') continue;
                    $qn = explode('|', $f['name'])[0]; $qn = explode('.', $qn)[0];
                    if (strpos($qn, '(') !== false) continue;
                    $q[] = ['key' => $qn, 'value' => ''];
                }
            }
            if ($q) {
                $raw .= (strpos($raw, '?') === false ? '?' : '&') . implode('&', array_map(fn($p) => $p['key'] . '=' . $p['value'], $q));
            }
            $headers = [['key' => 'Accept', 'value' => 'application/json']];
            $needAuth = is_array($e['authentication']) ? ($e['authentication']['required'] ?? false) : false;
            $isWebhook = strpos($e['path'], 'webhooks/') !== false;
            if ($isWebhook) {
                $key = $e['method'] . ' ' . $e['path'];
                foreach (($over[$key]['sig'] ?? []) as $hk => $hv) $headers[] = ['key' => $hk, 'value' => $hv];
            } elseif ($needAuth) {
                $tok = ($e['audience'] === 'customer') ? '{{access_token}}' : '{{admin_token}}';
                $headers[] = ['key' => 'Authorization', 'value' => 'Bearer ' . $tok];
            }
            $body = null;
            if (in_array($method, ['POST', 'PUT', 'PATCH']) && is_array($e['request']['body_contract'] ?? null)) {
                $obj = [];
                foreach (($e['request']['body_contract']['fields'] ?? []) as $f) {
                    if (($f['location'] ?? '') !== 'body') continue;
                    $n = $f['name']; $r = $f['rules'] ?? '';
                    if (stripos($r, 'required') === false) continue;
                    if (strpos($n, '|') !== false || strpos($n, '(') !== false || strpos($n, '*') !== false || strpos($n, '.') !== false) continue;
                    if ($n === 'fulfillment_ids') $obj[$n] = [1];
                    elseif ($n === 'user_id') $obj[$n] = 1;
                    elseif ($n === 'order_id' || $n === 'fulfillment_id') $obj[$n] = 1;
                    elseif ($n === 'warehouse_id' || $n === 'station_id' || $n === 'product_location_id') $obj[$n] = 1;
                    elseif ($n === 'quantity' || $n === 'weight') $obj[$n] = 1;
                    elseif ($n === 'limit') $obj[$n] = 10;
                    elseif ($n === 'metric') $obj[$n] = 'revenue';
                    elseif ($n === 'period') $obj[$n] = '7d';
                    elseif ($n === 'granularity') $obj[$n] = 'day';
                    elseif ($n === 'code') $obj[$n] = 'WH-01';
                    elseif ($n === 'name') $obj[$n] = 'Main Warehouse';
                    elseif ($n === 'token') $obj[$n] = 'fcm-device-token';
                    elseif ($n === 'platform') $obj[$n] = 'android';
                    elseif ($n === 'order_number') $obj[$n] = 'ORD-0001';
                    elseif ($n === 'location' || $n === 'product') $obj[$n] = 'SCAN-001';
                    elseif ($n === 'reason') $obj[$n] = 'forensic probe';
                    elseif ($n === 'dimensions') $obj[$n] = [10, 10, 10];
                    elseif (stripos($r, 'date') !== false) $obj[$n] = '2026-01-01';
                    elseif ($n === 'date_to') $obj[$n] = '2026-01-31';
                    else $obj[$n] = 'string';
                }
                if ($obj) $body = ['mode' => 'raw', 'raw' => json_encode($obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 'options' => ['raw' => ['language' => 'json']]];
            }
            if ($isWebhook) $body = ['mode' => 'raw', 'raw' => '{}', 'options' => ['raw' => ['language' => 'json']]];
            $resp = [];
            if (strpos($e['status'], 'deep-traced') === 0) {
                foreach (($e['responses'] ?? []) as $r) {
                    if (!isset($r['status']) || !is_numeric($r['status'])) continue;
                    $resp[] = ['name' => $r['status'] . ' ' . substr($r['desc'] ?? '', 0, 80), 'originalRequest' => ['method' => $method, 'header' => $headers, 'url' => ['raw' => $raw]], 'status' => (string)$r['status'], 'code' => (int)$r['status'], '_postman_previewlanguage' => 'json', 'header' => [['key' => 'Content-Type', 'value' => 'application/json']], 'body' => json_encode(['status' => $r['status'], 'message' => substr($r['desc'] ?? '', 0, 120), 'success' => ((int)$r['status'] < 400)], JSON_UNESCAPED_SLASHES)];
                    if (count($resp) >= 6) break;
                }
            }
            $rname = $method . ' ' . $e['path'];
            if (isset($e['unreachable'])) $rname = '[UNREACHABLE double-v1] ' . $rname;
            $item = ['name' => $rname, 'request' => array_filter(['method' => $method, 'header' => $headers, 'url' => ['raw' => $raw], 'description' => pmDesc($e), 'body' => $body], fn($v) => $v !== null), 'response' => $resp];
            $reqs[] = $item;
        }
        $subs[] = ['name' => $F, 'item' => $reqs];
    }
    $items[] = ['name' => $G, 'item' => $subs];
}
$vars = ['base_url' => 'http://localhost', 'admin_token' => '', 'access_token' => '', 'customer_token' => ''];
foreach (['general_api', 'admin_api', 'system_api'] as $k) {
    foreach (($spec[$k]['endpoints'] ?? []) as $e) {
        if (preg_match_all('#\{([A-Za-z0-9_]+)\}#', $e['path'], $m)) foreach ($m[1] as $p) {
            $map = ['orderId' => 'order_id', 'itemId' => 'item_id', 'deviceId' => 'device_id'];
            $kk = $map[$p] ?? $p;
            if (!array_key_exists($kk, $vars)) $vars[$kk] = '';
        }
    }
}
$vlist = [];
foreach ($vars as $kk => $vv) $vlist[] = ['key' => $kk, 'value' => $vv];
$col = ['info' => ['_postman_id' => sprintf('%04x%04x-%04x-%04x-%04x-%04x%08x', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 4095) | 0x4000, mt_rand(0, 16383) | 0x8000, mt_rand(0, 65535), mt_rand(0, 4294967295)),
  'name' => 'ADMIN — Forensic Collection', 'description' => 'ADMIN + WMS + ANALYTICS + SYSTEM fragment. Generated from source-code forensic audit. Admin requests: Bearer {{admin_token}} (via POST /api/v1/admin-login). Webhooks: real provider signature headers (Stripe-Signature / Paypal-Transmission-*). System routes: never customer auth.', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
 'variable' => $vlist, 'auth' => ['type' => 'noauth'], 'event' => [], 'item' => $items];
file_put_contents('C:\\Users\\mohta\\AppData\\Local\\Temp\\opencode\\forensic\\admin-postman.json', json_encode($col, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$n = 0;
foreach ($items as $gg) foreach ($gg['item'] as $ss) $n += count($ss['item']);
echo "admin-postman requests: $n" . PHP_EOL;
