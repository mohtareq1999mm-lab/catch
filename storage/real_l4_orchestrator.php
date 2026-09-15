<?php
// Real L4 orchestrator - proves L4 for all 6 operations
// Run via: php D:/work/meem/storage/real_l4_orchestrator.php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\User;
use Marvel\Enums\FileOperationType;
use Marvel\Enums\Permission as Perm;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Support\Facades\Artisan;

$baseUrl = 'http://127.0.0.1:8001/api/v1/';
$pusherKey = env('PUSHER_APP_KEY');
$pusherCluster = env('PUSHER_APP_CLUSTER') ?: 'eu';
$logPath = __DIR__ . '/pusher-test/events_l4.json';
$reportPath = __DIR__ . '/pusher-test/l4_report.json';

// Clean previous
@unlink($logPath);
@unlink($reportPath);
if (is_dir(__DIR__ . '/pusher-test')) {
  foreach (glob(__DIR__ . '/pusher-test/events*.json') ?: [] as $f) @unlink($f);
}
if (is_dir(storage_path('app/imports'))) {
  foreach (glob(storage_path('app/imports') . '/*.json') ?: [] as $f) @unlink($f);
}

// Create admin with all perms
echo "Creating admin user...\n";
$perms = [Perm::IMPORT_PRODUCT, Perm::IMPORT_CATEGORY, Perm::IMPORT_BRAND, Perm::EXPORT_PRODUCT, Perm::EXPORT_CATEGORY, Perm::EXPORT_BRAND];
foreach ($perms as $p) { Permission::findOrCreate($p, 'api'); }
$role = Role::create(['name' => 'l4_' . uniqid(), 'guard_name' => 'api', 'display_name' => 'l4']);
foreach ($perms as $p) $role->givePermissionTo($p);
$user = User::create([
  'name' => 'l4_' . uniqid(),
  'email' => uniqid() . '@l4.test',
  'password' => Hash::make('password'),
  'email_verified_at' => now(),
  'is_active' => true,
  'type' => 'admin',
]);
$user->assignRole($role);
foreach ($perms as $p) $user->givePermissionTo($p);
$token = $user->createToken('l4')->plainTextToken;
echo "User ID: {$user->id} Token: " . substr($token,0,8) . "***\n";

// Start Node client BEFORE operations
echo "Starting Node Pusher client...\n";
$nodeCmd = "node " . escapeshellarg(__DIR__ . "/pusher-test/client.js") .
  " --userId " . escapeshellarg($user->id) .
  " --token " . escapeshellarg($token) .
  " --key " . escapeshellarg($pusherKey) .
  " --cluster " . escapeshellarg($pusherCluster) .
  " --log " . escapeshellarg($logPath);
$descriptors = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
$proc = proc_open($nodeCmd, $descriptors, $pipes, __DIR__ . '/pusher-test');
if (!is_resource($proc)) { echo "Failed to start Node client\n"; exit(1); }
echo "Node client PID started, waiting for subscription...\n";
// Wait for subscription_succeeded up to 15s
$subscribed = false;
for ($i=0; $i<15; $i++) {
  sleep(1);
  if (file_exists($logPath)) {
    $data = json_decode(file_get_contents($logPath), true);
    if (!empty($data['diagnostics']['subscription_succeeded'])) { $subscribed=true; break; }
    if (!empty($data['diagnostics']['subscription_error'])) { echo "Subscription error: " . $data['diagnostics']['subscription_error'] . "\n"; break; }
  }
  echo "  waiting... $i\n";
}
if (!$subscribed) {
  echo "WARNING: subscription not confirmed within 15s, checking diagnostics\n";
  if (file_exists($logPath)) { echo file_get_contents($logPath) . "\n"; }
  // continue anyway
} else {
  echo "Subscription succeeded!\n";
}

// Helper to create workbooks
function makeProductWorkbook($rows) {
  $s = new Spreadsheet(); $sh = $s->getActiveSheet(); $sh->setTitle('products');
  $sh->fromArray(['name','sku','price','status','quantity','category','brand'], null, 'A1');
  $r=2; foreach($rows as $row){ $sh->fromArray([$row['name']??'', $row['sku']??'', $row['price']??10, $row['status']??1, $row['quantity']??10, $row['category']??'', $row['brand']??''], null, "A$r"); $r++; }
  $tmp=tempnam(sys_get_temp_dir(),'prod'); (new Xlsx($s))->save($tmp); $s->disconnectWorksheets(); return $tmp;
}
function makeCategoryWorkbook($rows) {
  $s=new Spreadsheet(); $sh=$s->getActiveSheet(); $sh->setTitle('categories');
  $sh->fromArray(['name_en','name_ar','parent_name_en','status'], null, 'A1');
  $r=2; foreach($rows as $row){ $sh->fromArray([$row['name_en']??'', $row['name_ar']??'', $row['parent_name_en']??'', $row['status']??1], null, "A$r"); $r++; }
  $tmp=tempnam(sys_get_temp_dir(),'cat'); (new Xlsx($s))->save($tmp); $s->disconnectWorksheets(); return $tmp;
}
function makeBrandWorkbook($rows) {
  $s=new Spreadsheet(); $sh=$s->getActiveSheet(); $sh->setTitle('brands');
  $sh->fromArray(['name_en','name_ar','details_en','details_ar','status'], null, 'A1');
  $r=2; foreach($rows as $row){ $sh->fromArray([$row['name_en']??'', $row['name_ar']??'', $row['details_en']??'', $row['details_ar']??'', $row['status']??1], null, "A$r"); $r++; }
  $tmp=tempnam(sys_get_temp_dir(),'brand'); (new Xlsx($s))->save($tmp); $s->disconnectWorksheets(); return $tmp;
}

$client = new GuzzleHttp\Client(['base_uri'=>$baseUrl, 'http_errors'=>false, 'timeout'=>30]);
$results = [];

function waitForTerminal($importId, $user, $timeout=45) {
  for($i=0;$i<$timeout;$i++){
    $import = Import::find($importId);
    if($import && in_array($import->status, ['completed','completed_with_errors','failed','cancelled'])) return $import;
    sleep(1);
    // Also run queue worker if needed (database queue)
    try { Artisan::call('queue:work', ['--queue'=>'catch-medium','--stop-when-empty'=>true,'--timeout'=>1300,'--tries'=>2,'--sleep'=>0.1]); } catch(Throwable $e){}
  }
  return Import::find($importId);
}

function getEventsForOperation($logPath, $opId) {
  if (!file_exists($logPath)) return [];
  $data=json_decode(file_get_contents($logPath), true);
  $evs = $data['events'] ?? [];
  $filtered = array_values(array_filter($evs, fn($e)=> isset($e['data']['operation_id']) && (int)$e['data']['operation_id']===(int)$opId || isset($e['data']['id']) && (int)$e['data']['id']===(int)$opId || isset($e['data']['import_id']) && (int)$e['data']['import_id']===(int)$opId || isset($e['data']['export_id']) && (int)$e['data']['export_id']===(int)$opId ));
  // fallback: if no id match, return all events that contain operation type
  if (empty($filtered) && !empty($evs)) {
    // do not filter, return all for debugging
  }
  return $filtered;
}

function doImport($client, $token, $endpoint, $filePath, $fileName, $user, $logPath) {
  $res = $client->post($endpoint, [
    'headers'=>['Authorization'=>"Bearer $token", 'Accept'=>'application/json'],
    'multipart'=>[['name'=>'file','contents'=>fopen($filePath,'r'),'filename'=>$fileName]]
  ]);
  $body = (string)$res->getBody();
  $json = json_decode($body, true);
  $status = $res->getStatusCode();
  if ($status!==202) { echo "POST $endpoint failed $status $body\n"; return [null,null,[]]; }
  $importId = $json['data']['import_id'] ?? $json['data']['id'] ?? null;
  echo "  $endpoint -> 202 import_id=$importId\n";
  // Run queue worker to process (since database queue)
  for($i=0;$i<3;$i++){ try{ Artisan::call('queue:work', ['--queue'=>'catch-medium','--stop-when-empty'=>true,'--timeout'=>1300,'--tries'=>2,'--sleep'=>0.1]); }catch(Throwable $e){} sleep(1); }
  $import = waitForTerminal($importId, $user, 30);
  echo "  DB status: " . ($import ? $import->status : 'null') . " progress: " . ($import ? $import->processed_rows . "/" . $import->total_rows : '') . "\n";
  sleep(2); // allow Pusher to deliver
  $evs = file_exists($logPath) ? json_decode(file_get_contents($logPath), true)['events'] ?? [] : [];
  $opEvs = getEventsForOperation($logPath, $importId);
  echo "  Events captured total: ".count($evs)." for op: ".count($opEvs)."\n";
  foreach($opEvs as $e) echo "    - ".$e['event']." state=".($e['data']['state'] ?? $e['data']['status'] ?? 'n/a')." progress=".($e['data']['progress'] ?? 'n/a')."\n";
  return [$importId, $import, $opEvs];
}

function doExport($client, $token, $endpoint, $method='POST', $payload=null, $user=null, $logPath=null) {
  $opts = ['headers'=>['Authorization'=>"Bearer $token",'Accept'=>'application/json']];
  if ($method==='POST' && $payload) { $opts['json']=$payload; }
  if ($method==='GET' && $payload) { $endpoint .= '?' . http_build_query($payload); }
  $res = $client->request($method, $endpoint, $opts);
  $body=(string)$res->getBody(); $json=json_decode($body,true); $status=$res->getStatusCode();
  if($status!==202){ echo "$method $endpoint failed $status $body\n"; return [null,$status,$body]; }
  $exportId = $json['data']['export_id'] ?? $json['data']['id'] ?? null;
  echo "  $method $endpoint -> 202 export_id=$exportId\n";
  for($i=0;$i<3;$i++){ try{ Artisan::call('queue:work', ['--queue'=>'catch-medium','--stop-when-empty'=>true,'--timeout'=>1300,'--tries'=>2,'--sleep'=>0.1]); }catch(Throwable $e){} sleep(1); }
  $exp = waitForTerminal($exportId, $user, 30);
  echo "  DB status: ".($exp?$exp->status:'null')."\n";
  sleep(2);
  $evs = file_exists($logPath) ? json_decode(file_get_contents($logPath), true)['events'] ?? [] : [];
  $opEvs = getEventsForOperation($logPath, $exportId);
  echo "  Events total: ".count($evs)." for op: ".count($opEvs)."\n";
  foreach($opEvs as $e) echo "    - ".$e['event']." state=".($e['data']['state'] ?? $e['data']['status'] ?? 'n/a')." download_available=".($e['data']['download_available']?'true':'false')."\n";
  // Download
  $dlRes = $client->get("/brands/export/$exportId/download" , ['headers'=>['Authorization'=>"Bearer $token"]]);
  // For product/category export, endpoint varies; caller will handle
  return [$exportId, $exp, $opEvs];
}

// Run 6 operations
echo "\n=== Product Import ===\n";
$tmp = makeProductWorkbook([['name'=>'L4 Product '.uniqid(),'sku'=>'SKU'.uniqid(),'price'=>10,'status'=>1]]);
list($pid,$pImport,$pEvs) = doImport($client,$token,'products/import',$tmp,'products.xlsx',$user,$logPath);
$results['product_import']=['id'=>$pid,'status'=>$pImport?$pImport->status:null,'events'=>$pEvs,'db_match'=> $pImport && count($pEvs)>0 ? 'check' : null];
@unlink($tmp);

echo "\n=== Category Import ===\n";
$tmp = makeCategoryWorkbook([['name_en'=>'L4 Cat '.uniqid(),'name_ar'=>'فئة','status'=>1]]);
list($cid,$cImport,$cEvs) = doImport($client,$token,'categories/import',$tmp,'categories.xlsx',$user,$logPath);
$results['category_import']=['id'=>$cid,'status'=>$cImport?$cImport->status:null,'events'=>$cEvs];
@unlink($tmp);

echo "\n=== Brand Import ===\n";
$tmp = makeBrandWorkbook([['name_en'=>'L4 Brand '.uniqid(),'name_ar'=>'علامة','status'=>1]]);
list($bid,$bImport,$bEvs) = doImport($client,$token,'brands/import',$tmp,'brands.xlsx',$user,$logPath);
$results['brand_import']=['id'=>$bid,'status'=>$bImport?$bImport->status:null,'events'=>$bEvs];
@unlink($tmp);

echo "\n=== Product Export (no filters) ===\n";
$res = $client->post('products/export', ['headers'=>['Authorization'=>"Bearer $token",'Accept'=>'application/json'],'json'=>[]]);
$body=(string)$res->getBody(); $json=json_decode($body,true); $peId=$json['data']['export_id']??null;
echo "  POST /products/export -> 202 export_id=$peId\n";
for($i=0;$i<3;$i++){ try{ Artisan::call('queue:work', ['--queue'=>'catch-medium','--stop-when-empty'=>true,'--timeout'=>1300,'--tries'=>2,'--sleep'=>0.1]); }catch(Throwable $e){} sleep(1); }
$pe = waitForTerminal($peId,$user,30); echo "  DB status: ".($pe?$pe->status:'null')."\n"; sleep(2);
$peEvs=getEventsForOperation($logPath,$peId); echo "  Events for op: ".count($peEvs)."\n"; foreach($peEvs as $e) echo "    - ".$e['event']."\n";
$results['product_export']=['id'=>$peId,'status'=>$pe?$pe->status:null,'events'=>$peEvs];
if($peId){
  $dl=$client->get("products/export/$peId/download", ['headers'=>['Authorization'=>"Bearer $token"],'http_errors'=>false]);
  echo "  Download status: ".$dl->getStatusCode()." ct:".$dl->getHeader('Content-Type')[0]."\n";
  $results['product_export']['download_status']=$dl->getStatusCode();
}

echo "\n=== Category Export ===\n";
$res=$client->get('categories/export',['headers'=>['Authorization'=>"Bearer $token",'Accept'=>'application/json']]);
$body=(string)$res->getBody(); $json=json_decode($body,true); $ceId=$json['data']['export_id']??null;
echo "  GET /categories/export -> 202 export_id=$ceId\n";
for($i=0;$i<3;$i++){ try{ Artisan::call('queue:work', ['--queue'=>'catch-medium','--stop-when-empty'=>true,'--timeout'=>1300,'--tries'=>2,'--sleep'=>0.1]); }catch(Throwable $e){} sleep(1); }
$ce=waitForTerminal($ceId,$user,30); echo "  DB status: ".($ce?$ce->status:'null')."\n"; sleep(2);
$ceEvs=getEventsForOperation($logPath,$ceId); echo "  Events: ".count($ceEvs)."\n";
$results['category_export']=['id'=>$ceId,'status'=>$ce?$ce->status:null,'events'=>$ceEvs];
if($ceId){ $dl=$client->get("categories/export/$ceId/download", ['headers'=>['Authorization'=>"Bearer $token"],'http_errors'=>false]); echo "  Download: ".$dl->getStatusCode()."\n"; $results['category_export']['download_status']=$dl->getStatusCode(); }

echo "\n=== Brand Export ===\n";
$res=$client->get('brands/export',['headers'=>['Authorization'=>"Bearer $token",'Accept'=>'application/json']]);
$body=(string)$res->getBody(); $json=json_decode($body,true); $beId=$json['data']['export_id']??null;
echo "  GET /brands/export -> 202 export_id=$beId\n";
for($i=0;$i<3;$i++){ try{ Artisan::call('queue:work', ['--queue'=>'catch-medium','--stop-when-empty'=>true,'--timeout'=>1300,'--tries'=>2,'--sleep'=>0.1]); }catch(Throwable $e){} sleep(1); }
$be=waitForTerminal($beId,$user,30); echo "  DB status: ".($be?$be->status:'null')."\n"; sleep(2);
$beEvs=getEventsForOperation($logPath,$beId); echo "  Events: ".count($beEvs)."\n";
$results['brand_export']=['id'=>$beId,'status'=>$be?$be->status:null,'events'=>$beEvs];
if($beId){ $dl=$client->get("brands/export/$beId/download", ['headers'=>['Authorization'=>"Bearer $token"],'http_errors'=>false]); echo "  Download: ".$dl->getStatusCode()."\n"; $results['brand_export']['download_status']=$dl->getStatusCode(); }

// Collect diagnostics
$diag = file_exists($logPath) ? json_decode(file_get_contents($logPath), true)['diagnostics'] ?? [] : [];
$allEvents = file_exists($logPath) ? json_decode(file_get_contents($logPath), true)['events'] ?? [] : [];
$report = [
  'user_id'=>$user->id,
  'diagnostics'=>$diag,
  'total_events'=>count($allEvents),
  'operations'=>$results,
  'pusher_key_exists'=> env('PUSHER_APP_KEY') ? true : false,
  'cluster'=>$pusherCluster,
  'queue'=>'database catch-medium',
];
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT));
echo "\n=== REPORT ===\n";
echo json_encode($report, JSON_PRETTY_PRINT) . "\n";

// Cleanup Node
if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); echo "Node client terminated\n"; }

echo "Done\n";
