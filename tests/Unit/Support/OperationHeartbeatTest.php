<?php

namespace Tests\Unit\Support;

use App\Support\OperationHeartbeat;
use Tests\TestCase;

class OperationHeartbeatTest extends TestCase
{
    private int $operationId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operationId = random_int(100000, 999999);
    }

    protected function tearDown(): void
    {
        $heartbeat = OperationHeartbeat::for($this->operationId);
        $heartbeat->remove('progress');
        $heartbeat->remove('cancel');
        $heartbeat->remove('ids');

        parent::tearDown();
    }

    public function test_write_read_round_trip(): void
    {
        $heartbeat = OperationHeartbeat::for($this->operationId);

        $heartbeat->write('progress', ['processed_rows' => 5, 'progress' => 12.5]);

        $this->assertSame(
            ['processed_rows' => 5, 'progress' => 12.5],
            $heartbeat->read('progress')
        );
        $this->assertTrue($heartbeat->exists('progress'));
    }

    public function test_read_missing_signal_returns_null(): void
    {
        $this->assertNull(OperationHeartbeat::for($this->operationId)->read('progress'));
    }

    public function test_remove_deletes_the_signal(): void
    {
        $heartbeat = OperationHeartbeat::for($this->operationId);

        $heartbeat->write('ids', ['ids' => [1, 2, 3]]);
        $heartbeat->remove('ids');

        $this->assertFalse($heartbeat->exists('ids'));
        $this->assertNull($heartbeat->read('ids'));
    }

    public function test_cancel_requested_follows_the_cancel_signal(): void
    {
        $heartbeat = OperationHeartbeat::for($this->operationId);

        $this->assertFalse($heartbeat->cancelRequested());

        $heartbeat->write('cancel', ['cancelled_at' => now()->toIso8601String()]);

        $this->assertTrue($heartbeat->cancelRequested());

        $heartbeat->remove('cancel');

        $this->assertFalse($heartbeat->cancelRequested());
    }

    public function test_operations_do_not_share_signals(): void
    {
        OperationHeartbeat::for($this->operationId)->write('progress', ['processed_rows' => 1]);

        $this->assertNull(OperationHeartbeat::for($this->operationId + 1)->read('progress'));
    }
}
