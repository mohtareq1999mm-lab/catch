<?php
// Build postman-collection.json v2.1 from api-master-spec.json. No invented fields.
error_reporting(E_ERROR);
$spec = json_decode(file_get_contents(__DIR__.'/../api-master-spec.json'), true);
function folder($e,$g){
    $u=$e['path'];
    if ($g==='system') {
        if (strpos($u,'webhook')!==false) return 'Webhooks';
        if (strpos($u,'callback')!==false) return 'Payment Callbacks';
        return 'Internal';
    }
    if ($g==='admin') {
        if (preg_match('#/(brand|attribute|tag|flash|promotion|coupon|product|categor|banner|slider|content|section|static|setting|faq|governorate|countr|cit|pickup|fast-shipping|site-review|currenc|payment-gateway)#',$u,$m)) {
            $map=['brand'=>'Brands','attribute'=>'Content','tag'=>'Content','flash'=>'Content','promotion'=>'Content','coupon'=>'Content','product'=>'Products','categor'=>'Categories','banner'=>'Content','slider'=>'Content','content'=>'Content','section'=>'Content','static'=>'Content','setting'=>'Settings','faq'=>'Content','governorate'=>'Locations','countr'=>'Locations','cit'=>'Locations','pickup'=>'Locations','fast-shipping'=>'Settings','site-review'=>'Content','currenc'=>'Settings','payment-gateway'=>'Payments'];
            return $map[strtolower($m[1])]??'Other';
        }
        if (strpos($u,'order')!==false||strpos($u,'fulfill')!==false) return 'Orders';
        if (strpos($u,'refund')!==false) return 'Refunds';
        if (strpos($u,'shipment')!==false||strpos($u,'warehouse')!==false||strpos($u,'wms')!==false) return 'Fulfillment';
        if (strpos($u,'user')!==false||strpos($u,'role')!==false||strpos($u,'permission')!==false) return 'Roles & Permissions';
        if (strpos($u,'notification')!==false) return 'Notifications';
        if (strpos($u,'analytic')!==false||strpos($u,'dashboard')!==false||strpos($u,'report')!==false) return 'Dashboard';
        if (strpos($u,'invoice')!==false) return 'Orders';
        if (strpos($u,'review')!==false) return 'Reviews';
        if (strpos($u,'contact')!==false) return 'Customers';
        if (strpos($u,'address')!==false||strpos($u,'customer')!==false) return 'Customers';
        return 'Other';
    }
    if (preg_match('#/(token|register|login|logout|password|otp|social|/me|contact$)#',$u)) return 'Authentication';
    if (strpos($u,'cart')!==false) return 'Cart';
    if (strpos($u,'checkout')!==false) return 'Checkout';
    if (strpos($u,'coupon')!==false) return 'Coupons';
    if (strpos($u,'order')!==false) return 'Orders';
    if (strpos($u,'payment')!==false) return 'Payments';
    if (strpos($u,'refund')!==false) return 'Refunds';
    if (strpos($u,'track')!==false) return 'Tracking';
    if (strpos($u,'invoice')!==false) return 'Invoices';
    if (strpos($u,'digital')!==false) return 'Digital';
    if (strpos($u,'notification')!==false||strpos($u,'preference')!==false) return 'Notifications';
    if (strpos($u,'review')!==false||strpos($u,'wishlist')!==false) return 'Reviews';
    if (strpos($u,'address')!==false||strpos($u,'profile')!==false) return 'Profile';
    if (strpos($u,'contact')!==false) return 'Contact';
    if (strpos($u,'shipment')!==false) return 'Shipments';
    return 'Catalog';
}
function desc($e){
    $auth = $e['authentication'];
    $areq = is_array($auth) ? (($auth['required']??false)?('required ('.($auth['mechanism']??'sanctum Bearer').')'.(isset($auth['throttle'])?(' Throttle: '.$auth['throttle']):'')):'not required (guest allowed)') : (string)$auth;
    $az = is_array($e['authorization']??null) ? json_encode($e['authorization']) : (string)($e['authorization']??'unknown');
    $biz = $e['business_logic']??[];
    $bizS = is_array($biz) ? json_encode($biz, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) : (string)$biz;
    $req = $e['request']??[];
    $reqS = is_array($req) ? json_encode($req, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) : (string)$req;
    $resp = $e['responses']??[];
    $respS = is_array($resp) ? json_encode($resp, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) : (string)$resp;
    $impl = $e['implementation_trace']??[];
    $implS = is_array($impl) ? json_encode($impl, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) : (string)$impl;
    $isDeep = (($e['status']??'')==='deep-traced');
    $valS = $isDeep ? $reqS : json_encode(['validation_sources'=>['routes','controller','FormRequest'],'contract'=>'unknown: route-confirmed only; FormRequest not traced for this endpoint — do not assume fields'], JSON_PRETTY_PRINT);
    $bizDetail = $isDeep ? $bizS : 'unknown: route-confirmed only; controller/service chain not traced for this endpoint. See implementation_trace.';
    $dbS = $isDeep ? $bizS : 'unknown: no proven mutations; inspect controller/service in implementation_trace.';
    $sideS = $isDeep ? $bizS : 'unknown: events/jobs/notifications not traced for this endpoint.';
    $unreach = isset($e['unreachable']) ? ("\nWARNING: UNREACHABLE double-v1 path. ".($e['unreachable_reason']??'')."\n") : '';
    $S = fn($t)=>"==================================================\n".$t."\n==================================================\n\n";
    return $S('BUSINESS PURPOSE').($e['purpose']??'unknown')."\n\n"
      .$S('ACTOR').($e['audience']??'unknown').' ('.$e['group'].' API)'."\n\n"
      .$S('AUTHENTICATION').$areq."\n\n"
      .$S('AUTHORIZATION').$az."\n\n"
      .$S('PRECONDITIONS')."See business_logic; auth token present where required.".$unreach."\n\n"
      .$S('REQUEST CONTRACT').$reqS."\n\n"
      .$S('VALIDATION').$valS."\n\n"
      .$S('BUSINESS LOGIC').$bizDetail."\n\n"
      .$S('DATABASE EFFECTS').$dbS."\n\n"
      .$S('SIDE EFFECTS').$sideS."\n\n"
      .$S('SUCCESS RESPONSES').$respS."\n\n"
      .$S('ERROR RESPONSES').$respS."\n\n"
      .$S('PREVIOUS BUSINESS STEP').($biz['prev']??'unknown — reason: no reliable consumer/workflow evidence found')."\n\n"
      .$S('NEXT BUSINESS STEP').($biz['next']??'unknown — reason: no reliable consumer/workflow evidence found')."\n\n"
      .$S('IMPLEMENTATION TRACE').$implS;
}
function rawUrl($path){
    $raw = '{{base_url}}'.$path;
    // every {param} -> {{param}} so Postman treats it as a variable; declared below
    $raw = preg_replace_callback('#\{([A-Za-z0-9_]+)\}#', function($m){
        $p = $m[1];
        $map = ['orderId'=>'order_id','itemId'=>'item_id','content_page'=>'content_page','static_page'=>'static_page'];
        return '{{'.($map[$p]??$p).'}}';
    }, $raw);
    return $raw;
}
$tree = ['General'=>[],'Admin'=>[],'System'=>[]];
$gmap = ['general'=>'General','admin'=>'Admin','system'=>'System'];
foreach (['general_api','admin_api','system_api'] as $k) {
    foreach (($spec[$k]['endpoints']??[]) as $e) {
        $G = $gmap[$e['group']] ?? 'General';
        $F = folder($e,$e['group']);
        $tree[$G][$F][] = $e;
    }
}
foreach ($tree as $g=>&$f) ksort($f);
unset($f);
$items = [];
foreach (['General','Admin','System'] as $G) {
    $subs = [];
    foreach ($tree[$G] as $F=>$list) {
        if (!$list) continue;
        $reqs = [];
        foreach ($list as $e) {
            $method = $e['method']; $raw = rawUrl($e['path']);
            $q = [];
            $isMutation = in_array($method,['POST','PUT','PATCH']);
            $body = null;
            if ($isMutation && isset($e['request']['body_contract']['fields'])) {
                $obj = [];
                foreach ($e['request']['body_contract']['fields'] as $f) {
                    $n = $f['name']??null; if(!$n) continue;
                    $r = $f['rules']??'';
                    if (stripos($r,'required')===false) continue; // only required proven fields
                    if ($n==='email') $obj[$n]='customer@example.com';
                    elseif ($n==='password') $obj[$n]='Secret123';
                    elseif ($n==='phone_number'||$n==='user_phone') $obj[$n]='96599123456';
                    elseif ($n==='first_name') $obj[$n]='John';
                    elseif ($n==='last_name') $obj[$n]='Doe';
                    elseif ($n==='policy') $obj[$n]='1';
                    elseif ($n==='name') $obj[$n]='John Doe';
                    elseif ($n==='code') $obj[$n]='SAVE10';
                    else $obj[$n]='string';
                }
                if ($obj) $body = ['mode'=>'raw','raw'=>json_encode($obj,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),'options'=>['raw'=>['language'=>'json']]];
            }
            $headers = [['key'=>'Accept','value'=>'application/json']];
            $needAuth = is_array($e['authentication'])?($e['authentication']['required']??false):false;
            if ($needAuth) {
                $tok = ($e['group']==='admin')?'{{admin_token}}':'{{access_token}}';
                if (stripos($e['path'],'signed')!==false||strpos($e['path'],'{uuid}')!==false) {}
                else $headers[]=['key'=>'Authorization','value'=>'Bearer '.$tok];
            }
            $req = ['method'=>$method,'header'=>$headers,'url'=>['raw'=>$raw],'description'=>desc($e)];
            if ($body) $req['body']=$body;
            // chaining only where proven
            $ev = [];
            if ($e['id']==='post /api/v1/token') $ev[]=['listen'=>'test','script'=>['exec'=>['const b=pm.response.json(); const t=b?.data?.token||b?.token; if(t){pm.collectionVariables.set("access_token",t);}']]];
            if ($e['id']==='post /api/v1/general/checkout') $ev[]=['listen'=>'test','script'=>['exec'=>['const b=pm.response.json(); const id=b?.data?.order_id; if(id){pm.collectionVariables.set("order_id",id);}']]];
            // saved responses: only reachable statuses from spec
            $resp = [];
            if (($e['status']??'')==='deep-traced') {
            foreach (($e['responses']??[]) as $r) {
                if (!isset($r['status']) || !is_numeric($r['status'])) continue;
                $resp[]=['name'=>$r['status'].' '.($r['desc']??$r['description']??''),'originalRequest'=>['method'=>$method,'header'=>$headers,'url'=>['raw'=>$raw]],'status'=> (string)$r['status'],'code'=>(int)$r['status'],'_postman_previewlanguage'=>'json','header'=>[['key'=>'Content-Type','value'=>'application/json']],'body'=>json_encode(['status'=>$r['status'],'message'=>substr($r['desc']??$r['description']??'',0,120),'success'=>((int)$r['status']<400)],JSON_UNESCAPED_SLASHES)];
                if (count($resp)>=6) break;
            }
            }
            $rname = $method.' '.$e['path'];
            if (isset($e['unreachable'])) $rname = '[UNREACHABLE double-v1] '.$rname;
            $item=['name'=>$rname,'request'=>$req,'response'=>$resp];
            if ($ev) $item['event']=$ev;
            $reqs[]=$item;
        }
        $subs[]=['name'=>$F,'item'=>$reqs];
    }
    $items[]=['name'=>$G,'item'=>$subs];
}
$vars = ['base_url'=>'http://localhost','access_token'=>'','admin_token'=>'','customer_token'=>'','order_id'=>'','orderId'=>'','product_id'=>'','id'=>'','slug'=>'','uuid'=>'','provider'=>'','address'=>'','item_id'=>'','itemId'=>'','order_number'=>'','content_page'=>'','static_page'=>''];
foreach (['general_api','admin_api','system_api'] as $k) { foreach (($spec[$k]['endpoints']??[]) as $e) {
    if (preg_match_all('#\{([A-Za-z0-9_]+)\}#', $e['path'], $m)) foreach ($m[1] as $p) {
        $map = ['orderId'=>'order_id','itemId'=>'item_id','content_page'=>'content_page','static_page'=>'static_page'];
        $vars[$map[$p]??$p] = $vars[$map[$p]??$p] ?? '';
    }
} }
$vlist = []; foreach ($vars as $kk=>$vv) $vlist[]=['key'=>$kk,'value'=>$vv];
$col=['info'=>['_postman_id'=>sprintf('%04x%04x-%04x-%04x-%04x-%04x%08x',mt_rand(0,65535),mt_rand(0,65535),mt_rand(0,65535),mt_rand(0,4095)|0x4000,mt_rand(0,16383)|0x8000,mt_rand(0,65535),mt_rand(0,4294967295)),
  'name'=>'API — Forensic Collection','description'=>'Generated from source-code forensic audit.','schema'=>'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
 'variable'=>$vlist,
 'auth'=>['type'=>'noauth'],'event'=>[],'item'=>$items];
file_put_contents(__DIR__.'/../postman-collection.json', json_encode($col, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$n=0; foreach($items as $g) foreach($g['item'] as $s) $n+=count($s['item']);
echo "postman requests: $n".PHP_EOL;
