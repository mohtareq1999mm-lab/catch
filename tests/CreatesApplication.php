<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        // DB-005 quarantine: refuse to run ANY test against the shared `catch`
        // database unless explicitly allowed. phpunit.xml defaults DB_DATABASE
        // to catch, so a bare `php artisan test` — including the 241
        // RefreshDatabase suites that run migrate:fresh — would wipe shared
        // fixtures (proven twice by incident). Use a process-local isolated
        // database instead, e.g. $env:DB_DATABASE='catch_verify' (PowerShell)
        // or DB_DATABASE=catch_verify php artisan test. Only for a deliberate
        // supervised run set ALLOW_CATCH_TESTS=true.
        $database = (string) config('database.connections.mysql.database', '');
        $host = (string) config('database.connections.mysql.host', '');
        if ($database === 'catch' && $host === '127.0.0.1' && getenv('ALLOW_CATCH_TESTS') !== 'true') {
            fwrite(STDERR, "\nREFUSED: refusing to run tests against shared database `catch` on 127.0.0.1.\n"
                . "Use a process-local isolated database instead, e.g. \$env:DB_DATABASE='catch_verify' (PowerShell).\n"
                . "Only for a deliberate supervised run set ALLOW_CATCH_TESTS=true.\n\n");
            exit(1);
        }

        return $app;
    }
}
