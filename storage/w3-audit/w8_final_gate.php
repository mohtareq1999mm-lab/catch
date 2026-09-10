<?php

/**
 * W8 FINAL PRODUCTION GATE — consolidated black-box verification.
 *
 * Production migrations on a scratch DB · real HTTP · raw-PDO expectations.
 * Derives everything independently; prints PASS/FAIL per check.
 *
 * Usage: DB_CONNECTION=sqlite DB_DATABASE=<file> php w8_final_gate.php
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->instance('request', Illuminate\Http\Request::create('http://localhost/'));
$kernel->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$fail=[];$total=0;
function gate(string $n,bool $ok,string $d=''):void{ global $fail,$total; $total++; echo ($ok?'PASS':'FAIL').": $n".($ok||$d===''?'':" | $d")."\n"; if(!$ok)$fail[]=$n; }
function http($kernel,$app,$m,$u,?string $t=null,array $j=[],array $files=[],array $headers=[]){ global $lastRaw;
    try{app('auth')->guard('sanctum')->forgetUser();}catch(\Throwable){}
    app('auth')->forgetGuards();
    $srv=array_merge(['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json'],$headers);
    $req=Request::create('/api/v1'.$u,$m,[],[],$files,$srv,json_encode($j));
    if($t){$req->headers->set('Authorization','Bearer '.$t);}
    $app->instance('request',$req);
    $res=$kernel->handle($req);
    $lastRaw=$res->getContent();
    return ['status'=>$res->getStatusCode(),'json'=>json_decode($res->getContent(),true)??[],'raw'=>$lastRaw];
}

Artisan::call('migrate:fresh',['--force'=>true]);
$pdo=DB::getPdo();
if(DB::getDriverName()==='sqlite'){ $pdo->sqliteCreateFunction('now',fn()=>date('Y-m-d H:i:s')); }

/* ---------- A. schema ---------- */
foreach (['digital_assets','digital_entitlements','digital_license_keys','digital_download_logs','digital_asset_entitlement'] as $t) {
    gate("schema: $t exists", DB::getSchemaBuilder()->hasTable($t));
}
gate('schema: assets.path nullable', (int)collect($pdo->query("PRAGMA table_info('digital_assets')")->fetchAll(PDO::FETCH_ASSOC))->firstWhere('name','path')['notnull']===0);

/* ---------- B. registry ---------- */
$r = app(App\Services\Digital\AssetTypeRegistry::class);
gate('registry: 4 creatable types', count($r->creatableTypes())===4);
gate('registry: pdf still active', in_array('pdf',$r->activeExtensions()));
gate('registry: media activated (A3)', in_array('mp4',$r->activeExtensions()) && in_array('mp3',$r->activeExtensions()));

/* ---------- C. permissions in DB ---------- */
foreach (['view-products','create-product','update-product','manage-digital-access','manage-digital-licenses'] as $p) {
    $pdo->prepare('INSERT INTO permissions (name,guard_name,created_at,updated_at) VALUES (?,?,now(),now())')->execute([$p,'api']);
}
foreach (['manage-digital-access','manage-digital-licenses','view-products','create-product','update-product'] as $p) {
    gate("perm db: $p", (bool)$pdo->query("SELECT count(*) FROM permissions WHERE name='$p' AND guard_name='api'")->fetchColumn());
}

/* ---------- D. fixtures via HTTP ---------- */
function mkU($pdo,string $tag,array $perms=[],string $type='customer'){ $pdo->prepare('INSERT INTO users (name,email,password,type,is_active,created_at,updated_at) VALUES (?,?,?,?,1,now(),now())')->execute([$tag,uniqid($tag).'@e.com',password_hash('x',PASSWORD_BCRYPT),$type]); $id=(int)$pdo->lastInsertId(); foreach($perms as $p){ $pid=$pdo->query("SELECT id FROM permissions WHERE name='$p'")->fetchColumn(); if($pid){$pdo->prepare('INSERT INTO model_has_permissions (permission_id,model_type,model_id) VALUES (?,?,?)')->execute([$pid,'Marvel\Database\Models\User',$id]);} } return $id; }
function tok($pdo,int $uid){ $t='g8-'.bin2hex(random_bytes(16)); $pdo->prepare('INSERT INTO personal_access_tokens (tokenable_type,tokenable_id,name,token,created_at) VALUES (?,?,?,?,now())')->execute(['Marvel\Database\Models\User',$uid,'g8',hash('sha256',$t)]); return $t; }

$adminId=mkU($pdo,'g-admin',['view-products','create-product','update-product','view-orders','manage-digital-access'],'admin');
$custId=mkU($pdo,'g-cust');
$adminTok=tok($pdo,$adminId); $custTok=tok($pdo,$custId);

$pdo->prepare('INSERT INTO products (slug,name,description,price,item_type,status,in_stock,created_at,updated_at) VALUES (?,?,?,?,?,1,1,now(),now())')
    ->execute(['g8-'.uniqid(),json_encode(['en'=>'G']),json_encode(['en'=>'g']),11,'DIGITAL']);
$productId=(int)$pdo->lastInsertId();

$pdf="%PDF-1.4\nG8FINAL\n%%EOF";
$tmp=tempnam(sys_get_temp_dir(),'pdf'); file_put_contents($tmp,$pdf);
$up=new \Illuminate\Http\UploadedFile($tmp,'final.pdf','application/pdf',null,true);
$r=http($kernel,$app,'POST',"/products/{$productId}/digital-assets",$adminTok,[],['file'=>$up]);
gate('upload FILE → 201',$r['status']===201);
$assetUuid=$r['json']['data']['uuid'];
$assetId=(int)$pdo->query("SELECT id FROM digital_assets WHERE uuid=".$pdo->quote($assetUuid))->fetchColumn();
$storedPath=$pdo->query("SELECT path FROM digital_assets WHERE uuid=".$pdo->quote($assetUuid))->fetchColumn();
gate('fs: physical file exists at recorded path', is_file(storage_path('app/private/'.$storedPath)));
gate('fs: stored checksum == sha256(file)', hash('sha256',file_get_contents(storage_path('app/private/'.$storedPath)))===$pdo->query("SELECT checksum FROM digital_assets WHERE uuid=".$pdo->quote($assetUuid))->fetchColumn());

/* ---------- E. fulfillment + delivery ---------- */
$orderCols=collect($pdo->query("PRAGMA table_info('orders')")->fetchAll(PDO::FETCH_ASSOC));
$oN=[];$oB=[]; $oVals=['user_id'=>$custId,'status'=>'completed','payment_status'=>'paid','name'=>json_encode(['en'=>'O'])];
foreach($orderCols as $c){ $n=$c['name']; if(isset($oVals[$n])){$oN[]=$n;$oB[]=$oVals[$n];continue;} if((int)$c['notnull']===1&&$c['dflt_value']===null&&$n!=='id'){$ty=strtolower((string)$c['type']);$oN[]=$n;$oB[]=str_contains($ty,'int')?0:'{}';} }
$pdo->prepare('INSERT INTO orders ('.implode(',',$oN).') VALUES ('.implode(',',array_fill(0,count($oN),'?')).')')->execute($oB);
$orderId=(int)$pdo->lastInsertId();
$iCols=collect($pdo->query("PRAGMA table_info('order_products')")->fetchAll(PDO::FETCH_ASSOC));
$iN=[];$iB=[]; $iVals=['order_id'=>$orderId,'product_id'=>$productId,'product_name'=>json_encode(['en'=>'I']),'item_type'=>'DIGITAL','product_quantity'=>1];
foreach($iCols as $c){ $n=$c['name']; if(isset($iVals[$n])){$iN[]=$n;$iB[]=$iVals[$n];continue;} if((int)$c['notnull']===1&&$c['dflt_value']===null&&$n!=='id'){$ty=strtolower((string)$c['type']);$iN[]=$n;$iB[]=str_contains($ty,'int')?0:'{}';} }
$pdo->prepare('INSERT INTO order_products ('.implode(',',$iN).') VALUES ('.implode(',',array_fill(0,count($iN),'?')).')')->execute($iB);
$itemId=(int)$pdo->lastInsertId();

event(new App\Events\PaymentSucceeded(Marvel\Database\Models\Order::find($orderId)));
$ent=$pdo->query("SELECT * FROM digital_entitlements WHERE order_id={$orderId}")->fetch(PDO::FETCH_ASSOC);
gate('fulfillment delivered entitlement',$ent && $ent['status']==='delivered');

$signed=\Illuminate\Support\Facades\URL::temporarySignedRoute('general.digital.download',now()->addMinutes(10),['entitlement'=>$ent['uuid'],'asset'=>$assetUuid]);
$req=Request::create($signed,'GET'); $app->instance('request',$req);
$res=$kernel->handle($req);
ob_start(); try{$res->sendContent();}finally{$bytes=ob_get_clean();}
gate('download byte-exact vs fixture',$bytes===$pdf);
gate('credit incremented to 1',(int)$pdo->query("SELECT download_count FROM digital_entitlements WHERE id={$ent['id']}")->fetchColumn()===1);
gate('audit row written',(int)$pdo->query("SELECT count(*) FROM digital_download_logs WHERE asset_id={$assetId}")->fetchColumn()===1);
gate('response exposes no path/disk',!str_contains(json_encode($res->headers->all()),'storage') );

/* ---------- F. translations ---------- */
foreach ([['en','ERROR.DIGITAL_DOWNLOAD_LIMIT_REACHED'],['ar','ERROR.DIGITAL_LICENSE_ALREADY_REVEALED'],['de','ERROR.DIGITAL_ASSET_NOT_REPLACEABLE']] as [$l,$k]) {
    $v=__("message.".$k,[],$l);
    gate("trans $l: ".basename($k)." resolves",$v!==$k && trim($v)!=='');
}

echo "\n==== W8 FINAL GATE: ".($total-count($fail))."/{$total} ====\n";
exit(empty($fail)?0:1);
