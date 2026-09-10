<?php

declare(strict_types=1);

namespace Tests\Stubs;

use Pusher\Pusher;

/**
 * A real Pusher SDK instance whose trigger() calls always throw, used to
 * prove that broadcast failures never fail the underlying file operation.
 */
class ThrowingPusher extends Pusher
{
    public function __construct()
    {
        parent::__construct('throwing-key', 'throwing-secret', 'throwing-app-id', [
            'cluster' => 'mt1',
            'useTLS' => false,
        ]);
    }

    public function trigger($channels, string $event, $data, array $params = [], bool $already_encoded = false): object
    {
        throw new \RuntimeException('Simulated Pusher outage while triggering ' . $event);
    }
}
