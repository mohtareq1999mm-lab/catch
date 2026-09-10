<?php
require __DIR__ . '/_hbase.php';
require __DIR__ . '/_state.php';
$st = json_decode((string) file_get_contents(__DIR__ . '/state.json'), true);
$t = $st['adminToken'];

$invoice = DB::table('invoices')->orderBy('id')->first();
ev('invoice id=' . ($invoice?->id) . ' pdf_path=' . var_export($invoice->pdf_path ?? null, true));

[$c, , $resp] = httpFull('GET', '/api/v1/invoices/' . $invoice->uuid . '/download', null, [], $t);
// StreamedResponse streams the PDF through sendContent() — capture it.
ob_start();
$resp->sendContent();
$pdfBytes = (string) ob_get_clean();
record('INVOICE-PDF-ARTIFACT', $c === 200 && str_starts_with($pdfBytes, '%PDF-'),
    "download HTTP=$c type=" . $resp->headers->get('Content-Type') . ' bytes=' . strlen($pdfBytes) . ' head=' . substr($pdfBytes, 0, 8) . ' disposition=' . $resp->headers->get('Content-Disposition'));

// Export: async start returns 202 by contract.
[$cs, $js] = http('GET', '/api/v1/categories/export', null, $t);
$exportId = $js['data']['id'] ?? $js['data']['export_id'] ?? null;
record('EXPORT-START', in_array($cs, [200, 202], true) && $exportId !== null, "GET /categories/export HTTP=$cs id=$exportId body=" . substr(json_encode($js), 0, 220));
if ($exportId) {
    for ($i = 0; $i < 12; $i++) {
        exec('php artisan queue:work --queue="meem-high,meem-medium,default" --stop-when-empty --sleep=0 2>&1');
        [$css, $jss] = http('GET', "/api/v1/categories/export/{$exportId}", null, $t);
        if (($jss['data']['status'] ?? '') === 'completed' || ($jss['data']['status'] ?? '') === 'done') {
            ev('  export status after ' . ($i + 1) . ' drains: ' . json_encode($jss['data']));
            break;
        }
    }
    [$cd, , $r2] = httpFull('GET', "/api/v1/categories/export/{$exportId}/download", null, [], $t);
    if ($r2 instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
        $b = (string) file_get_contents($r2->getFile()->getRealPath());
    } else {
        ob_start();
        $r2->sendContent();
        $b = (string) ob_get_clean();
    }
    record('EXPORT-ARTIFACT', $cd === 200 && str_starts_with($b, "PK\x03\x04") && strlen($b) > 1000,
        "download HTTP=$cd bytes=" . strlen($b) . ' zipMagic=' . var_export(str_starts_with($b, "PK\x03\x04"), true) . ' type=' . $r2->headers->get('Content-Type') . ' class=' . get_class($r2));
}

// Import sample root cause probe
require __DIR__ . '/../../vendor/autoload.php';
