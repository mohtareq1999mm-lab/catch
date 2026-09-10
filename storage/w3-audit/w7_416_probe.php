<?php
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

echo 'symfony-httpfoundation file: ' . (new \ReflectionClass(BinaryFileResponse::class))->getFileName() . "\n";
$file = tempnam(sys_get_temp_dir(), 'bfr');
file_put_contents($file, str_repeat('B', 1000));
$req = Request::create('http://localhost/x', 'GET');
$req->headers->set('Range', 'bytes=99999999-');
$b = new BinaryFileResponse($file, 200, [], false);
try {
    $b->prepare($req);
    echo 'status=' . $b->getStatusCode() . ' CR=' . var_export($b->headers->get('Content-Range'), true) . "\n";
} catch (\Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . ' code=' . $e->getStatusCode() . "\n";
}
