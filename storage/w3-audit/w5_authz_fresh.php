<?php
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->instance('request', Illuminate\Http\Request::create('http://localhost/'));
$kernel->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$mode = $argv[1] ?? 'limited';
$assetUuid = $argv[2] ?? '';
$tokFile = $argv[3] ?? '';

if ($mode === 'setup') {
    Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--force' => true]);
    foreach (['view-products','create-product','update-product','manage-digital-licenses'] as $p) {
        DB::table('permissions')->insertOrIgnore(['name'=>$p,'guard_name'=>'api','created_at'=>now(),'updated_at'=>now()]);
    }
    function mkU($pdo,$tag,$type='admin'){ $pdo->prepare('INSERT INTO users (name,email,password,type,is_active,created_at,updated_at) VALUES (?,?,?,?,1,?,?)')->execute([$tag,uniqid($tag).'@e.com',password_hash('x',PASSWORD_BCRYPT),$type,now(),now()]); return (int)$pdo->lastInsertId(); }
    $pdo = DB::getPdo();
    $full = mkU($pdo,'full'); $lim = mkU($pdo,'lim');
    foreach (DB::table('permissions')->whereIn('name',['view-products','create-product','manage-digital-licenses'])->pluck('id') as $pid) {
        DB::table('model_has_permissions')->insert(['permission_id'=>$pid,'model_type'=>'Marvel\Database\Models\User','model_id'=>$full]);
    }
    $pdo->prepare('INSERT INTO products (slug,name,description,price,item_type,status,in_stock,created_at,updated_at) VALUES (?,?,?,?,?,1,1,?,?)')
        ->execute(['s-'.uniqid(),json_encode(['en'=>'S']),json_encode(['en'=>'s']),5,'DIGITAL',now(),now()]);
    file_put_contents(__DIR__.'/w5_ids.json', json_encode([
        'full'=>$full,'lim'=>$lim,'product'=>(int)$pdo->lastInsertId(),
        'fullTok'=>'f-'.bin2hex(random_bytes(16)),'limTok'=>'l-'.bin2hex(random_bytes(16)),
    ]));
    $ids = json_decode(file_get_contents(__DIR__.'/w5_ids.json'), true);
    foreach ([[$ids['full'],$ids['fullTok']],[ $ids['lim'],$ids['limTok']]] as [$uid,$t]) {
        DB::table('personal_access_tokens')->insert(['tokenable_type'=>'Marvel\Database\Models\User','tokenable_id'=>$uid,'name'=>'x','token'=>hash('sha256',$t),'created_at'=>now()]);
    }
    echo "seeded\n";
    exit(0);
}

function callHttp($kernel,$app,$m,$u,$t,array $j=[]) {
    $req=Request::create('/api/v1'.$u,$m,[],[],[],['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json'],json_encode($j));
    if($t){$req->headers->set('Authorization','Bearer '.$t);}
    $app->instance('request',$req);
    $res=$kernel->handle($req);
    return [$res->getStatusCode(),substr($res->getContent(),0,160)];
}

$ids = json_decode(file_get_contents(__DIR__.'/w5_ids.json'), true);

// Single-purpose process: full admin creates pool
if ($mode === 'full-create') {
    [$st,$b] = callHttp($kernel,$app,'POST',"/products/{$ids['product']}/digital-assets",$ids['fullTok'],['type'=>'LICENSE']);
    echo "create: $st $b\n";
    preg_match('/"uuid":"([^"]+)"/',$b,$m); file_put_contents(__DIR__.'/w5_uuid.txt',$m[1] ?? '');
    exit(0);
}

// Limited admin attempts import — FRESH PROCESS, FIRST REQUEST
if ($mode === 'limited-import') {
    $uuid = file_get_contents(__DIR__.'/w5_uuid.txt');
    [$st,$b] = callHttp($kernel,$app,'POST',"/digital-assets/{$uuid}/license-keys",$ids['limTok'],['keys'=>['NOPE']]);
    echo "LIMITED IMPORT (fresh process): $st | $b\n";
    exit(0);
}

// Full admin imports in its own fresh process too
if ($mode === 'full-import') {
    $uuid = file_get_contents(__DIR__.'/w5_uuid.txt');
    [$st,$b] = callHttp($kernel,$app,'POST',"/digital-assets/{$uuid}/license-keys",$ids['fullTok'],['keys'=>['REAL-1']]);
    echo "FULL IMPORT (fresh process): $st | $b\n";
    exit(0);
}
