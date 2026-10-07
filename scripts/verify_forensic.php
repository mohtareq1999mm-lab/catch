<?php
error_reporting(E_ERROR);
$pm = json_decode(file_get_contents(__DIR__.'/../postman-collection.json'), true);
$sp = json_decode(file_get_contents(__DIR__.'/../api-master-spec.json'), true);
$err = [];
if (json_last_error() !== JSON_ERROR_NONE) $err[] = 'json decode failed';
// P56 schema/root
if (($pm['info']['schema'] ?? '') !== 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json') $err[] = 'bad schema';
$rk = array_keys($pm); sort($rk);
if ($rk !== ['auth','event','info','item','variable']) $err[] = 'bad root keys: '.implode(',',$rk);
$bad = ['general_api','admin_api','business_flows'];
foreach ($bad as $b) if (array_key_exists($b, $pm)) $err[] = "custom root $b in postman";
// counts
$count = function($items) use (&$count) { $c=0; foreach($items as $i){ if(isset($i['item'])) $c+=$count($i['item']); elseif(isset($i['request'])) $c++; } return $c; };
$n = $count($pm['item']);
$ns = count($sp['general_api']['endpoints'])+count($sp['admin_api']['endpoints'])+count($sp['system_api']['endpoints']);
if ($n !== 541) $err[] = "postman count $n != 541";
if ($ns !== 541) $err[] = "spec count $ns != 541";
if ($n !== $ns) $err[] = "mismatch pm $n vs spec $ns";
// folders non-empty + description sections
$need = ['BUSINESS PURPOSE','ACTOR','AUTHENTICATION','AUTHORIZATION','PRECONDITIONS','REQUEST CONTRACT','VALIDATION','BUSINESS LOGIC','DATABASE EFFECTS','SIDE EFFECTS','SUCCESS RESPONSES','ERROR RESPONSES','PREVIOUS BUSINESS STEP','NEXT BUSINESS STEP','IMPLEMENTATION TRACE'];
$check = function($items) use (&$check,&$err,$need) {
    foreach($items as $i){
        if(isset($i['item'])){ if(!count($i['item'])) $err[]='empty folder '.$i['name']; $check($i['item']); }
        elseif(isset($i['request'])){
            $d = $i['request']['description'] ?? '';
            foreach($need as $s){ if(strpos($d,$s)===false){ $err[]='missing section '.$s.' in '.$i['name']; break; } }
        }
    }
};
$check($pm['item']);
// variables
$vk = array_column($pm['variable'],'key');
foreach (['base_url','access_token','admin_token','customer_token'] as $v) if(!in_array($v,$vk)) $err[]="missing var $v";
// URL honesty: no single-brace params, all {{vars}} declared, no {{id_id}}, duplicates, unreachable flagged
$seen = [];
$walk = function($items) use (&$walk,&$err,$vk,&$seen) {
    foreach($items as $i){
        if(isset($i['item'])) $walk($i['item']);
        elseif(isset($i['request'])){
            $raw = is_array($i['request']['url']) ? ($i['request']['url']['raw']??'') : '';
            if (preg_match('#(?<!\{)\{[A-Za-z0-9_]+\}(?!\})#', $raw)) $err[]='single-brace param in '.$i['name'].': '.$raw;
            if (strpos($raw,'{{id_id}}')!==false) $err[]='bad var id_id in '.$i['name'];
            preg_match_all('#\{\{([A-Za-z0-9_]+)\}\}#', $raw, $m);
            foreach (($m[1]??[]) as $v) if(!in_array($v,['base_url']) && !in_array($v,$vk)) $err[]="undeclared var {{$v}} in ".$i['name'];
            if (strpos($raw,'/api/v1/v1/')!==false && stripos($i['name'],'UNREACHABLE')===false) $err[]='double-v1 not flagged: '.$i['name'];
            $k = $i['name'];
            if (isset($seen[$k])) $err[]='duplicate name: '.$k; $seen[$k]=1;
        }
    }
};
$walk($pm['item']);
$invC = count(json_decode(file_get_contents(__DIR__.'/../storage/route-inventory.json'), true));
if ($n !== $invC) $err[] = "drift: pm $n vs inventory $invC";
echo "pm=$n spec=$ns\n";
if ($err) { echo "FAIL\n".implode("\n", array_slice($err,0,30))."\n"; exit(1); }
echo "PASS\n";
