<?php
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;

Storage::fake('private');
Storage::disk('private')->put('probe.mp4', str_repeat('A', 1000));
$res = Storage::disk('private')->response('probe.mp4', 'p.mp4');
echo 'class=' . get_class($res) . "\n";

$req = Request::create('http://localhost/x', 'GET');
$req->headers->set('Range', 'bytes=0-9');
$res->prepare($req);
echo 'after prepare status=' . $res->getStatusCode() . ' content-range=' . ($res->headers->get('Content-Range') ?? 'NONE') . "\n";
