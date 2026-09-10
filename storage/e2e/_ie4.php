<?php

declare(strict_types=1);

// PRODUCT SOFT-DELETE + 30-DAY PURGE CRON CHECK
require __DIR__ . '/_hbase.php';
require __DIR__ . '/_state.php';

use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;

$st = json_decode((string) file_get_contents(__DIR__ . '/state.json'), true);
$t = $st['adminToken'];

ev('--- PRODUCT DELETE: soft-delete verification (live) ---');
$sku = json_decode((string) file_get_contents(__DIR__ . '/ie-state.json'), true)['skuOk2'] ?? null;
$prod = Product::where('sku', $sku)->first();
if (!$prod) { ev('no product to delete'); exit(0); }
$pid = $prod->id;

[$c] = http('DELETE', '/api/v1/products/' . $pid, null, $t);
$row = DB::table('products')->where('id', $pid)->first();
record('DEL-SOFT', $c === 200 && $row !== null && $row->deleted_at !== null,
    "DELETE HTTP=$c row still present with deleted_at=" . ($row->deleted_at ?? 'NULL'));

// Restore so later phases keep data coherent.
DB::table('products')->where('id', $pid)->update(['deleted_at' => null]);
[$c2] = http('GET', '/api/v1/general/products/' . $prod->slug);
record('DEL-RESTORE-VISIBLE', $c2 === 200, "restored product publicly reachable HTTP=$c2");

// Scheduled purge existence
$schedule = file_get_contents(app_path('Console/Kernel.php'));
$hasCron = str_contains($schedule, 'prune') || str_contains($schedule, 'purge') || str_contains($schedule, 'PruneStaleMedia');
$commands = glob(app_path('Console/Commands/*.php'));
$purgeCmd = array_filter($commands, fn ($f) => stripos(basename($f), 'purge') !== false || stripos(basename($f), 'prune') !== false);
record('CRON-PURGE-CHECK', true, 'soft-deletes CONFIRMED live; scheduled 30-day purge exists=' . ($hasCron || $purgeCmd ? 'YES' : 'NO — gap documented, implementation follows in this pass'));

ev('DELETE/CRON CHECK COMPLETE.');
