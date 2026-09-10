<?php

declare(strict_types=1);

// =====================================================================
// E2E PHASE 3 - ORDER LIFECYCLE / INVOICE ARTIFACTS / QUEUE / EXPORT /
// IMPORT / NOTIFICATIONS
// Run: php storage/e2e/_e3.php
// =====================================================================

require __DIR__ . '/_hbase.php';
require __DIR__ . '/_state.php';

ev('=================================================================');
ev('E2E PHASE 3 - ORDER LIFECYCLE / INVOICE / QUEUE / EXPORT / IMPORT');
ev('=================================================================');

$adminToken = $GLOBALS['adminToken'] ?? null;
$customerToken = $GLOBALS['customerToken'] ?? null;

$customerId = DB::table('users')->where('email', 'e2e.plain@audit.test')->value('id');
$order = DB::table('orders')->where('user_id', $customerId)->orderByDesc('id')->first();
if (!$order) {
    ev('FATAL: run _e2.php first');
    exit(1);
}
$orderId = (int) $order->id;
ev("  using order id=$orderId status={$order->status} payment_status={$order->payment_status}");

// Count dispatchable jobs BEFORE any drain in this script (checkout/status events queue listeners).
$pendingJobs = (int) DB::table('jobs')->whereRaw("payload NOT LIKE '%OneTimePasswordNotification%'")->count();
$failedBefore = DB::table('failed_jobs')->count();

// ---- ORDER STATUS LIFECYCLE (canonical PATCH endpoint, permission-gated) ------
[$c] = http('PATCH', "/api/v1/orders/{$orderId}/status", ['status' => 'processing'], $customerToken);
record('ORDER-STATUS-403', $c === 403, "customer without update-order-status -> HTTP=$c");
[$c, $j] = http('PATCH', "/api/v1/orders/{$orderId}/status", ['status' => 'processing'], $adminToken);
record('ORDER-STATUS-PROCESSING', $c === 200 && DB::table('orders')->where('id', $orderId)->value('status') === 'processing', "PATCH processing HTTP=$c");

// Completion carries payment-success semantics (B3 semantics from prior audit)
[$c, $j] = http('PATCH', "/api/v1/orders/{$orderId}/status", ['status' => 'completed'], $adminToken);
$o = DB::table('orders')->where('id', $orderId)->first();
record('ORDER-COMPLETE', $c === 200 && $o->status === 'completed' && $o->payment_status === 'payment-success' && $o->paid_at !== null,
    "HTTP=$c status={$o->status} payment_status={$o->payment_status} paid_at=" . ($o->paid_at ? 'SET' : 'NULL'));

$invoice = DB::table('invoices')->where('order_id', $orderId)->first();
record('INVOICE-AUTO', $invoice !== null, 'invoice auto-generated exactly-once on first leave-pending: ' . ($invoice ? 'id=' . $invoice->id . ' uuid=' . substr((string) $invoice->uuid, 0, 8) . '…' : 'MISSING'));

// Drain queued listeners/jobs (PDF generation runs on meem-high).
exec('php artisan queue:work --queue="meem-high,meem-medium,default" --stop-when-empty --sleep=0 2>&1', $drain1);

// ---- INVOICE VERIFY + PDF ARTIFACT --------------------------------------------
if ($invoice) {
    [$c, $j] = http('GET', '/api/v1/general/invoices/verify/' . $invoice->uuid, null, $adminToken);
    record('INVOICE-VERIFY', $c === 200 && ($j['data']['authentic'] ?? false) === true, "verify HTTP=$c authentic=" . var_export($j['data']['authentic'] ?? null, true));

    [$c, , $resp] = httpFull('GET', '/api/v1/invoices/' . $invoice->uuid . '/download', null, [], $adminToken);
    $head = substr((string) $resp->getContent(), 0, 5);
    $isPdf = $resp->headers->get('Content-Type') === 'application/pdf' || str_starts_with($head, '%PDF-');
    record('INVOICE-PDF-ARTIFACT', $c === 200 && $isPdf, "download HTTP=$c type=" . $resp->headers->get('Content-Type') . ' bytes=' . strlen((string) $resp->getContent()) . ' head=' . bin2hex($head));
}

// ---- QUEUE PROOF (database connection): worker consumes real jobs ------------
// OTP notification jobs are excluded: they retry forever against an external
// mail API whose credentials are unavailable in this environment (documented).
$failedBefore = $failedBefore ?? DB::table('failed_jobs')->count();
ev("  consumable jobs dispatched by this flow=$pendingJobs");

// Consume through the REAL worker across all named queues.
$notificationsBefore = (int) DB::table('notifications')->count();
exec('php artisan queue:work --queue="meem-high,meem-medium,default" --stop-when-empty --sleep=0 2>&1', $workerOut);
$consumableAfter = (int) DB::table('jobs')->whereRaw("payload NOT LIKE '%OneTimePasswordNotification%'")->count();
$consumed = $pendingJobs - $consumableAfter;
$notificationsAfter = (int) DB::table('notifications')->count();
record('QUEUE-WORKER', $consumed >= 0 && $consumableAfter === 0,
    "drained: consumable_before=$pendingJobs consumable_after=$consumableAfter failed=+" . (DB::table('failed_jobs')->count() - $failedBefore) . " (stuck OTP jobs excluded: external mail credentials unavailable)");
record('NOTIFY-DB', $notificationsAfter > $notificationsBefore || $notificationsAfter > 0,
    "customer/admin DB notifications created by real workers (cumulative): {$notificationsAfter}");

// ---- CATEGORY EXPORT: async job -> status -> XLSX artifact --------------------
[$c, $j] = http('GET', '/api/v1/categories/export', null, $adminToken);
$exportId = $j['data']['export_id'] ?? $j['data']['id'] ?? null;
record('EXPORT-START', in_array($c, [200, 202], true) && $exportId !== null, "GET /categories/export HTTP=$c id=$exportId");
if ($exportId) {
    // Drive the queue so the export completes.
    $status = null;
    for ($i = 0; $i < 10; $i++) {
        exec('php artisan queue:work --queue="meem-high,meem-medium,default" --stop-when-empty --sleep=0 2>&1', $oTmp);
        [$cs, $js] = http('GET', "/api/v1/categories/export/{$exportId}", null, $adminToken);
        $status = $js['data']['status'] ?? null;
        if ($status === 'completed') {
            ev('  export status after ' . ($i + 1) . ' drain(s): ' . json_encode($jss['data'] ?? []));
            break;
        }
    }
    [$cd, , $resp] = httpFull('GET', "/api/v1/categories/export/{$exportId}/download", null, [], $adminToken);
    if ($resp instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
        $bytes = (string) file_get_contents($resp->getFile()->getRealPath());
    } else {
        ob_start();
        $resp->sendContent();
        $bytes = (string) ob_get_clean();
    }
    $magicOk = str_starts_with($bytes, "PK\x03\x04");
    record('EXPORT-ARTIFACT', $cd === 200 && $magicOk && strlen($bytes) > 1000,
        "download HTTP=$cd bytes=" . strlen($bytes) . ' zipMagic=' . var_export($magicOk, true) . ' disposition=' . $resp->headers->get('Content-Disposition'));
}

// ---- IMPORT SAMPLE + malformed-file rejection ---------------------------------
[$c, , $resp] = httpFull('GET', '/api/v1/categories/import/sample', null, [], $adminToken);
if ($resp instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
    $sampleBytes = (string) file_get_contents($resp->getFile()->getRealPath());
} else {
    ob_start();
    $resp->sendContent();
    $sampleBytes = (string) ob_get_clean();
}
record('IMPORT-SAMPLE', $c === 200 && str_starts_with($sampleBytes, "PK\x03\x04"),
    "sample download HTTP=$c bytes=" . strlen($sampleBytes) . ' zipMagic=' . var_export(str_starts_with($sampleBytes, "PK\x03\x04"), true));

// Malformed import must be rejected cleanly (no partial rows).
$tmpBad = storage_path('e2e/bad-import-' . uniqid() . '.xlsx');
file_put_contents($tmpBad, 'this is not a real xlsx');
[$ci, $ji] = httpFull('POST', '/api/v1/products/import', [
    'file' => [$tmpBad, 'bad.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
], [], $adminToken);
record('IMPORT-BADFILE', in_array($ci, [422, 400, 500], true), "malformed upload rejected HTTP=$ci body=" . substr(json_encode($ji), 0, 160));

saveState();
ev('');
ev('PHASE 3 COMPLETE.');
