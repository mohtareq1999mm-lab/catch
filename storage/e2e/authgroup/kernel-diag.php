<?php
// Diagnostic: run the /token and /register FormRequests through the real Http kernel IN-PROCESS,
// with a body/headers exactly like the observed external wire traffic. READ-ONLY for DB (bad-creds path).
declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$cases = [
    'token-badcreds' => [
        'uri' => 'http://127.0.0.1:8000/api/v1/token', 'method' => 'POST',
        'body' => ['email' => 'qa.kernel.diag1@gmail.com', 'password' => 'WrongPass!2026'],
    ],
    'token-missing' => [
        'uri' => 'http://127.0.0.1:8000/api/v1/token', 'method' => 'POST', 'body' => [],
    ],
    'register-full' => [
        'uri' => 'http://127.0.0.1:8000/api/v1/register', 'method' => 'POST',
        'body' => [
            'first_name' => 'Qa', 'last_name' => 'Kernel', 'email' => 'qa.kernel.diag-' . random_int(100000, 999999) . '@gmail.com',
            'phone_number' => '0155099' . random_int(100000, 999999), 'password' => 'QaPass!2026x',
            'password_confirmation' => 'QaPass!2026x', 'policy' => 'true',
        ],
    ],
];

foreach ($cases as $name => $c) {
    $parameters = []; // must NOT be placed here — body goes in raw content, mirrors wire JSON
    $request = Illuminate\Http\Request::create(
        $c['uri'], $c['method'], $parameters, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'ACCEPT' => 'application/json',
        ], json_encode($c['body'], JSON_UNESCAPED_SLASHES)
    );
    $resp = $kernel->handle($request);
    echo "=== $name status=" . $resp->getStatusCode() . " ct=" . $resp->headers->get('content-type') . PHP_EOL;
    echo substr($resp->getContent(), 0, 500) . PHP_EOL;
}
$kernel->terminate($request, $resp);
echo "DONE", PHP_EOL;
